<?php

declare(strict_types=1);

use App\Application\Import\ImportService;
use App\Application\Media\MediaService;
use App\Application\Media\MediaUrlSigner;
use App\Application\Content\PreviewService;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Rbac\Db\AssignmentsStorage;
use Yiisoft\Rbac\Db\ItemsStorage;
use Yiisoft\Rbac\Manager;
use Yiisoft\Rbac\ManagerInterface;
use App\Infrastructure\Media\ImageVariants;
use App\Infrastructure\Media\MediaStorages;
use App\Infrastructure\Media\StorageDefinitions;
use App\Application\Service\SecretBox;
use App\Repository\RevisionRepository;
use App\Infrastructure\Webhook\HttpWebhookSender;
use App\Infrastructure\Webhook\WebhookSender;
use App\Application\Service\AccountService;
use App\Application\Service\JwtService;
use App\Application\Service\OAuthService;
use App\Application\Service\SettingsService;
use App\Infrastructure\Mail\Mailer;

/** @var array $params */

return [
  JwtService::class => [
    '__construct()' => [
      'secret' => $_ENV['JWT_SECRET'] ?? '',
      'ttl' => $params['application']['jwt_ttl'],
    ],
  ],
  OAuthService::class => [
    '__construct()' => [
      'tokenTtl' => $params['application']['oauth_token_ttl'],
    ],
  ],
  SettingsService::class => [
    '__construct()' => [
      'rateLimitDefaults' => $params['application']['rate_limit'],
    ],
  ],
  ImportService::class => [
    '__construct()' => [
      'storagePath' => dirname(__DIR__, 3).'/runtime/imports',
      'maxRows' => $params['application']['import_max_rows'],
    ],
  ],
  // Templates of blocks: compiled once into runtime/twig (by their content)
  \App\Application\Content\BlockTemplates::class => [
    '__construct()' => [
      'cachePath' => dirname(__DIR__, 3).'/runtime/twig',
    ],
  ],
  MediaStorages::class => static fn(StorageDefinitions $definitions): MediaStorages => new MediaStorages(
    $params['application']['media']['local_path'],
    $params['application']['media']['url'],
    dirname(__DIR__, 3),
    $definitions,
  ),
  // Plugins: folders in plugins/ (PLUGINS_DIR), uploaded as ZIP in the admin app - PLUGINS_ENABLED=false turns them off
  \App\Plugin\PluginManager::class => [
    '__construct()' => [
      'directory' => (static fn(string $dir): string => str_starts_with($dir, '/') ? $dir : dirname(__DIR__, 3).'/'.$dir)($_ENV['PLUGINS_DIR'] ?? 'plugins'),
      'enabled' => !in_array(strtolower((string)($_ENV['PLUGINS_ENABLED'] ?? 'true')), ['0', 'false', 'off', 'no'], true),
      'secret' => $_ENV['JWT_SECRET'] ?? '',
    ],
  ],
  SecretBox::class => [
    '__construct()' => ['key' => $_ENV['APP_ENCRYPTION_KEY'] ?? null],
  ],
  AccountService::class => [
    '__construct()' => [
      'appUrl' => $params['application']['app_url'],
      'appName' => $params['application']['name'],
    ],
  ],
  Mailer::class => [
    '__construct()' => [
      'dsn' => $params['application']['mail']['dsn'],
      'from' => $params['application']['mail']['from'],
      'fromName' => $params['application']['mail']['from_name'],
      'logFile' => dirname(__DIR__, 3).'/'.$params['application']['mail']['log_file'],
    ],
  ],
  WebhookSender::class => HttpWebhookSender::class,
  RevisionRepository::class => [
    '__construct()' => [
      'limit' => $params['application']['revision_limit'],
    ],
  ],
  // References to .env variables in settings ($NAME), see EnvVariables
  \App\Application\Service\EnvVariables::class => [
    '__construct()' => [
      'envFile' => dirname(__DIR__, 3).'/.env',
    ],
  ],
  // Roles and permissions (see AccessControl): users and API clients also get permissions of their own
  ManagerInterface::class => static fn(ConnectionInterface $db): ManagerInterface => new Manager(
    new ItemsStorage($db),
    new AssignmentsStorage($db),
    enableDirectPermissions: true,
  ),
  MediaUrlSigner::class => [
    '__construct()' => [
      'secret' => $_ENV['JWT_SECRET'] ?? '',
      'ttl' => $params['application']['media']['signed_ttl'],
    ],
  ],
  PreviewService::class => [
    '__construct()' => [
      'secret' => $_ENV['JWT_SECRET'] ?? '',
      // Lifetime of a preview link (seconds)
      'ttl' => max(60, (int)($_ENV['PREVIEW_TTL'] ?? 3600)),
    ],
  ],
  ImageVariants::class => [
    '__construct()' => [
      'directory' => dirname(__DIR__, 3).'/runtime/media-variants',
      'maxSize' => $params['application']['media']['transform_max_size'],
    ],
  ],
  MediaService::class => [
    '__construct()' => [
      'maxSize' => $params['application']['media']['max_size'],
    ],
  ],
];
