<?php

declare(strict_types=1);

return [
  // Demo/test data for `./yii fixtures:load <set>` (classes from fixtures/, autoload-dev only)
  'fixtures' => [
    'sets' => [
      'dev' => [\Fixtures\DemoFixture::class],
      'test' => [\Fixtures\TestFixture::class],
    ],
  ],
  'yiisoft/yii-console' => [
    'commands' => require __DIR__.'/commands.php',
  ],
  // Our migrations and the ones of yiisoft/rbac-db (roles, permissions) and yiisoft/queue-db (queue)
  'yiisoft/db-migration' => [
    'sourceNamespaces' => ['App\\Migration'],
    'sourcePaths' => [
      dirname(__DIR__, 2).'/vendor/yiisoft/rbac-db/migrations/items',
      dirname(__DIR__, 2).'/vendor/yiisoft/rbac-db/migrations/assignments',
      dirname(__DIR__, 2).'/vendor/yiisoft/queue-db/migrations',
    ],
  ],
];
