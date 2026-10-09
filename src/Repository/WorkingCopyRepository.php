<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Service\CurrentActor;
use App\Domain\Schema\EntityDefinition;
use App\Shared\Id;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Working copies of published records (table `working_copy`): changes saved for later while the
 * record stays live as it is. One per record, the values by column as they would be stored.
 */
final class WorkingCopyRepository
{
  public function __construct(
    private ConnectionInterface $db,
    private CurrentActor $actor,
  ) {
  }

  /**
   * @return array{values: array<string, mixed>, created_by: ?string, created_at: string, updated_by: ?string, updated_at: string}|null
   */
  public function find(EntityDefinition $entity, string $recordId): ?array
  {
    $row = $this->db->createQuery()->from('working_copy')->where(['entity_id' => $entity->id, 'record_id' => $recordId])->one();
    if (null === $row) {
      return null;
    }
    return [
      'values' => (array)json_decode((string)$row['data'], true),
      'created_by' => $row['created_by'],
      'created_at' => (string)$row['created_at'],
      'updated_by' => $row['updated_by'],
      'updated_at' => (string)$row['updated_at'],
    ];
  }

  /**
   * Saves the working copy, replacing the former one.
   *
   * @param array<string, mixed> $values column => value
   * @param array<string, mixed> $row the record with these values (for the files it uses)
   */
  public function save(EntityDefinition $entity, string $recordId, array $values, array $row): void
  {
    $media = RevisionRepository::media($entity, RevisionRepository::snapshot($entity, $row));
    $data = [
      'data' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
      'media' => [] !== $media ? json_encode($media) : null,
      'updated_by' => $this->actor->id(),
      'updated_at' => date('Y-m-d H:i:s'),
    ];
    $updated = $this->db->createCommand()->update('working_copy', $data, ['entity_id' => $entity->id, 'record_id' => $recordId])->execute();
    if (0 === $updated && null === $this->find($entity, $recordId)) {
      $this->db->createCommand()->insert('working_copy', $data + [
        'id' => Id::new(),
        'project_id' => $entity->projectId,
        'entity_id' => $entity->id,
        'record_id' => $recordId,
        'created_by' => $data['updated_by'],
        'created_at' => $data['updated_at'],
      ])->execute();
    }
  }

  public function delete(EntityDefinition $entity, string $recordId): bool
  {
    return $this->db->createCommand()->delete('working_copy', ['entity_id' => $entity->id, 'record_id' => $recordId])->execute() > 0;
  }

  /**
   * Which of the records have a working copy.
   *
   * @param list<string> $recordIds
   * @return array<string, true>
   */
  public function recordsWithCopy(EntityDefinition $entity, array $recordIds): array
  {
    if ([] === $recordIds) {
      return [];
    }
    $ids = $this->db->createQuery()->from('working_copy')->select('record_id')->where(['entity_id' => $entity->id, 'record_id' => $recordIds])->column();
    return array_fill_keys(array_map('strval', $ids), true);
  }

  /**
   * Ids of all files working copies use (the media cleanup keeps them).
   *
   * @return array<string, true>
   */
  public function mediaIds(): array
  {
    $ids = [];
    foreach ($this->db->createQuery()->from('working_copy')->select('media')->where(['not', ['media' => null]])->column() as $list) {
      foreach ((array)json_decode((string)$list, true) as $id) {
        $ids[(string)$id] = true;
      }
    }
    return $ids;
  }
}
