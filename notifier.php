#!/usr/bin/php
<?php
declare(ticks = 1);
require_once dirname(__FILE__) . "/vendor/autoload.php";
require_once dirname(__FILE__) . "/util.php";
$config = include dirname(__FILE__) . "/config.php";
if ($config === false) die("Создайте config.php, см. README.md\n");

// Количество секунд, которое воспринимается человеком как допустимая погрешность понятия "день"
define("DAY_PRECISION", 86400 / 2);

function usage(): void
{
    echo <<<TTT
    USAGE: {$_SERVER["argv"][0]} <PERIOD> [<PERIOD> ...]
    
    Отправляет уведомления пользователям о заканчивающейся подписке за <PERIOD> суток.

    OPTIONS:
    
        --precision=N   Точность, с которой отправляются уведомления, в секундах. Можно
                        указать минуты или часы: "1h", "42m". Default=1h.
                        Указанная точность достигается, если скрипт запускать с этим интервалом.
        --fake-now=T    Указать текущее время в формате ISO-8601
        --release-lock  Очистить флаг того, что какой-то процесс работает и выйти.
                        Запускать с этим параметром можно, если уверены, что больше нет
                        работающих процессов, а кто-то из них завершился, не сняв lock.
        --ignore-state  Игнорировать информацию о предыдущем запуске
        --help, -h      Показать эту справку


    TTT;
}

/**
 * Парсит командную строку, возвращает массив со всеми аргументами и опциями
 */
function get_options(): array
{
    // Defaults
    $options = [
        "precision" => 3600,
        "now" => time(),
        "release-lock" => false,
        "ignore-state" => false,
        "help" => false,
    ];
    $optionAliases = ["h" => "help"];
    $opts = getopt("h", ["precision:", "fake-now:", "release-lock", "ignore-state", "help"], $rest_index);

    foreach ($opts as $k => $v)
    {
        $k = $optionAliases[$k] ?? $k;
        $v = $v === false ? true : $v;

        if ($k === "precision")
        {
            $options[$k] = human_to_sec($v);
        }
        elseif ($k === "fake-now")
        {
            try
            {
                $options["now"] = (new DateTime($v))->getTimestamp();
            }
            catch(Exception $e)
            {
                printf("Неверный формат времени \"%s\" в параметре --fake-now: %s\n", $v, $e->getMessage());
                exit(1);
            }
        }
        else
        {
            $options[$k] = $v;
        }
    }

    $pos_args = array_slice($_SERVER["argv"], $rest_index);
    $options["periods"] = array_map(function($x) { return (int) $x * 86400; }, $pos_args);
    $options["periods"] = array_filter($options["periods"], function ($x) { return $x > 0; });

    return $options;
}

/**
 * Загружает состояние приложения и возвращает его в виде ассоциативного массива
 */
function load_state(): array
{
    $dbh = get_db();
    $t = $dbh->query("SELECT state FROM notifier_state")->fetchColumn();
    return $t === false ? [] : json_decode($t, true);
}

/**
 * Сохраняет состояние приложения
 */
function save_state(array $state): void
{
    $dbh = get_db();
    $dbh->exec("TRUNCATE notifier_state");
    $dbh->prepare("INSERT INTO notifier_state (state) VALUES (?)")->execute([json_encode($state)]);
}

/**
 * Блокировка параллельного исполнения и его сброс.
 */
function handle_lock(array $options): void
{

    $release_lock = function (): void
    {
        $state = load_state();
        unset($state["busy"]);
        save_state($state);
    };

    $handle_sigterm = function(int $signo, mixed $siginfo) use ($release_lock): void
    {
        $release_lock();
        exit(1);
    };

    if ($options["release-lock"])
    {
        printf("Блокировка параллельного запуска снята\n");
        $release_lock();
        exit();
    }

    pcntl_signal(SIGINT, $handle_sigterm);
    pcntl_signal(SIGHUP, $handle_sigterm);
    pcntl_signal(SIGUSR1, $handle_sigterm);
    if (isset($state["busy"]))
    {
        printf("Скрипт уже запущен (%s). Дождитесь окончания его работы или убейте.\n", date(DATETIME_FORMAT . " e", $state["busy"]));
        exit(1);
    }
}

/**
 * Уведомляет пользователя $user о том, что у него через $period секунд истекает подписка
 */
function notify(array $user, int $period): void
{
    printf("Enqueue %s\n", user_repr($user));

    enqueue_task(QUEUE_MAIL, "notify", [
        "user" => $user,
        "period" => $period
    ], $user["validts"] - $period + DAY_PRECISION);
}

/**
 * Возвращает временной интервал validts, для которого сейчас нужно отправить уведомления
 * об окончании подписки за $period суток
 */
function get_validts_boundaries(array $state, array $options, int $period): array
{
    // Считаем временную вилку ($start, $stop) для тех, кого пора предупредить
    if (isset($state[$period]["lastts"]))
    {
        // Если в прошлый раз мы закончили обрабатывать юзеров, чья подписка закончится раньше, чем $period
        if ($state[$period]["lastts"] <= $options["now"] + $period - DAY_PRECISION)
        {
            printf(
                "ВНИМАНИЕ: Последний раз скрипт запускался слишком давно.\n" .
                "Уведомления по времени окончания подписки %s - %s не были отправлены.\n",
                date(DATETIME_FORMAT, $state[$period]["lastts"] + 1),
                date(DATETIME_FORMAT, $options["now"] + $period - DAY_PRECISION - 1)
            );
            $start = $options["now"] + $period - DAY_PRECISION;
        }
        else
        {
            $start = $state[$period]["lastts"];
        }
    }
    else
    {
        $start = $options["now"] + $period;
    }
    
    $finish = $options["now"] + $period + $options["precision"];

    return [$start, $finish];
}

/**
 * Возвращает юзеров c validts между $start и $stop, кому нужно отправить уведомления об
 * окончании подписки через $period.
 * В условиях монолита берёт их из базы, если юзерами ведает микросервис, то будет общаться
 * с ним.
 */
function get_expiring_users(int $start, int $finish, int $period): array
{
    $dbh = get_db();
    $sth = $dbh->prepare("
        SELECT users.* 
        FROM users 
        LEFT JOIN notifications_done ON
            notifications_done.user_id = users.id AND 
            notifications_done.period = :period AND
            notifications_done.validts = users.validts
        WHERE 
            users.validts BETWEEN :start AND :finish AND 
            notifications_done.user_id IS NULL AND
            NOT (confirmed = 0 AND valid = 0 AND checked = 1)
        ORDER BY users.validts
    ");
    $sth->execute(["start" => $start, "finish" => $finish, "period" => $period]);
    
    return $sth->fetchAll();
}


$options = get_options();

if (!$options["release-lock"] && ($options["help"] || $options["precision"] <= 0 || count($options["periods"]) === 0))
{
    usage();
    exit();
}

$dbh = get_db();

handle_lock($options);

$state = load_state();
if ($options["ignore-state"]) $state = [];

$state["busy"] = time();
save_state($state);

// Удаляем записи об уведомлениях для тех подписок, которые уже закончились: они нам точно не понадобятся.
$sth = $dbh->prepare("DELETE FROM notifications_done WHERE validts + period <= ?")->execute([$options["now"]]);

printf("Сейчас %s\n", date(DATETIME_FORMAT, $options["now"]));

// Обрабатываем каждый период отдельно
foreach($options["periods"] as $period)
{
    list($start, $finish) = get_validts_boundaries($state, $options, $period);
    printf(
        "Уведомляем пользователей с validts in (%s - %s) об окончании подписки через %d суток\n", 
        date(DATETIME_FORMAT, $start), date(DATETIME_FORMAT, $finish),
        $period / 86400
    );
    
    $t0 = microtime(true);
    $users = get_expiring_users($start, $finish, $period);

    $t1 = microtime(true);
    $sentSth = $dbh->prepare("INSERT INTO notifications_done (user_id, period, validts) VALUES (:user_id, :period, :validts)");
    foreach($users as $user)
    {
        notify($user, $period);
        $sentSth->execute(["user_id" => $user["id"], "period" => $period, "validts" => $user["validts"]]);
    }
    $tz = microtime(true);
    printf("DB: %s, отправка писем: %s, юзеров: %d\n\n", sec_to_str($t1 - $t0), sec_to_str($tz - $t1), count($users));
    
    $state[$period]["lastts"] = $finish;
}

unset($state["busy"]);
save_state($state);
