<?php
/**
 * Заглушки внешних функций send_email() и check_email()
 */

 require_once dirname(__FILE__) . "/util.php";

function send_email(string $from, string $to, string $text, int $max_delay=10): void
 {
    $dbh = get_db();
    $text = strtr($text, "\r\n", "");
    $delay = mt_rand(0, $max_delay);
    printf(
        "\e[2m  SYS> send_email(\"%s\", \"%s\", \"%s\") [delay=%d]\e[0m\n", 
        $from, $to, mb_strlen($text) > 30 ? mb_substr($text, 0, 30) . "..." : $text, $delay
    );
    $dbh->prepare("INSERT INTO notifier_log (rcpt, body, delay) VALUES (?, ?, ?)")->execute([$to, $text, $delay]);
    sleep($delay);
 }

 function check_email(string $email, int $max_delay=60): bool
 {
    $delay = mt_rand(0, $max_delay);
    $result = (bool) mt_rand(0, 1);
    printf(
        "\e[2m  SYS> check_email(\"%s\") = %d [delay=%d]\e[0m\n", 
        $email, $result, $delay
    );
    sleep($delay);
    return $result;
 }
 