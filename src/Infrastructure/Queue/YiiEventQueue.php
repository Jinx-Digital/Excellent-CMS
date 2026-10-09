<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Event\EventQueue;
use Yiisoft\Queue\Message\GenericMessage;
use Yiisoft\Queue\Provider\QueueProducerProviderInterface;

/**
 * Runs of events in queue mode go to the yiisoft/queue queue "events" (table `queue`, worker:
 * `./yii queue:run events`). yiisoft/queue has no stable release yet - this class and
 * config/common/di/queue.php are the only places that know it.
 */
final class YiiEventQueue implements EventQueue
{
  public const QUEUE = 'events';
  public const MESSAGE = 'event-run';

  public function __construct(
    private QueueProducerProviderInterface $queues,
  ) {
  }

  public function push(string $runId): void
  {
    $this->queues->getProducer(self::QUEUE)->push(new GenericMessage(self::MESSAGE, ['run' => $runId]));
  }
}
