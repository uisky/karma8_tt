<?php

define("DATETIME_FORMAT", "Y-m-d H:i:s");
define("QUEUE_MAIL", "mailer:queue:mail");
define("QUEUE_CHECK", "mailer:queue:check");

function get_db(): PDO
{
    $config = include dirname(__FILE__) . "/config.php";

    if (!isset($GLOBALS["_db"])) {
        $dbh = new PDO("mysql:host={$config["db_host"]};dbname={$config["db_name"]}", $config["db_user"], $config["db_password"]);
        $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dbh->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $dbh->exec("SET time_zone = '+00:00'");    
        $GLOBALS["_db"] = $dbh;
    }

    return $GLOBALS["_db"];
}

function get_redis(): Predis\Client
{
    $config = include dirname(__FILE__) . "/config.php";

    if (!isset($GLOBALS["_redis"])) {
        $GLOBALS["_redis"] = new Predis\Client([
            "scheme" => $config["redis_scheme"],
            "host" => $config["redis_host"],
            "port" => $config["redis_port"]
        ]);
    }

    return $GLOBALS["_redis"];
}

function enqueue_task(string $queue, string $action, mixed $payload, $expires=null): bool
{
    $redis = get_redis();

    $redis->rpush($queue, json_encode([
        "action" => $action,
        "created" => time(),
        "expires" => $expires,
        "payload" => $payload,
    ]));

    return true;
}

/**
 * Преобразовывает строку с человеческой записью времени в секунды:
 *   "2" => 2
 *   "2m" => 2 * 60 = 120
 *   "2h" => 2 * 60 * 60 = 7200
 */
function human_to_sec(string $str): int
{
    $str = strtolower($str);
    if ($str[-1] === 'h')
    {
        return substr($str, 0, -1) * 60 * 60;
    }
    if ($str[-1] === 'm')
    {
        return substr($str, 0, -1) * 60;
    }
    return (int) $str;
}

/**
 * Возвращает строку вида `HH:MM:SS.ddd` для количества секунд `$t`
 */
function sec_to_str(float $t): string
{
    $sign = $t < 0 ? '-' : '';
    $t = abs($t);
    return sprintf("%s%02d:%02d:%02d.%03d", $sign, intval($t / 3600), intval($t / 60) % 60, $t % 60, ($t - intval($t)) * 1000);
}

/**
 * Возвращает true с вероятностью $p %.
 */
function probable(int $p)
{
    return mt_rand(0, 99) < $p;
}

/**
 * Возвращает строковое представление юзера
 */
function user_repr($user) {
    $flags = [];
    foreach (["confirmed", "valid", "checked"] as $k)
    {
        if ($user[$k]) $flags[] = $k;
    }
    return sprintf(
        "\033[1;35m#%7d\e[0m \033[0;32m%-16s\e[0m: expires \033[1;34m%s\e[0m (%s) ", 
        $user["id"],
        $user["username"],
        date(DATETIME_FORMAT, $user["validts"]),
        join(", ", $flags)
    );
}
