<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Content\RecordPresenter;
use App\Domain\Schema\EntityDefinition;
use App\Repository\EventRepository;
use App\Shared\Id;

/**
 * Collects the records events react to during one request: the condition is checked when the
 * record is written (for "delete" before it is gone), with the record's data and its state
 * before. flush() turns them into runs - one run per event and request, whatever the number of
 * records (an import of 100 rows is one run with 100 records). Runs of events in queue mode are
 * pushed to the queue, the others are returned to be run right away (EventRunner).
 *
 * Events started by the steps of a run (chains) are one level deeper; at most MAX_DEPTH levels,
 * and an event never starts itself again within its chain.
 */
final class EventDispatcher
{
  public const ACTIONS = ['create', 'update', 'delete', 'publish', 'unpublish', 'restore'];
  /** What an event listens to: records of an entity, the media or the variables of the project */
  public const SOURCES = ['entity', 'media', 'variables'];
  /** Actions of media (upload, rename / keep, delete) and variables (added, changed, removed) */
  public const DATA_ACTIONS = ['create', 'update', 'delete'];
  public const MAX_DEPTH = 3;

  /** @var array<string, array{event: array, records: list<array>}> event id => what it collected */
  private array $pending = [];
  /** @var array<string, list<array>> entity id => active events */
  private array $events = [];
  /** Level and chain of the run whose steps are executed right now */
  private int $depth = 0;
  /** @var list<string> */
  private array $origin = [];

  public function __construct(
    private EventRepository $repository,
    private ConditionMatcher $conditions,
    private RecordPresenter $presenter,
    private EventQueue $queue,
    private ?\App\Application\Access\FieldAccess $fieldAccess = null,
  ) {
  }

  public function record(EntityDefinition $entity, string $action, array $row, ?array $before = null): void
  {
    if ($this->depth >= self::MAX_DEPTH) {
      return;
    }
    $events = array_filter(
      $this->events[$entity->id] ??= $this->repository->activeFor($entity->id),
      fn(array $event): bool => in_array($action, (array)json_decode((string)$event['actions'], true), true) && !in_array((string)$event['id'], $this->origin, true),
    );
    if ([] === $events) {
      return;
    }
    // Events see every field, whoever made the change
    $unrestricted = fn(callable $callback): mixed => null !== $this->fieldAccess ? $this->fieldAccess->unrestricted($callback) : $callback();
    $data = $unrestricted(fn(): array => $this->present($entity, $row));
    $old = null !== $before ? $unrestricted(fn(): array => $this->present($entity, $before)) : null;
    foreach ($events as $event) {
      $condition = null !== $event['condition'] ? json_decode((string)$event['condition'], true) : null;
      if (!$unrestricted(fn(): bool => $this->conditions->matches($entity, is_array($condition) ? $condition : null, (string)$row['id'], $data, $old))) {
        continue;
      }
      $this->pending[(string)$event['id']] ??= ['event' => $event, 'records' => []];
      $this->pending[(string)$event['id']]['records'][] = ['id' => (string)$row['id'], 'entity' => $entity->slug, 'action' => $action, 'data' => $data, 'old' => $old];
    }
  }

  /**
   * A file of the media library or a variable of the project changed.
   *
   * @param string $source media, variables or the event source of a plugin ("forms.submission")
   * @param string $id id of the file, name of the variable
   * @param array $data how it is now (for "delete": how it was), $old before an update
   * @param string|null $target e.g. the form: events limited to another target are left out
   */
  public function recordData(string $source, string $projectId, string $action, string $id, array $data, ?array $old = null, ?string $target = null): void
  {
    if ($this->depth >= self::MAX_DEPTH) {
      return;
    }
    $key = $source.':'.$projectId;
    $events = array_filter(
      $this->events[$key] ??= $this->repository->activeForSource($projectId, $source),
      fn(array $event): bool => in_array($action, (array)json_decode((string)$event['actions'], true), true) && !in_array((string)$event['id'], $this->origin, true)
        && (null === ($event['source_target'] ?? null) || '' === $event['source_target'] || $event['source_target'] === $target),
    );
    foreach ($events as $event) {
      $condition = null !== $event['condition'] ? json_decode((string)$event['condition'], true) : null;
      if (!$this->conditions->matchesData(is_array($condition) ? $condition : null, $data, $old)) {
        continue;
      }
      $this->pending[(string)$event['id']] ??= ['event' => $event, 'records' => []];
      $this->pending[(string)$event['id']]['records'][] = ['id' => $id, 'entity' => $source, 'action' => $action, 'data' => $data, 'old' => $old];
    }
  }

  public function hasPending(): bool
  {
    return [] !== $this->pending;
  }

  /**
   * Creates the runs of what was collected; queue mode goes to the queue.
   *
   * @return list<string> ids of the runs to execute right away
   */
  public function flush(): array
  {
    $direct = [];
    foreach ($this->pending as $eventId => $item) {
      $id = Id::new();
      $steps = (array)json_decode((string)$item['event']['steps'], true);
      $queued = 'queue' === $item['event']['mode'];
      $this->repository->insertRun([
        'id' => $id,
        'event_id' => $eventId,
        'project_id' => $item['event']['project_id'],
        'status' => 'queued',
        'depth' => $this->depth,
        'origin' => [] !== $this->origin ? json_encode($this->origin) : null,
        'count' => count($item['records']),
        'records' => json_encode($item['records'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        'steps' => json_encode(array_map(static fn($step): array => ['type' => (string)($step['type'] ?? ''), 'status' => 'waiting', 'done' => 0, 'total' => 0, 'error' => null], $steps)),
      ]);
      $queued ? $this->queue->push($id) : $direct[] = $id;
    }
    $this->pending = [];
    return $direct;
  }

  /**
   * While a run executes its steps, the changes they make start events one level deeper.
   *
   * @template T
   * @param list<string> $origin events of the chain so far, this one included
   * @param callable(): T $callback
   * @return T
   */
  public function within(int $depth, array $origin, callable $callback): mixed
  {
    [$previousDepth, $previousOrigin] = [$this->depth, $this->origin];
    [$this->depth, $this->origin] = [$depth, $origin];
    try {
      return $callback();
    } finally {
      [$this->depth, $this->origin] = [$previousDepth, $previousOrigin];
    }
  }

  /**
   * Events changed (admin app): forget the cached ones.
   */
  public function reset(): void
  {
    $this->events = [];
  }

  private function present(EntityDefinition $entity, array $row): array
  {
    return $this->presenter->withMedia($entity, [$this->presenter->presentForAdmin($entity, $row)])[0];
  }
}
