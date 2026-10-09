<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Service\CurrentActor;
use App\Application\Event\EventHooks;
use App\Domain\Schema\EntityDefinition;
use App\Shared\Id;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\QueryInterface;

/**
 * Rows of the content tables (_<project prefix><slug>). Values are already converted to their column types
 * (ValueConverter); this class only reads and writes.
 *
 * Entities with a trash: rows with `deleted_at` are in the trash and invisible to every read here
 * unless $withTrashed is asked for - they still hold their unique values and references.
 */
final class RecordRepository
{
  private const CHUNK = 1000;

  public function __construct(
    private ConnectionInterface $db,
    private CurrentActor $actor,
    private RevisionRepository $revisions,
    private WorkingCopyRepository $workingCopies,
    private RecordScheduleRepository $schedules,
    private EventHooks $events,
    private ?\App\Application\Search\SearchHooks $search = null,
  ) {
  }

  /**
   * Active records (not in the trash).
   */
  public function query(EntityDefinition $entity): QueryInterface
  {
    $query = $this->db->createQuery()->from($entity->tableName());
    return $entity->trash ? $query->andWhere([EntityDefinition::DELETED_AT => null]) : $query;
  }

  /**
   * Records the content API delivers: not in the trash and no drafts.
   */
  public function publishedQuery(EntityDefinition $entity): QueryInterface
  {
    $query = $this->query($entity);
    return $entity->drafts ? $query->andWhere([EntityDefinition::DRAFT => false]) : $query;
  }

  public static function isDraft(EntityDefinition $entity, array $row): bool
  {
    return $entity->drafts && (bool)($row[EntityDefinition::DRAFT] ?? false);
  }

  /**
   * Only for entities whose table has the draft column (drafts on, or just being switched off).
   */
  public function countDrafts(EntityDefinition $entity): int
  {
    return (int)$this->allQuery($entity)->andWhere([EntityDefinition::DRAFT => true])->count();
  }

  public function trashQuery(EntityDefinition $entity): QueryInterface
  {
    return $this->db->createQuery()->from($entity->tableName())->andWhere(['not', [EntityDefinition::DELETED_AT => null]]);
  }

  /**
   * Every record, the ones in the trash too (conditions of events on "delete").
   */
  public function queryWithTrashed(EntityDefinition $entity): QueryInterface
  {
    return $this->allQuery($entity);
  }

  private function allQuery(EntityDefinition $entity): QueryInterface
  {
    return $this->db->createQuery()->from($entity->tableName());
  }

  public function find(EntityDefinition $entity, string $id, bool $withTrashed = false): ?array
  {
    $row = ($withTrashed ? $this->allQuery($entity) : $this->query($entity))->andWhere(['id' => $id])->one();
    return is_array($row) ? $row : null;
  }

  /**
   * @param list<string> $ids
   * @return array<string, array> id => row
   */
  public function findMany(EntityDefinition $entity, array $ids): array
  {
    $result = [];
    foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
      foreach ($this->query($entity)->andWhere(['id' => $chunk])->all() as $row) {
        $result[(string)$row['id']] = $row;
      }
    }
    return $result;
  }

  /**
   * @param array<string, mixed> $values column => value
   */
  public function insert(EntityDefinition $entity, array $values): string
  {
    [$values] = $this->withOrder($entity, [$values]);
    $id = Id::new();
    $now = date('Y-m-d H:i:s');
    $by = $this->actor->id();
    $this->db->createCommand()->insert($entity->tableName(), ['id' => $id] + $values + ['created_at' => $now, 'updated_at' => $now, EntityDefinition::CREATED_BY => $by, EntityDefinition::UPDATED_BY => $by])->execute();
    $row = (array)$this->find($entity, $id, true);
    $this->revisions->record($entity, 'create', $row);
    $this->events->record($entity, 'create', $row);
    $this->search?->saved($entity, [$row]);
    return $id;
  }

  /**
   * Many rows in one statement per chunk (import).
   *
   * @param list<array<string, mixed>> $rows all with the same columns
   */
  public function insertBatch(EntityDefinition $entity, array $rows): void
  {
    if ([] === $rows) {
      return;
    }
    $rows = $this->withOrder($entity, $rows);
    $now = date('Y-m-d H:i:s');
    $by = $this->actor->id();
    $prepared = array_map(static fn(array $row): array => ['id' => Id::new()] + $row + ['created_at' => $now, 'updated_at' => $now, EntityDefinition::CREATED_BY => $by, EntityDefinition::UPDATED_BY => $by], $rows);
    foreach (array_chunk($prepared, 500) as $chunk) {
      $this->db->createCommand()->insertBatch($entity->tableName(), $chunk, array_keys($chunk[0]))->execute();
    }
    $saved = $this->findMany($entity, array_column($prepared, 'id'));
    foreach ($saved as $row) {
      $this->events->record($entity, 'create', $row);
    }
    $this->search?->saved($entity, array_values($saved));
  }

  /**
   * New records without a value in the order field go to the end: highest value + 1, + 2 ...
   *
   * @param list<array<string, mixed>> $rows
   * @return list<array<string, mixed>>
   */
  private function withOrder(EntityDefinition $entity, array $rows): array
  {
    $field = $entity->orderField();
    if (null === $field) {
      return $rows;
    }
    $next = null;
    foreach ($rows as &$row) {
      if (null === ($row[$field->name] ?? null)) {
        $next ??= (int)$this->allQuery($entity)->max($field->name);
        $row[$field->name] = ++$next;
      }
    }
    unset($row);
    return $rows;
  }

  /**
   * Gives the records the order of $ids: they take the places they had among each other, so
   * records not on the page stay where they are. Then all records are numbered 1, 2, 3 ... (no gaps,
   * no duplicates). Records in the trash keep their value.
   *
   * @param list<string> $ids
   */
  public function reorder(EntityDefinition $entity, array $ids): void
  {
    $field = $entity->orderField() ?? throw new \LogicException('The entity has no order field.');
    $all = array_map('strval', $this->query($entity)->select('id')->orderBy([$field->name => SORT_ASC, 'created_at' => SORT_ASC, 'id' => SORT_ASC])->column());
    $moved = array_values(array_intersect(array_values(array_unique($ids)), $all));
    $slots = array_keys(array_intersect($all, $moved));
    foreach ($slots as $index => $slot) {
      $all[$slot] = $moved[$index];
    }
    $current = array_map(static fn($value): ?int => null !== $value ? (int)$value : null, $this->query($entity)->select([$field->name, 'id'])->indexBy('id')->column());
    $changed = array_values(array_filter($all, static fn(string $id, int $position): bool => ($current[$id] ?? null) !== $position + 1, ARRAY_FILTER_USE_BOTH));
    $this->db->transaction(function () use ($entity, $field, $all, $changed): void {
      foreach ($changed as $id) {
        $this->db->createCommand()->update($entity->tableName(), [$field->name => array_search($id, $all, true) + 1, 'updated_at' => date('Y-m-d H:i:s'), EntityDefinition::UPDATED_BY => $this->actor->id()], ['id' => $id])->execute();
      }
    });
    // Positions are no content: the order changes start "update" events, no revisions
    foreach ([] !== $changed ? $this->findMany($entity, $changed) : [] as $row) {
      $this->events->record($entity, 'update', $row);
    }
  }

  /**
   * @param array<string, mixed> $values
   */
  public function update(EntityDefinition $entity, string $id, array $values): void
  {
    $before = $this->find($entity, $id, true);
    $this->db->createCommand()->update($entity->tableName(), $values + ['updated_at' => date('Y-m-d H:i:s'), EntityDefinition::UPDATED_BY => $this->actor->id()], ['id' => $id])->execute();
    $row = $this->find($entity, $id, true);
    if (null === $before || null === $row) {
      return;
    }
    $this->revisions->record($entity, 'update', $row, $before);
    $this->events->record($entity, 'update', $row, $before);
    $this->search?->saved($entity, [$row]);
    // Drafts: published or taken back
    if (self::isDraft($entity, $before) !== self::isDraft($entity, $row)) {
      $this->events->record($entity, self::isDraft($entity, $row) ? 'unpublish' : 'publish', $row, $before);
    }
  }

  public function delete(EntityDefinition $entity, string $id): bool
  {
    // Events see the record before it is gone (records in the trash were reported when they went there)
    $row = $this->find($entity, $id, true);
    if (null !== $row && null === ($row[EntityDefinition::DELETED_AT] ?? null)) {
      $this->events->record($entity, 'delete', $row);
    }
    $deleted = $this->db->createCommand()->delete($entity->tableName(), ['id' => $id])->execute() > 0;
    if ($deleted) {
      $this->revisions->deleteForRecord($entity, $id);
      $this->workingCopies->delete($entity, $id);
      $this->schedules->deleteForRecord($entity, $id);
      $this->search?->deleted($entity, $id);
    }
    return $deleted;
  }

  public function moveToTrash(EntityDefinition $entity, string $id): void
  {
    $this->db->createCommand()->update($entity->tableName(), [EntityDefinition::DELETED_AT => date('Y-m-d H:i:s'), EntityDefinition::DELETED_BY => $this->actor->id()], ['id' => $id])->execute();
    $row = (array)$this->find($entity, $id, true);
    $this->revisions->record($entity, 'trash', $row);
    $this->events->record($entity, 'delete', $row);
  }

  /**
   * @param list<string> $ids
   */
  public function restore(EntityDefinition $entity, array $ids): int
  {
    $count = 0;
    foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
      $count += $this->db->createCommand()->update($entity->tableName(), [EntityDefinition::DELETED_AT => null, EntityDefinition::DELETED_BY => null, EntityDefinition::UPDATED_BY => $this->actor->id()], ['and', ['id' => $chunk], ['not', [EntityDefinition::DELETED_AT => null]]])->execute();
    }
    foreach ($count > 0 ? $this->findMany($entity, $ids) : [] as $row) {
      $this->revisions->record($entity, 'restore', $row);
      $this->events->record($entity, 'restore', $row);
    }
    return $count;
  }

  /**
   * @return list<string>
   */
  public function trashedIds(EntityDefinition $entity, ?array $ids = null): array
  {
    if (!$entity->trash) {
      return [];
    }
    $query = $this->trashQuery($entity)->select('id');
    if (null !== $ids) {
      if ([] === $ids) {
        return [];
      }
      $query->andWhere(['id' => array_values(array_unique($ids))]);
    }
    return array_map('strval', $query->column());
  }

  public function count(EntityDefinition $entity): int
  {
    return (int)$this->query($entity)->count();
  }

  public function countTrashed(EntityDefinition $entity): int
  {
    return $entity->trash ? (int)$this->trashQuery($entity)->count() : 0;
  }

  /**
   * @param array<string, mixed> $where column => value
   * @param bool $withTrashed also records in the trash - they keep their unique values
   */
  public function exists(EntityDefinition $entity, array $where, ?string $exceptId = null, bool $withTrashed = false): bool
  {
    $query = ($withTrashed ? $this->allQuery($entity) : $this->query($entity))->andWhere($where);
    if (null !== $exceptId) {
      $query->andWhere(['<>', 'id', $exceptId]);
    }
    return $query->exists();
  }

  /**
   * Looks up many values of one column at once: value => id. Used to resolve references and to
   * find existing records by their key during an import.
   *
   * @param list<string> $values
   * @return array<string, string>
   */
  public function idsByValues(EntityDefinition $entity, string $column, array $values, bool $withTrashed = false): array
  {
    $result = [];
    foreach (array_chunk(array_values(array_unique($values)), self::CHUNK) as $chunk) {
      $rows = ($withTrashed ? $this->allQuery($entity) : $this->query($entity))->select(['id', $column])->andWhere([$column => $chunk])->all();
      foreach ($rows as $row) {
        $result[(string)$row[$column]] ??= (string)$row['id'];
      }
    }
    return $result;
  }

  /**
   * Values of a slug column that collide with $slug ("home", "home-2" ...), records in the trash
   * included (the unique index counts them). Trees: only on the same level ($scope = [parent => id
   * or null]).
   *
   * @param array<string, string|null> $scope
   * @return array<string, true>
   */
  public function takenSlugs(EntityDefinition $entity, string $column, string $slug, ?string $exceptId = null, array $scope = []): array
  {
    $base = (string)preg_replace('/-\d+$/', '', $slug);
    $quoted = $this->db->getQuoter()->quoteColumnName($column);
    $query = $this->allQuery($entity)->select($column)->andWhere(['or', [$column => [$slug, $base]], new Expression("{$quoted} LIKE :slug_prefix", [':slug_prefix' => $base.'-%'])]);
    foreach ($scope as $scopeColumn => $value) {
      $query->andWhere([$scopeColumn => $value]);
    }
    if (null !== $exceptId) {
      $query->andWhere(['<>', 'id', $exceptId]);
    }
    return array_fill_keys(array_map('strval', $query->column()), true);
  }

  /**
   * All values of a column: value => id (records in the trash included).
   *
   * @return array<string, string>
   */
  public function valueIds(EntityDefinition $entity, string $column): array
  {
    return array_map('strval', array_column($this->allQuery($entity)->select(['id', $column])->andWhere(['not', [$column => null]])->all(), 'id', $column));
  }

  /**
   * Counts records in the trash too: they still point to other records (foreign keys).
   */
  public function countWhere(EntityDefinition $entity, array $where): int
  {
    return (int)$this->allQuery($entity)->andWhere($where)->count();
  }

  /**
   * @return list<array<string, mixed>> all values of the given columns (schema changes check them)
   */
  public function column(EntityDefinition $entity, string $column): array
  {
    return $this->db->createQuery()->select(['id', $column])->from($entity->tableName())->where(['not', [$column => null]])->all();
  }
}
