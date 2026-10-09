<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldType;
use App\Repository\RecordRepository;
use App\Repository\RevisionRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;

/**
 * Revisions in the admin app: the history of a record, one revision presented like the record
 * (mapped onto the current schema by field id), and restoring it - all fields or some - through
 * the usual checks of RecordService.
 */
final class RevisionService
{
  public function __construct(
    private RevisionRepository $revisions,
    private RecordRepository $records,
    private RecordPresenter $presenter,
    private RecordService $recordService,
    private ?\App\Application\Access\FieldAccess $fieldAccess = null,
  ) {
  }

  /**
   * @return list<array{id: string, action: string, changed: list<string>, created_by: mixed, created_at: string}>
   */
  public function history(EntityDefinition $entity, string $recordId): array
  {
    $this->record($entity, $recordId);
    // Fields the user may not read are not named either
    $fields = null !== $this->fieldAccess ? $this->fieldAccess->readableFields($entity) : $entity->fields;
    $byId = array_column(array_map(static fn($f): array => ['id' => $f->id, 'name' => $f->name], $fields), 'name', 'id');
    $list = array_map(static fn(array $row): array => [
      'id' => (string)$row['id'],
      'action' => (string)$row['action'],
      // Names of the changed fields as they are called now (removed fields are left out)
      'changed' => array_values(array_filter(array_map(
        static fn(string $id): ?string => EntityDefinition::DRAFT === $id ? $id : ($byId[$id] ?? null),
        (array)json_decode((string)($row['changed'] ?? '[]'), true)
      ))),
      'created_by' => $row['created_by'],
      'created_at' => (string)$row['created_at'],
    ], $this->revisions->forRecord($entity, $recordId));
    return $this->presenter->withActors($list);
  }

  /**
   * One revision: the record as it was (current fields; fields added later are empty), and the
   * fields of then that are gone now.
   *
   * @return array{id: string, action: string, created_at: string, record: array, removed: list<array{name: string, label: string, value: mixed}>}
   */
  public function show(EntityDefinition $entity, string $recordId, string $revisionId): array
  {
    $revision = $this->revision($entity, $recordId, $revisionId);
    [$record, $removed] = $this->present($entity, $recordId, RevisionRepository::decode($revision), (string)$revision['created_at']);
    return [
      'id' => (string)$revision['id'],
      'action' => (string)$revision['action'],
      'created_by' => $this->presenter->withActors([['created_by' => $revision['created_by']]])[0]['created_by'],
      'created_at' => (string)$revision['created_at'],
      'record' => $record,
      'removed' => $removed,
    ];
  }

  /**
   * Restores the revision - only the given fields, or all fields that still exist. The draft
   * state stays as it is.
   *
   * @param list<string>|null $fields
   */
  public function restore(EntityDefinition $entity, string $recordId, string $revisionId, ?array $fields): array
  {
    $this->record($entity, $recordId);
    $revision = $this->revision($entity, $recordId, $revisionId);
    [$record] = $this->present($entity, $recordId, RevisionRepository::decode($revision), (string)$revision['created_at']);
    // Counters and positions are no content of a revision
    // ... and fields limited to roles the user does not have
    $names = array_map(static fn($f): string => $f->name, array_filter($entity->fields, fn($f): bool => !$f->type->isGenerated() && FieldType::Order !== $f->type && (null === $this->fieldAccess || $this->fieldAccess->canWrite($f))));
    $names = null !== $fields ? array_values(array_intersect($names, $fields)) : $names;
    if ([] === $names) {
      throw new UserFacingException(I18n::t('Please choose at least one field to restore.'));
    }
    $data = array_intersect_key($record, array_flip($names));
    $i18n = array_intersect_key((array)($record['_i18n'] ?? []), array_flip($names));
    if ([] !== $i18n) {
      $data['_i18n'] = array_map(static fn($byLanguage): array => (array)$byLanguage, $i18n);
    }
    return $this->recordService->update($entity, $recordId, $data);
  }

  private function record(EntityDefinition $entity, string $recordId): array
  {
    return $this->records->find($entity, $recordId, true) ?? throw UserFacingException::notFound(I18n::t('This record does not exist (any more).'));
  }

  private function revision(EntityDefinition $entity, string $recordId, string $revisionId): array
  {
    $this->record($entity, $recordId);
    return $this->revisions->find($entity, $recordId, $revisionId) ?? throw UserFacingException::notFound(I18n::t('This revision does not exist (any more).'));
  }

  /**
   * @return array{0: array, 1: list<array{name: string, label: string, value: mixed}>}
   */
  private function present(EntityDefinition $entity, string $recordId, array $snapshot, string $at): array
  {
    $row = ['id' => $recordId, 'created_at' => $at, 'updated_at' => $at];
    foreach ($entity->fields as $field) {
      $entry = $snapshot['fields'][$field->id] ?? null;
      $row[$field->name] = $entry['value'] ?? null;
      foreach ($field->translatable ? $entity->otherLanguages() : [] as $language) {
        $row[$field->translationColumn($language)] = $entry['i18n'][$language] ?? null;
      }
    }
    if ($entity->drafts) {
      $row[EntityDefinition::DRAFT] = (bool)($snapshot['draft'] ?? false);
    }
    $records = $this->presenter->withMedia($entity, [$this->presenter->presentForAdmin($entity, $row)], true);
    $record = $this->presenter->withReferenceLabels($entity, $records)[0];

    $current = array_column(array_map(static fn($f): array => ['id' => $f->id], $entity->fields), 'id');
    $removed = [];
    foreach ($snapshot['fields'] as $id => $entry) {
      if (!in_array((string)$id, $current, true)) {
        $removed[] = ['name' => (string)$entry['name'], 'label' => (string)$entry['label'], 'value' => $entry['value']];
      }
    }
    return [$record, $removed];
  }
}
