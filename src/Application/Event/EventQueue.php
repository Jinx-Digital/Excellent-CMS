<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * Where runs of events in queue mode wait for the worker (`./yii queue:run events`, e.g. by cron
 * every minute). The only place the CMS knows the queue implementation.
 */
interface EventQueue
{
  public function push(string $runId): void;
}
