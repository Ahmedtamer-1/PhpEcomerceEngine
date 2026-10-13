<?php
require_once __DIR__ . '/config.php';

/**
 * Returns a singleton PDO connection.
 * Usage: $pdo = db();
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        // Talk to MySQL in the store's time zone (config.php sets Africa/Cairo).
        // Otherwise MySQL uses its server clock (typically UTC), so NOW() and
        // TIMESTAMP reads disagreed with PHP's date() by 2–3 hours: "today" on
        // the dashboard started at 2–3am and late orders landed on the next day.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (PDOException $e) {
        if (ENV === 'development') {
            die('<pre style="color:red">DB Error: ' . $e->getMessage() . '</pre>');
        }
        die('A database error occurred. Please try again later.');
    }

    return $pdo;
}
