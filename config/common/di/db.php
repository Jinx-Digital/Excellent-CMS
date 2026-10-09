<?php

declare(strict_types=1);

use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql\Connection;
use Yiisoft\Db\Mysql\Driver;

return [
  ConnectionInterface::class => [
    'class' => Connection::class,
    '__construct()' => [
      'driver' => new Driver(
        sprintf(
          'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
          $_ENV['DB_HOST'] ?? '127.0.0.1',
          $_ENV['DB_PORT'] ?? '8889',
          $_ENV['DB_NAME'] ?? 'excellent_cms'
        ),
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASSWORD'] ?? 'root'
      ),
    ],
  ],
];
