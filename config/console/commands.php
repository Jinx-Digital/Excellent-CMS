<?php

declare(strict_types=1);

use App\Console\Command\CleanupCommand;
use App\Console\Command\CreateAdminCommand;
use App\Console\Command\DocsSyncCommand;
use App\Console\Command\FixturesLoadCommand;
use App\Console\Command\PluginsSyncCommand;
use App\Console\Command\ScheduleRunCommand;
use App\Console\Command\SearchRebuildCommand;

return [
  'user:create-admin' => CreateAdminCommand::class,
  // Cron, e.g. hourly: expired OAuth tokens, abandoned imports, old rate limit counters
  'cleanup' => CleanupCommand::class,
  // Cron, every minute: scheduled publishing and unpublishing
  'schedule:run' => ScheduleRunCommand::class,
  // After deploying the search index, or to rebuild it: every project, --project=…, --entity=…
  'search:rebuild' => SearchRebuildCommand::class,
  // After deploying plugin updates: new blocks in every project; --templates: the plugins' templates again
  'plugins:sync' => PluginsSyncCommand::class,
  // After every update: the documentation (docs/*.md) as pages of a project (default: docs)
  'docs:sync' => DocsSyncCommand::class,
  // Demo/test data from fixtures/, dev/test only
  'fixtures:load' => FixturesLoadCommand::class,
];
