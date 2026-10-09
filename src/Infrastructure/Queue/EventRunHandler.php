<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Application\Event\EventRunner;
use Yiisoft\Queue\Message\Handler\HandlerInterface;
use Yiisoft\Queue\Message\MessageInterface;

/**
 * The worker's handler of "event-run" messages: executes the run (errors are kept in the run).
 */
final class EventRunHandler implements HandlerInterface
{
  public function __construct(
    private EventRunner $runner,
  ) {
  }

  public function handle(MessageInterface $message): void
  {
    $payload = $message->getPayload();
    if (is_array($payload) && isset($payload['run'])) {
      $this->runner->run((string)$payload['run']);
    }
  }
}
