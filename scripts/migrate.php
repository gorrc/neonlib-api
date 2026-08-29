<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';
$database = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]
);
$requestedMigration = $argv[1] ?? null;
$files = $requestedMigration
    ? [dirname(__DIR__) . '/database/' . basename($requestedMigration)]
    : (glob(dirname(__DIR__) . '/database/*.sql') ?: []);
sort($files);

foreach ($files as $file) {
    if (!is_file($file)) {
        throw new RuntimeException("Migration not found: {$file}");
    }
    echo 'Running ' . basename($file) . PHP_EOL;
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Unable to read {$file}");
    }
    $database->exec($sql);
}

echo "Migrations complete." . PHP_EOL;
