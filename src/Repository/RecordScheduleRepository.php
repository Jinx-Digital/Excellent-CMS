<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\EntityDefinition;
use App\Shared\Id;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Scheduled publishing and unpublishing of records (table `record_schedule`), see RecordSchedules.
 */
final class RecordScheduleRepository
{
  public const ACTIONS = ['publish', 'unpublish'];

  public function __construct(
    private ConnectionInterface $db,
  ) {
  }

  /**
   * @param list<string> $recordIds
   * @return array<string, array<string, array{run_at: string, error: ?string}>> record id => action => schedule
   */
  public function forRecords(EntityDefinition $entity, array $recordIds): array
  {
    if ([] === $recordIds) {
      return [];
    }
    $result = [];
    foreach ($this->db->createQuery()->from('record_schedule')->where(['entity_id' => $entity->id, 'record_id' => array_values($recordIds)])->all() as $row) {
      $result[(string)$row['record_id']][(string)$row['action']] = ['run_at' => (string)$row['run_at'], 'error' => null !== $row['error'] ? (string)$row['error'] : null];
    }
    return $result;
  }

  /**
   * Sets (or with null removes) the time of an action - a new time starts without the old error.
   */
  public function set(EntityDefinition $entity, string $recordId, string $action, ?string $runAt, ?string $by): void
  {
    $where = ['entity_id' => $entity->id, 'record_id' => $recordId, 'action' => $action];
    $this->db->createCommand()->delete('record_schedule', $where)->execute();
    if (null !== $runAt) {
      $this->db->createCommand()->insert('record_schedule', $where + [
        'id' => Id::new(),
        'project_id' => $entity->projectId,
        'run_at' => $runAt,
        'created_by' => $by,
        'created_at' => date('Y-m-d H:i:s'),
      ])->execute();
    }
  }

  /**
   * Schedules that are due and did not fail, oldest first.
   *
   * @return list<array<string, mixed>>
   */
  public function due(string $now): array
  {
    return $this->db->createQuery()->from('record_schedule')->where(['<=', 'run_at', $now])->andWhere(['error' => null])->orderBy(['run_at' => SORT_ASC])->all();
  }

  public function done(string $id): void
  {
    $this->db->createCommand()->delete('record_schedule', ['id' => $id])->execute();
  }

  public function failed(string $id, string $error): void
  {
    $this->db->createCommand()->update('record_schedule', ['error' => mb_substr($error, 0, 2000)], ['id' => $id])->execute();
  }

  public function deleteForRecord(EntityDefinition $entity, string $recordId): void
  {
    $this->db->createCommand()->delete('record_schedule', ['entity_id' => $entity->id, 'record_id' => $recordId])->execute();
  }
}
