<?php

declare(strict_types=1);

// Drops and recreates the given database. Usage: php bin/reset-db.php [dbname]

require dirname(__DIR__).'/vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
  \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '8889';
$dbname = $argv[1] ?? ($_ENV['DB_NAME'] ?? 'excellent_cms');

if (!preg_match('/^[a-z0-9_]+$/i', $dbname)) {
  fwrite(STDERR, "Invalid database name.\n");
  exit(1);
}

try {
  $pdo = new PDO("mysql:host={$host};port={$port}", $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASSWORD'] ?? 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
  // Recreated with the server's default utf8mb4 collation, which matches the connection
  // collation - yiisoft/rbac-db's MySQL tree queries fail on a collation mismatch.
  $pdo->exec("DROP DATABASE IF EXISTS `{$dbname}`");
  $pdo->exec("CREATE DATABASE `{$dbname}` CHARACTER SET utf8mb4");
  echo "Database {$dbname} recreated.\n";
} catch (Throwable $e) {
  fwrite(STDERR, 'Error resetting database: '.$e->getMessage()."\n");
  exit(1);
}
