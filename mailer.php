#!/usr/bin/php
<?php
require_once dirname(__FILE__) . "/vendor/autoload.php";
require_once dirname(__FILE__) . "/external.php";
require_once dirname(__FILE__) . "/util.php";
require_once dirname(__FILE__) . "/mailer_actions.php";
$config = include dirname(__FILE__) . "/config.php";
if ($config === false) die("Создайте config.php, см. README.md\n");


function usage(): void
{
    echo <<<TTT
    USAGE: {$_SERVER["argv"][0]}
    
    Менеджер очереди отправки сообщений.

    OPTIONS:
    
        --budget-daily=N   Бюджет на расходы на check_email() в день.
        --quick            Не эмулировать задержку send_email() и check_email()
        --clear            Очистить очереди перед началом работы
        --quit-on-empty    Выйти, если очереди пусты.
        --help, -h         Показать эту справку


    TTT;
}

/**
 * Парсит командную строку, возвращает массив со всеми аргументами и опциями
 */
function get_options(): array
{
    // Defaults
    $options = [
        "budget-daily" => 0,
        "quick" => false,
        "clear" => false,
        "quit-on-empty" => false,
        "help" => false,
    ];
    $optionAliases = ["h" => "help"];
    $opts = getopt("h", ["budget-daily:", "quick", "clear", "quit-on-empty", "help"], $rest_index);

    foreach ($opts as $k => $v)
    {
        $k = $optionAliases[$k] ?? $k;
        $v = $v === false ? true : $v;

        if ($k === "budget-daily")
        {
            $options[$k] = intval($v);
        }
        else
        {
            $options[$k] = $v;
        }
    }

    return $options;
}

$options = get_options();

if ($options["help"])
{
    usage();
    exit();
}

$redis = get_redis();

print("Служба рассылки запущена\n");

// Очистка очереди по запросу в командной строке
if ($options["clear"])
{
    printf("Очищаем очереди\n");
    $redis->del(QUEUE_MAIL);
    $redis->del(QUEUE_CHECK);
}

// Дневной бюджет
// @todo: если уже был израсходован какой-то бюджет за сегодняшний день и сервис перезапускается с
// новым --budget-daily, пересчитывать KEY budget-today.
if ($options["budget-daily"]) {
    $redis->set("mailer:budget-daily", intval($options["budget-daily"]));
    $redis->set("mailer:budget-today", intval($options["budget-daily"]));
}

$run = true;
$said = false;
while ($run) {
    // Забираем сообщения из очереди MAIL, а если она пуста, то из CHECK.
    $msg = $redis->lpop(QUEUE_MAIL) ?? $msg = $redis->lpop(QUEUE_CHECK);

    // Если обе очереди пусты, то один раз сообщаем об этом и спим секунду.
    if (!$msg) {
        if (!$said)
        {
            printf("Очреди пусты\n");
            $said = true;
            if ($options["quit-on-empty"]) exit();
        }
        sleep(1);
        continue;
    }
    $said = false;

    $task = json_decode($msg, true);

    // Отбрасываем устаревшие задачи
    if ($task["expires"] && $task["expires"] < time())
    {
        printf("NOTICE: Задача \"%s\" больше не актуальна\n", $task["action"]);
        continue;
    }

    // Выполняем задачу
    $executor = "action_" . $task["action"];
    if (function_exists($executor))
    {
        try
        {
            call_user_func($executor, $task["payload"], $task, $options);
        }
        catch (Exception $e)
        {
            printf(
                "ERROR! Во время выполнения действия \"%s\" приключилось исключение %s\nPayload: %s\n", 
                $task["action"], $e->getMessage(), print_r($payload, true)
            );
        }
    }
    else
    {
        printf("WARNING! Действия \"%s\" не существует\n", $task["action"]);
    }
}

