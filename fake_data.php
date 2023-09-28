#!/usr/bin/php
<?php
require_once dirname(__FILE__) . "/vendor/autoload.php";
require_once dirname(__FILE__) . "/util.php";
$config = include dirname(__FILE__) . "/config.php";
if ($config === false) die("Создайте config.php, см. README.md\n");

function usage(): void
{
    echo <<<TTT
    USAGE: {$_SERVER["argv"][0]} [OPTIONS] <START_DATE> <END_DATE> <N>
    
    Добавляет в таблицу `users` <N> записей со значением `validts`,
    случайно распределёнными между <START_DATE> и <END_DATE>, сохраняя
    вероятности, указанные в описании задачи.

    <START_DATE> и <END_DATE> нужно указывать в формате ISO-8601. Можно
    использовать значение "now".

    В параметре <N> можно указать выражение, типа "5000000/12".

    OPTIONS:
    
      --create, -c     Уничтожить и создать таблицы заново
      --truncate, -t   Очистить таблицы
      --help, -h       Показать эту справку

    EXAMPLES:

      Создать таблицы и заполнить их 5 миллионами записей для 2023 года:

      $ ./fake_data.php -c 2023-01-01 "2023-12-31 23:59:59" 5000000

      Добавить ещё 3000 записей для сентября 2023 года:

      $ ./fake_data.php -t 2023-09-01 "2023-09-30 23:59:59" 3000


    TTT;
}

/**
 * Парсит командную строку
 */
function get_options(): array
{
    $options = [
        "create" => false,
        "truncate" => false,
        "help" => false,
    ];
    $optionAliases = ["c" => "create", "t" => "truncate", "h" => "help"];
    $opts = getopt("cth", ["create", "truncate", "help"], $rest_index);

    foreach ($opts as $k => $v)
    {
        $k = $optionAliases[$k] ?? $k;
        $v = $v === false ? true : $v;

        $options[$k] = $v;
    }

    $pos_args = array_slice($_SERVER["argv"], $rest_index);
    
    if ($options["help"] || count($pos_args) != 3)
    {
        usage();
        exit();
    }

    foreach (["start", "finish"] as $i => $k)
    {
        try
        {
            $options[$k] = new DateTime($pos_args[$i]);
        }
        catch(Exception $e)
        {
            printf("Неверный формат времени \"%s\" в параметре <%s>: %s\n", $v, strtoupper($k), $e->getMessage());
            exit(1);
        }
    }

    if (preg_match('/^[\d\s\/\*+-]+$/', $pos_args[2]))
    {
        $options["n"] = (int) eval("return intval({$pos_args[2]});");
    }
    else
    {
        $options["n"] = (int) $pos_args[2];
    }

    return $options;
}

/**
 * Создаёт все таблицы
 */
function create_tables(): void
{
    printf("Создаём заново таблицы\n");
    
    $dbh = get_db();
    $r = $dbh->exec("
        DROP TABLE IF EXISTS users;
        CREATE TABLE users (
            id int unsigned not null auto_increment primary key,
            username varchar(16) not null,
            email varchar(256) not null,
            validts int not null,
            confirmed tinyint not null default 0,
            checked tinyint not null default 0,
            valid tinyint not null default 0
        );
        CREATE INDEX users_validts_idx ON users(validts) TYPE btree;

        DROP TABLE IF EXISTS notifications_done;
        CREATE TABLE notifications_done (
            user_id int unsigned not null,
            period int unsigned not null,
            validts int unsigned not null
        );
        CREATE UNIQUE INDEX notifications_done_pk ON notifications_done(user_id, period, validts);
        
        DROP TABLE IF EXISTS notifier_state;
        CREATE TABLE notifier_state (
            state text
        );

        DROP TABLE IF EXISTS notifier_log;
        CREATE TABLE notifier_log (
            created timestamp not null default current_timestamp,
            rcpt varchar(256) not null,
            delay int not null,
            body text not null
        );
    ");
}

/**
 * Очищает все таблицы
 */
function truncate_tables(): void
{
    printf("Очищаем таблицы\n");
    $dbh = get_db();
    $r = $dbh->exec("TRUNCATE TABLE users");
    $r = $dbh->exec("TRUNCATE TABLE notifications_done");
    $r = $dbh->exec("TRUNCATE TABLE notifier_state");
    $r = $dbh->exec("TRUNCATE TABLE notifier_log");
}

/**
 * Генерирует и возвращает случайный юзернейм длины от $minLen до $maxLen
 */
function gen_username(int $minLen=3, int $maxLen=16): string
{
    $alphabet = "abcdefghijklmnopqrstuvwxyz";
    $length = rand($minLen, $maxLen);
    $username = "";
    while ($length--)
    {
        $username .= $alphabet[rand(0, strlen($alphabet) - 1)];
    }
    return $username;
}

function insert_records(array $options): void
{
    $dbh = get_db();

    $sth = $dbh->prepare("
        INSERT INTO users 
            (username, email, validts, confirmed, checked, valid) 
        VALUES 
            (:username, :email, :validts, :confirmed, :checked, :valid)
    ");

    $dbh->beginTransaction();
    for ($i = 0; $i < $options["n"]; $i++)
    {
        if ($i % 10000 === 0)
        {
            printf("%d / %d строк добавлено...\r", $i, $options["n"]);
        }
        
        $username = gen_username();
        $params = [
            "username" => $username,
            "email" => $username . "@gmail.com",
            "confirmed" => probable(15) ? 1 : 0,
        ];
        
        // Подписку имеет 20% пользователей
        if (probable(20))
        {
            // Генерим случайную дату и время в текущем году
            $params["validts"] = mt_rand($options["start"]->getTimestamp(), $options["finish"]->getTimestamp());
        }
        else
        {
            $params["validts"] = 0;
        }
        
        // Допустим, 30% всех адресов прошли проверку, из них 50% оказались валидными
        $params["checked"] = probable(30) ? 1 : 0;
        $params["valid"] = $params["checked"] ? (int) probable(50) : 0;

        $sth->execute($params);
    }

    printf("%1\$d / %1\$d строк добавлено   \n", $options["n"]);
    $dbh->commit();
}


$options = get_options();

if ($options["create"])
{
    create_tables();
}

if ($options["truncate"])
{
    truncate_tables();
}

$t0 = microtime(true);
insert_records($options);
printf("Заняло %s\n", sec_to_str(microtime(true) - $t0));
