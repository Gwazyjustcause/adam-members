<?php

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=10006;dbname=local;charset=utf8mb4', 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}

$statement = $pdo->query('SELECT ID, user_login, user_email FROM wp_users ORDER BY ID LIMIT 10');

foreach ($statement as $row) {
    echo $row['ID'] . "\t" . $row['user_login'] . "\t" . $row['user_email'] . PHP_EOL;
}
