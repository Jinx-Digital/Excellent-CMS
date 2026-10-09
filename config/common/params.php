<?php

declare(strict_types=1);

$bool = static fn(string $key, bool $default): bool => isset($_ENV[$key]) ? (bool)filter_var($_ENV[$key], FILTER_VALIDATE_BOOL) : $default;

return [
  // Messages: English in the code, other languages in messages/ (see App\Shared\I18n)
  'yiisoft/translator' => [
    'locale' => 'en',
    'fallbackLocale' => 'en',
    'defaultCategory' => 'app',
  ],
  'application' => [
    'name' => 'Excellent CMS',
    // Login of CMS users (admin app)
    'jwt_ttl' => (int)($_ENV['JWT_TTL'] ?? 60 * 60 * 24 * 7),
    // Access tokens of OAuth clients (content API)
    'oauth_token_ttl' => (int)($_ENV['OAUTH_TOKEN_TTL'] ?? 3600),
    'import_max_rows' => (int)($_ENV['IMPORT_MAX_ROWS'] ?? 50000),
    // Address of the admin app for links in mails (empty: scheme and host of the request)
    'app_url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
    // Mails (password reset, new e-mail address) - no DSN: written to runtime/logs/mail.log
    'mail' => [
      'dsn' => $_ENV['MAILER_DSN'] ?? '',
      'from' => $_ENV['MAILER_FROM'] ?? 'noreply@localhost',
      'from_name' => $_ENV['MAILER_FROM_NAME'] ?? 'Excellent CMS',
      'log_file' => 'runtime/logs/mail.log',
    ],
    // Revisions of records: the newest this many per record are kept (0 = no revisions)
    'revision_limit' => max(0, (int)($_ENV['REVISION_LIMIT'] ?? 50)),
    // Uploads of media fields: the local folder storage/ and buckets, picked per project (see MediaStorages)
    'media' => [
      'max_size' => (int)($_ENV['MEDIA_MAX_SIZE'] ?? 20) * 1024 * 1024,
      // Lifetime of signed addresses of protected files in seconds (valid for 1-2 times this long)
      'signed_ttl' => max(60, (int)($_ENV['MEDIA_SIGNED_TTL'] ?? 86400)),
      // Largest width and height of transformed images (?w=…&h=…)
      'transform_max_size' => max(1, (int)($_ENV['MEDIA_TRANSFORM_MAX_SIZE'] ?? 4000)),
      // Folder of the built-in storage "local" (more storages: Administration › Storages)
      'local_path' => 'storage',
      // Where the CMS serves files (local, private buckets) - default /media
      'url' => $_ENV['MEDIA_URL'] ?? '',
    ],
    // Defaults until an admin saves the rate limit settings (table `setting`)
    'rate_limit' => [
      'enabled' => $bool('RATE_LIMIT_ENABLED', false),
      'requests' => (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 60),
      'window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
    ],
  ],

  'yiisoft/aliases' => [
    'aliases' => require __DIR__.'/aliases.php',
  ],
  // Runs of events in queue mode: the worker (./yii queue:run events) hands them to EventRunHandler
  'yiisoft/queue' => [
    'handlers' => [\App\Infrastructure\Queue\YiiEventQueue::MESSAGE => \App\Infrastructure\Queue\EventRunHandler::class],
  ],
];
