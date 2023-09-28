<?php

function action_notify(array $payload, array $task, array $options): void
{
    $user = $payload["user"];

    printf("NOTIFY %s\n", user_repr($user));
 
    // Если юзер не подтверждён, не валиден и не проверялся, то запланируем проверку его почты, если будет время
    if (!$user["confirmed"] && !$user["valid"] && !$user["checked"])
    {
        printf("  Отправляется на проверку\n");
        enqueue_task(QUEUE_CHECK, "check", $payload, $task["expires"]);
    }

    // Подтверждённым и валидным шлём письмо
    if ($user["confirmed"] || $user["valid"])
    {
        notify($user, $payload["period"], $options);
    }

    printf("\n");
}

function action_check(array $payload, array $task, array $options): void
{
    // global это плохо, но ООП запрещено, а без статических классов контекст приложения будет некрасиво реализован
    $dbh = get_db();
    $redis = get_redis();

    printf("CHECK %s\n", user_repr($payload["user"]));

    // Если предыдущий вызов check_email был не сегодня, сбрасываем mailer:budget-today в mailer:budget-daily
    // Всё это может привести к race condition в мультипроцессном окружении, для продакшена это
    // можно переписать на Lua.
    $today = date("Y-m-d");
    if ($redis->get('mailer:last-check-date') !== $today)
    {
        printf("  Новый день, новый бюджет\n");
        $redis->transaction(function($r) use ($today) {
            $r->set('mailer:last-check-date', $today);
            $r->copy('mailer:budget-daily', 'mailer:budget-today');
        });
    }
    
    // Проверяем бюджет на сегодня
    if ($redis->decr('mailer:budget-today') < 0) 
    {
        printf("  Не обрабатываем: вышел бюджет.\n\n");
        return;
    }

    $redis->set('mailer:last-check-date', $today);
    $valid = check_email($payload["user"]["email"], $options["quick"] ? 0 : 60);
    $dbh->prepare("UPDATE users SET checked = 1, valid = ? WHERE id = ?")->execute([(int) $valid, $payload["user"]["id"]]);
    if ($valid)
    {
        notify($payload["user"], $payload["period"], $options);
    }

    printf("\n");
    printf("\n");
}

function notify(array $user, int $period, array $options): void
{
    printf(
        "  Предупреждаем юзера %s об окончании подписки за %d дней (реально за %s, D=%s)\n",
        $user["email"], 
        $period / 86400, 
        sec_to_str($user["validts"] - time()), 
        sec_to_str($period - $user["validts"] + time())
    );
    send_email("no-reply@karma8.io", $user["email"], sprintf("Осталось %d дней!", $period / 86400), $options["quick"] ? 0 : 10);
}
