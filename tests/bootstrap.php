<?php

declare(strict_types=1);

// Tests always run against the separate test database (see `make test`).
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = getenv('DB_NAME') ?: 'excellent_cms_test';
putenv('DB_NAME='.$_ENV['DB_NAME']);

require dirname(__DIR__).'/src/bootstrap.php';

if (empty($_ENV['APP_ENCRYPTION_KEY'])) {
  $_ENV['APP_ENCRYPTION_KEY'] = base64_encode(str_repeat('k', 32));
}
if (empty($_ENV['JWT_SECRET'])) {
  $_ENV['JWT_SECRET'] = str_repeat('test-secret-', 4);
}
// Plugins of the tests never land in plugins/
$_ENV['PLUGINS_DIR'] = 'runtime/test-plugins';
$_ENV['PLUGINS_ENABLED'] = 'true';
// The rate limit is tested with clients that have their own limit
$_ENV['RATE_LIMIT_ENABLED'] = 'false';
