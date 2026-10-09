<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Content\RecordPresenter;
use App\Application\Service\CurrentActor;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\GroupValues;
use App\Shared\Id;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Revisions of records (table `revision`): a snapshot after every change, written by
 * RecordRepository. The snapshot keeps the stored values by field id:
 *
 *   {"fields": {"<field id>": {"name": "title", "label": "Titel", "value": "…", "i18n": {"en": "…"}}}, "draft": false}
 *
 * Only for entities with revisions switched on; at most $limit per record are kept (0 = none at all).
 */
final class RevisionRepository
{
  public function __construct(
    private ConnectionInterface $db,
    private CurrentActor $actor,
    private int $limit = 50,
  ) {
  }

  /**
   * Revisions are written for entities that have them switched on (and not with REVISION_LIMIT=0).
   */
  public function enabled(EntityDefinition $entity): bool
  {
    return $this->limit > 0 && $entity->revisions;
  }

  /**
   * After a change: the new snapshot, with the fields that differ from $before. Records from the
   * time before revisions get their former state as first revision. Nothing changed = no revision.
   *
   * @param array|null $before the row before the change (null: new record)
   */
  public function record(EntityDefinition $entity, string $action, array $row, ?array $before = null): void
  {
    if (!$this->enabled($entity)) {
      return;
    }
    $id = (string)$row['id'];
    $snapshot = self::snapshot($entity, $row);
    $changed = [];
    if (null !== $before) {
      $previous = self::snapshot($entity, $before);
      $changed = self::changed($previous, $snapshot);
      if ('update' === $action && [] === $changed) {
        return;
      }
      if (!$this->exists($entity, $id)) {
        $this->insert($entity, $id, 'create', $previous, [], (string)($before['created_by'] ?? '') ?: null, (string)($before['updated_at'] ?? $before['created_at'] ?? date('Y-m-d H:i:s')));
      }
    }
    $this->insert($entity, $id, $action, $snapshot, $changed, $this->actor->id(), (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'));
    $this->prune($entity, $id);
  }

  /**
   * @return list<array> newest first
   */
  public function forRecord(EntityDefinition $entity, string $recordId): array
  {
    return $this->db->createQuery()->from('revision')
      ->select(['id', 'action', 'changed', 'created_by', 'created_at'])
      ->where(['entity_id' => $entity->id, 'record_id' => $recordId])
      ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
      ->all();
  }

  public function find(EntityDefinition $entity, string $recordId, string $id): ?array
  {
    $row = $this->db->createQuery()->from('revision')->where(['id' => $id, 'entity_id' => $entity->id, 'record_id' => $recordId])->one();
    return is_array($row) ? $row : null;
  }

  /**
   * A record is deleted for good: its revisions go with it.
   */
  public function deleteForRecord(EntityDefinition $entity, string $recordId): void
  {
    $this->db->createCommand()->delete('revision', ['entity_id' => $entity->id, 'record_id' => $recordId])->execute();
  }

  /**
   * Ids of all files revisions use (the media cleanup keeps them).
   *
   * @return array<string, true>
   */
  public function mediaIds(): array
  {
    $ids = [];
    foreach ($this->db->createQuery()->from('revision')->select('media')->where(['not', ['media' => null]])->column() as $list) {
      foreach ((array)json_decode((string)$list, true) as $id) {
        $ids[(string)$id] = true;
      }
    }
    return $ids;
  }

  /**
   * @return array{fields: array<string, array{name: string, label: string, value: mixed, i18n?: array<string, mixed>}>, draft?: bool}
   */
  public static function snapshot(EntityDefinition $entity, array $row): array
  {
    $fields = [];
    foreach ($entity->fields as $field) {
      $entry = ['name' => $field->name, 'label' => $field->label, 'value' => $row[$field->name] ?? null];
      if ($field->translatable && [] !== $entity->otherLanguages()) {
        foreach ($entity->otherLanguages() as $language) {
          $entry['i18n'][$language] = $row[$field->translationColumn($language)] ?? null;
        }
      }
      $fields[$field->id] = $entry;
    }
    $snapshot = ['fields' => $fields];
    if ($entity->drafts) {
      $snapshot['draft'] = (bool)($row[EntityDefinition::DRAFT] ?? false);
    }
    return $snapshot;
  }

  public static function decode(array $revision): array
  {
    $data = json_decode((string)$revision['data'], true);
    return is_array($data) ? $data + ['fields' => []] : ['fields' => []];
  }

  /**
   * @return list<string> ids of the fields that differ ("draft" for the draft state)
   */
  private static function changed(array $before, array $after): array
  {
    $changed = [];
    foreach ($after['fields'] as $id => $entry) {
      $old = $before['fields'][$id] ?? null;
      if (null === $old || (string)json_encode([$old['value'], $old['i18n'] ?? null]) !== (string)json_encode([$entry['value'], $entry['i18n'] ?? null])) {
        $changed[] = (string)$id;
      }
    }
    if (($before['draft'] ?? null) !== ($after['draft'] ?? null)) {
      $changed[] = EntityDefinition::DRAFT;
    }
    return $changed;
  }

  private function exists(EntityDefinition $entity, string $recordId): bool
  {
    return $this->db->createQuery()->from('revision')->where(['entity_id' => $entity->id, 'record_id' => $recordId])->exists();
  }

  private function insert(EntityDefinition $entity, string $recordId, string $action, array $snapshot, array $changed, ?string $by, string $at): void
  {
    $media = self::media($entity, $snapshot);
    $this->db->createCommand()->insert('revision', [
      'id' => Id::new(),
      'project_id' => $entity->projectId,
      'entity_id' => $entity->id,
      'record_id' => $recordId,
      'action' => $action,
      'data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
      'changed' => [] !== $changed ? json_encode($changed) : null,
      'media' => [] !== $media ? json_encode($media) : null,
      'created_by' => $by,
      'created_at' => $at,
    ])->execute();
  }

  /**
   * Ids of the files a snapshot uses (also for working copies).
   *
   * @return list<string>
   */
  public static function media(EntityDefinition $entity, array $snapshot): array
  {
    $isMedia = static fn(FieldDefinition $f): bool => FieldType::Media === $f->type;
    $ids = [];
    foreach ($entity->fields as $field) {
      $entry = $snapshot['fields'][$field->id] ?? null;
      if (null === $entry || (FieldType::Media !== $field->type && FieldType::Group !== $field->type)) {
        continue;
      }
      foreach (array_merge([$entry['value']], array_values($entry['i18n'] ?? [])) as $value) {
        array_push($ids, ...(FieldType::Group === $field->type ? GroupValues::collect($field, $value, $isMedia) : RecordPresenter::mediaIds($field, $value)));
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Keeps the newest $limit revisions of a record.
   */
  private function prune(EntityDefinition $entity, string $recordId): void
  {
    $old = $this->db->createQuery()->from('revision')->select('id')
      ->where(['entity_id' => $entity->id, 'record_id' => $recordId])
      ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
      ->offset($this->limit)->column();
    if ([] !== $old) {
      $this->db->createCommand()->delete('revision', ['id' => $old])->execute();
    }
  }
}
