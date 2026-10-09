<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Rows of the tables `event` and `event_run` (see EventService, EventRunner).
 */
final class EventRepository
{
  /** Runs kept per event */
  private const KEEP_RUNS = 100;

  public function __construct(
    private ConnectionInterface $db,
  ) {
  }

  /**
   * @return list<array>
   */
  public function all(string $projectId): array
  {
    return $this->db->createQuery()->from('event')->where(['project_id' => $projectId])->orderBy(['name' => SORT_ASC])->all();
  }

  /**
   * Active events of an entity (all actions - the caller picks).
   *
   * @return list<array>
   */
  public function activeFor(string $entityId): array
  {
    return $this->db->createQuery()->from('event')->where(['entity_id' => $entityId, 'is_active' => true])->all();
  }

  /**
   * Active events of the media or the variables of a project.
   *
   * @return list<array>
   */
  public function activeForSource(string $projectId, string $source): array
  {
    return $this->db->createQuery()->from('event')->where(['project_id' => $projectId, 'source' => $source, 'is_active' => true])->all();
  }

  public function find(string $id, ?string $projectId = null): ?array
  {
    $query = $this->db->createQuery()->from('event')->where(['id' => $id]);
    if (null !== $projectId) {
      $query->andWhere(['project_id' => $projectId]);
    }
    $row = $query->one();
    return is_array($row) ? $row : null;
  }

  /**
   * @param list<string> $ids
   * @return array<string, string> id => name
   */
  public function names(array $ids): array
  {
    return [] === $ids ? [] : array_map('strval', $this->db->createQuery()->from('event')->select(['name', 'id'])->where(['id' => $ids])->indexBy('id')->column());
  }

  public function insert(array $row): void
  {
    $this->db->createCommand()->insert('event', $row + ['created_at' => date('Y-m-d H:i:s')])->execute();
  }

  public function update(string $id, array $values): void
  {
    $this->db->createCommand()->update('event', $values + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id])->execute();
  }

  public function delete(string $id): void
  {
    $this->db->createCommand()->delete('event', ['id' => $id])->execute();
  }

  public function insertRun(array $row): void
  {
    // With microseconds: runs of one second keep their order
    $this->db->createCommand()->insert('event_run', $row + ['created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u')])->execute();
    $old = $this->db->createQuery()->from('event_run')->select('id')->where(['event_id' => $row['event_id']])
      ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->offset(self::KEEP_RUNS)->column();
    if ([] !== $old) {
      $this->db->createCommand()->delete('event_run', ['id' => $old])->execute();
    }
  }

  public function findRun(string $id): ?array
  {
    $row = $this->db->createQuery()->from('event_run')->where(['id' => $id])->one();
    return is_array($row) ? $row : null;
  }

  public function updateRun(string $id, array $values): void
  {
    $this->db->createCommand()->update('event_run', $values, ['id' => $id])->execute();
  }

  /**
   * @return list<array> newest first, without the record data
   */
  public function runs(string $eventId, int $limit = 50): array
  {
    return $this->db->createQuery()->from('event_run')
      ->select(['id', 'event_id', 'status', 'depth', 'count', 'steps', 'error', 'created_at', 'started_at', 'finished_at'])
      ->where(['event_id' => $eventId])->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->all();
  }

  /**
   * Status of the newest run of every event of a project: event id => run.
   *
   * @return array<string, array>
   */
  public function lastRuns(string $projectId): array
  {
    $rows = $this->db->createQuery()->from('event_run')->select(['id', 'event_id', 'status', 'count', 'steps', 'created_at', 'finished_at'])
      ->where(['project_id' => $projectId])->orderBy(['created_at' => SORT_DESC])->limit(500)->all();
    $result = [];
    foreach ($rows as $row) {
      $result[(string)$row['event_id']] ??= $row;
    }
    return $result;
  }
}
