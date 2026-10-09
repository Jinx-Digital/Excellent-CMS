<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Schema\EntityDefinition;
use Psr\Container\ContainerInterface;

/**
 * What RecordRepository tells about changes of records. The EventDispatcher is taken from the
 * container on first use: it needs services that need RecordRepository themselves.
 */
final class EventHooks
{
  private ?EventDispatcher $dispatcher = null;

  public function __construct(
    private ContainerInterface $container,
  ) {
  }

  /**
   * @param array $row the record now (for "delete": before it is gone)
   * @param array|null $before the record before an update
   */
  public function record(EntityDefinition $entity, string $action, array $row, ?array $before = null): void
  {
    $this->dispatcher ??= $this->container->get(EventDispatcher::class);
    $this->dispatcher->record($entity, $action, $row, $before);
  }

  /**
   * A file of the media library or a variable changed (see EventDispatcher::recordData()).
   */
  public function data(string $source, string $projectId, string $action, string $id, array $data, ?array $old = null, ?string $target = null): void
  {
    $this->dispatcher ??= $this->container->get(EventDispatcher::class);
    $this->dispatcher->recordData($source, $projectId, $action, $id, $data, $old, $target);
  }
}
