<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Media\MediaService;
use App\Application\Project\ProjectVariables;
use App\Application\Service\CurrentUser;
use App\Domain\Access\EntityPermission;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\InvalidValueException;
use App\Domain\Schema\Slug;
use App\Domain\Schema\ValueConverter;
use App\Repository\EntityRepository;
use App\Repository\MediaRepository;
use App\Repository\RecordRepository;
use App\Repository\WorkingCopyRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Yiisoft\Db\Query\QueryInterface;

/**
 * Records of an entity as edited in the admin app: validation by the field definitions
 * (type, required, unique, references) and protection of referenced records.
 * Permissions are checked by the controller before.
 */
final class RecordService
{
  public function __construct(
    private RecordRepository $records,
    private EntityRepository $entityRepository,
    private RecordPresenter $presenter,
    private MediaRepository $media,
    private TreeService $tree,
    private ProjectVariables $variables,
    private RecordQuery $recordQuery,
    private CurrentUser $currentUser,
    private WorkingCopyRepository $workingCopies,
    private ?\App\Application\Access\FieldAccess $fieldAccess = null,
  ) {
  }

  /** The record being validated is (or stays) a draft: required fields may stay empty */
  private bool $draft = false;
  /** Validating a working copy: required fields may stay empty as well, nothing about drafts changes */
  private bool $workingCopy = false;

  public function search(EntityDefinition $entity, string $search, array $filter, ?string $sort, bool $trash = false): QueryInterface
  {
    if ($trash && !$entity->trash) {
      throw new UserFacingException(I18n::t('"{entity}" has no trash.', ['entity' => $entity->name]));
    }
    // Fields of referenced entities (filter[author][name], sort=author[name]) only if I may read them
    return $this->recordQuery->apply(
      $trash ? $this->records->trashQuery($entity) : $this->records->query($entity),
      $entity,
      $search,
      $filter,
      $sort,
      canRead: fn(EntityDefinition $target): bool => $this->currentUser->can(EntityPermission::Read, $target),
    );
  }

  public function get(EntityDefinition $entity, string $id): array
  {
    $row = $this->records->find($entity, $id) ?? throw UserFacingException::notFound(I18n::t('This record does not exist (any more).'));
    $record = $this->tree->withChildCounts($entity, [$this->presentOne($entity, $row)])[0];
    if (null !== $entity->treeField()) {
      $record['_path'] = $this->tree->path($entity, $row);
    }
    $record['_working_copy'] = $this->workingCopy($entity, $row);
    return $record;
  }

  /**
   * Saves changes of a published record for later: the record stays live as
   * it is. Like update(), only the sent fields change - the working copy keeps the others. Required
   * fields may stay empty until publishing.
   */
  public function saveWorkingCopy(EntityDefinition $entity, string $id, array $data): array
  {
    $existing = $this->publishedRecord($entity, $id);
    unset($data[EntityDefinition::DRAFT]);
    $this->workingCopy = true;
    try {
      $values = $this->validate($entity, $data, $existing);
    } finally {
      $this->workingCopy = false;
    }
    $values = array_merge($this->workingCopies->find($entity, $id)['values'] ?? [], $values);
    $this->workingCopies->save($entity, $id, $values, array_merge($existing, $values));
    return $this->get($entity, $id);
  }

  /**
   * Publishes the changes (like update()) and removes the working copy.
   */
  public function publishWorkingCopy(EntityDefinition $entity, string $id, array $data): array
  {
    $this->publishedRecord($entity, $id);
    unset($data[EntityDefinition::DRAFT]);
    $this->update($entity, $id, $data);
    $this->workingCopies->delete($entity, $id);
    return $this->get($entity, $id);
  }

  /**
   * Publishes the saved working copy as it is (scheduled publishing): its values go live after the
   * required fields are checked, the working copy is gone. Null if the record has none.
   */
  public function publishStoredWorkingCopy(EntityDefinition $entity, string $id): ?array
  {
    $existing = $this->publishedRecord($entity, $id);
    $copy = $this->workingCopies->find($entity, $id);
    if (null === $copy) {
      return null;
    }
    $values = array_intersect_key($copy['values'], $existing);
    unset($values['id'], $values[EntityDefinition::DRAFT]);
    $row = array_merge($existing, $values);
    $errors = [];
    foreach ($entity->fields as $field) {
      if ($field->required && in_array($row[$field->name] ?? null, [null, '', '[]', '{}'], true)) {
        $errors[$field->name][] = I18n::t('Please fill in.');
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    if ([] !== $values) {
      $this->records->update($entity, $id, $values);
    }
    $this->workingCopies->delete($entity, $id);
    return $this->get($entity, $id);
  }

  public function discardWorkingCopy(EntityDefinition $entity, string $id): array
  {
    $this->publishedRecord($entity, $id);
    $this->workingCopies->delete($entity, $id);
    return $this->get($entity, $id);
  }

  /**
   * Records of a list: "_working_copy" true for the ones with one.
   *
   * @param list<array<string, mixed>> $records
   * @return list<array<string, mixed>>
   */
  public function withWorkingCopyFlags(EntityDefinition $entity, array $records): array
  {
    $copies = $this->workingCopies->recordsWithCopy($entity, array_map(static fn(array $record): string => (string)$record['id'], $records));
    return array_map(static fn(array $record): array => $record + ['_working_copy' => isset($copies[(string)$record['id']])], $records);
  }

  /**
   * The record as the working copy has it - columns of deleted fields are left out.
   */
  private function workingCopy(EntityDefinition $entity, array $row): ?array
  {
    $copy = $this->workingCopies->find($entity, (string)$row['id']);
    if (null === $copy) {
      return null;
    }
    $values = array_intersect_key($copy['values'], $row);
    unset($values['id'], $values[EntityDefinition::DRAFT]);
    $copyRow = array_merge($row, $values, ['updated_at' => $copy['updated_at'], EntityDefinition::UPDATED_BY => $copy['updated_by']]);
    return $this->presentOne($entity, $copyRow);
  }

  private function presentOne(EntityDefinition $entity, array $row): array
  {
    $records = $this->presenter->withActors($this->presenter->withMedia($entity, [$this->presenter->presentForAdmin($entity, $row)], true));
    return $this->presenter->withReferenceLabels($entity, $records)[0];
  }

  /**
   * Working copies exist for published records - with drafts switched off, every record is published.
   */
  private function publishedRecord(EntityDefinition $entity, string $id): array
  {
    $existing = $this->records->find($entity, $id) ?? throw UserFacingException::notFound(I18n::t('This record does not exist (any more).'));
    if (RecordRepository::isDraft($entity, $existing)) {
      throw UserFacingException::conflict(I18n::t('Drafts are not live - save them directly.'), 'record_is_draft');
    }
    return $existing;
  }

  public function create(EntityDefinition $entity, array $data): array
  {
    $values = $this->validate($entity, $data, null);
    $id = $this->records->insert($entity, $values);
    return $this->get($entity, $id);
  }

  /**
   * Only the sent fields change.
   */
  public function update(EntityDefinition $entity, string $id, array $data): array
  {
    $existing = $this->records->find($entity, $id) ?? throw UserFacingException::notFound(I18n::t('This record does not exist (any more).'));
    $values = $this->validate($entity, $data, $existing);
    if ([] !== $values) {
      $this->records->update($entity, $id, $values);
    }
    // Back to draft: nothing is live any more, the draft is edited directly
    if ($entity->drafts && true === ($values[EntityDefinition::DRAFT] ?? null)) {
      $this->workingCopies->delete($entity, $id);
    }
    return $this->get($entity, $id);
  }

  /**
   * One record: into the trash (if the entity has one), otherwise deleted.
   */
  public function delete(EntityDefinition $entity, string $id): void
  {
    $result = $this->deleteMany($entity, [$id]);
    if ([] !== $result['failed']) {
      $failure = $result['failed'][0];
      throw 'not_found' === $failure['code']
        ? UserFacingException::notFound($failure['message'])
        : UserFacingException::conflict($failure['message'], $failure['code']);
    }
  }

  /**
   * Several records at once. Records in use by others are skipped and reported - deleting the
   * rest is not undone because of them.
   *
   * @param list<string> $ids
   * @param bool $permanent delete for good, even if the entity has a trash (and records in the trash)
   * @return array{trashed: int, deleted: int, failed: list<array{id: string, label: string, message: string, code: string}>}
   */
  public function deleteMany(EntityDefinition $entity, array $ids, bool $permanent = false): array
  {
    $result = ['trashed' => 0, 'deleted' => 0, 'failed' => []];
    foreach (array_values(array_unique(array_map('strval', $ids))) as $id) {
      $row = $this->records->find($entity, $id, withTrashed: true);
      if (null === $row) {
        $result['failed'][] = ['id' => $id, 'label' => $id, 'message' => I18n::t('This record does not exist (any more).'), 'code' => 'not_found'];
        continue;
      }
      // Also for the trash: a hidden record would leave references pointing to nothing visible
      $usage = array_filter($this->referencedBy($entity, $id), static fn(array $ref): bool => $ref['count'] > 0);
      if ([] !== $usage) {
        $text = implode(', ', array_map(static fn(array $ref): string => $ref['other_project']
          ? I18n::t('{count} × {entity} ({field}) in another project', ['count' => $ref['count'], 'entity' => $ref['entity_name'], 'field' => $ref['field_label']])
          : I18n::t('{count} × {entity} ({field})', ['count' => $ref['count'], 'entity' => $ref['entity_name'], 'field' => $ref['field_label']]), $usage));
        $result['failed'][] = ['id' => $id, 'label' => $this->presenter->label($entity, $row), 'message' => I18n::t('Still in use: {usages}.', ['usages' => $text]), 'code' => 'record_in_use'];
        continue;
      }
      if ($entity->trash && !$permanent && null === $row[EntityDefinition::DELETED_AT]) {
        $this->records->moveToTrash($entity, $id);
        $result['trashed']++;
      } else {
        $this->records->delete($entity, $id);
        $result['deleted']++;
      }
    }
    return $result;
  }

  /**
   * @param list<string> $ids records in the trash
   */
  /**
   * Drag & drop in the admin app: the records get the order of $ids (see RecordRepository::reorder()).
   *
   * @param list<string> $ids
   */
  public function reorder(EntityDefinition $entity, array $ids): void
  {
    if (null === $entity->orderField()) {
      throw new UserFacingException(I18n::t('"{entity}" has no order field.', ['entity' => $entity->name]));
    }
    $this->records->reorder($entity, array_map('strval', $ids));
  }

  public function restore(EntityDefinition $entity, array $ids): int
  {
    if (!$entity->trash) {
      throw new UserFacingException(I18n::t('"{entity}" has no trash.', ['entity' => $entity->name]));
    }
    return $this->records->restore($entity, array_map('strval', $ids));
  }

  /**
   * Deletes everything in the trash for good.
   *
   * @return array{trashed: int, deleted: int, failed: list<array>}
   */
  public function emptyTrash(EntityDefinition $entity): array
  {
    return $this->deleteMany($entity, $this->records->trashedIds($entity), permanent: true);
  }

  /**
   * Choices for reference fields: id + label, searched in the display field.
   *
   * @return list<array{id: string, label: string}>
   */
  public function options(EntityDefinition $entity, string $search, int $limit = 20, array $ids = []): array
  {
    $display = $entity->displayField();
    $query = $this->records->query($entity)->limit($limit);
    if ([] !== $ids) {
      $query->andWhere(['id' => $ids]);
    } elseif ('' !== trim($search)) {
      $conditions = ['or', ['id' => trim($search)]];
      foreach ($entity->fields as $field) {
        if ($field->type->isTextual()) {
          $conditions[] = ['like', $field->name, trim($search)];
        }
      }
      $query->andWhere($conditions);
    }
    $query->orderBy(null !== $display ? [$display => SORT_ASC, 'id' => SORT_ASC] : ['created_at' => SORT_DESC]);

    return array_map(fn(array $row): array => ['id' => (string)$row['id'], 'label' => $this->presenter->label($entity, $row)], $query->all());
  }

  /**
   * Which records point to this one ("hasMany" of the old version), per entity and field.
   *
   * @return list<array{entity: string, entity_name: string, field: string, field_label: string, count: int}>
   */
  public function referencedBy(EntityDefinition $entity, string $id): array
  {
    $result = [];
    foreach ($this->entityRepository->referencesTo($entity->id) as ['entity' => $source, 'field' => $field]) {
      $result[] = [
        'entity' => $source->slug,
        'entity_name' => $source->name,
        // Global records: references from other projects count too, but cannot be opened here
        'other_project' => null === $this->entityRepository->findById($source->id),
        'field' => $field->name,
        'field_label' => $field->label,
        // Lists of references are JSON: "…","<id>",…
        'count' => $this->records->countWhere($source, $field->repeatable || FieldType::Group === $field->type ? ['like', $field->name, '"'.$id.'"'] : [$field->name => $id]),
      ];
    }
    return $result;
  }

  /**
   * Other languages of translatable fields, sent as `_i18n` ({"title": {"en": "..."}}) into their
   * columns title__en ... Only the default language can be required; slugs are unique per
   * language and made from the source field in the same language.
   *
   * @param array<string, mixed> $values
   * @param array<string, string[]> $errors
   */
  private function validateTranslations(EntityDefinition $entity, array $data, ?array $existing, array &$values, array &$errors): void
  {
    $sentAll = is_array($data['_i18n'] ?? null) ? $data['_i18n'] : [];
    foreach ([] !== $entity->otherLanguages() ? $entity->translatableFields() : [] as $field) {
      $sentField = is_array($sentAll[$field->name] ?? null) ? $sentAll[$field->name] : [];
      foreach ($entity->otherLanguages() as $language) {
        $column = $field->translationColumn($language);
        $sent = array_key_exists($language, $sentField);
        // A slug of this language that is still empty follows its source as soon as that has a value
        $source = FieldType::Slug === $field->type && null !== $field->slugSource ? $entity->field($field->slugSource) : null;
        $sourceSent = null !== $source && $source->translatable && is_array($sentAll[$source->name] ?? null) && array_key_exists($language, $sentAll[$source->name]);
        $fillSlug = !$sent && null !== $existing && null === ($existing[$column] ?? null) && $sourceSent;
        // Unchanged languages stay as they are
        if (!$sent && null !== $existing && !$fillSlug) {
          continue;
        }
        if (FieldType::Group === $field->type || $field->repeatable) {
          $problems = [];
          $values[$column] = FieldType::Group === $field->type
            ? $this->groupStorage($field, $sent ? $sentField[$language] : null, $problems, $language)
            : $this->repeatList($entity, $field, $sent ? $sentField[$language] : null, $problems, $language);
          if ([] !== $problems) {
            $errors["_i18n.{$field->name}.{$language}"] = $problems;
          }
          continue;
        }
        try {
          $value = $this->variables->toStorage($field, $sent ? $sentField[$language] : null, $language);
        } catch (InvalidValueException $e) {
          $errors["_i18n.{$field->name}.{$language}"][] = sprintf('%s (%s): %s', $field->label, strtoupper($language), $e->getMessage());
          continue;
        }
        if (FieldType::Slug === $field->type) {
          $value = $this->translatedSlug($entity, $field, $language, $value, $data, $existing);
        }
        $values[$column] = $value;
      }
    }
  }

  private function translatedSlug(EntityDefinition $entity, FieldDefinition $field, string $language, ?string $value, array $data, ?array $existing): ?string
  {
    $length = $field->length ?? FieldType::DEFAULT_LENGTH;
    $column = $field->translationColumn($language);
    $source = null !== $field->slugSource ? $entity->field($field->slugSource) : null;
    if (null === $value && null !== $source) {
      // The source in the same language - or its only value if it is not translatable
      $text = $source->translatable
        ? ($data['_i18n'][$source->name][$language] ?? $existing[$source->translationColumn($language)] ?? null)
        : ($data[$source->name] ?? $existing[$source->name] ?? null);
      $value = is_scalar($text) ? (Slug::make((string)$text, $length) ?: null) : null;
    }
    if (null === $value || (null !== $existing && $value === ($existing[$column] ?? null) && !$this->parentChanged($entity, $data, $existing))) {
      return $value;
    }
    return Slug::unique($value, $this->records->takenSlugs($entity, $column, $value, $existing['id'] ?? null, $this->slugScope($entity, $data, $existing)), $length);
  }

  /**
   * Trees: slugs are unique per level - [parent field => parent id, null at the top], else none.
   *
   * @return array<string, string|null>
   */
  private function slugScope(EntityDefinition $entity, array $data, ?array $existing): array
  {
    $tree = $entity->treeField();
    if (null === $tree) {
      return [];
    }
    $raw = array_key_exists($tree->name, $data) ? $data[$tree->name] : ($existing[$tree->name] ?? null);
    // The admin app may send the reference object back
    if (is_array($raw)) {
      $raw = $raw['id'] ?? null;
    }
    return [$tree->name => is_scalar($raw) && '' !== trim((string)$raw) ? trim((string)$raw) : null];
  }

  /**
   * A record moves to another parent: its slugs must be free on the new level.
   */
  private function parentChanged(EntityDefinition $entity, array $data, ?array $existing): bool
  {
    $tree = $entity->treeField();
    if (null === $tree || null === $existing || !array_key_exists($tree->name, $data)) {
      return false;
    }
    $old = $existing[$tree->name] ?? null;
    return $this->slugScope($entity, $data, $existing)[$tree->name] !== (null !== $old ? (string)$old : null);
  }

  /**
   * Moved records keep their slugs where possible - a slug taken on the new level gets a number.
   *
   * @param array<string, mixed> $values
   */
  private function moveSlugs(EntityDefinition $entity, array $data, array $existing, array &$values): void
  {
    if (!$this->parentChanged($entity, $data, $existing)) {
      return;
    }
    $scope = $this->slugScope($entity, $data, $existing);
    foreach ($entity->fields as $field) {
      if (FieldType::Slug !== $field->type) {
        continue;
      }
      $columns = [$field->name, ...($field->translatable ? array_map($field->translationColumn(...), $entity->otherLanguages()) : [])];
      foreach ($columns as $column) {
        $value = $existing[$column] ?? null;
        if (array_key_exists($column, $values) || null === $value) {
          continue;
        }
        $free = Slug::unique((string)$value, $this->records->takenSlugs($entity, $column, (string)$value, (string)$existing['id'], $scope), $field->length ?? FieldType::DEFAULT_LENGTH);
        if ($free !== $value) {
          $values[$column] = $free;
        }
      }
    }
  }

  /**
   * Repeatable field: the values in their order, each checked like a single value - stored as a
   * JSON list, null when empty. Accepts a list (also of the objects the API sent out, e.g. files),
   * a JSON list, or a single value.
   *
   * @param list<string> $problems
   */
  private function repeatList(EntityDefinition $entity, FieldDefinition $field, mixed $raw, array &$problems, ?string $language = null): ?string
  {
    $items = $this->repeatItems($field, $raw, $problems, $language);
    return [] !== $items ? json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
  }

  /**
   * @param list<string> $problems
   * @return list<string>
   */
  private function repeatItems(FieldDefinition $field, mixed $raw, array &$problems, ?string $language = null): array
  {
    if (is_string($raw) && str_starts_with(trim($raw), '[')) {
      $decoded = json_decode($raw, true);
      $raw = is_array($decoded) ? $decoded : $raw;
    }
    $items = [];
    foreach (null === $raw || '' === $raw ? [] : (is_array($raw) ? $raw : [$raw]) as $item) {
      if (is_array($item)) {
        $item = $item['id'] ?? null;
      }
      try {
        $value = $this->variables->toStorage($field, $item, $language);
      } catch (InvalidValueException $e) {
        $problems[] = I18n::quote(is_scalar($item) ? mb_strimwidth((string)$item, 0, 40, '…') : '?').': '.$e->getMessage();
        continue;
      }
      if (null !== $value) {
        $items[] = (string)$value;
      }
    }
    // Files and records are listed once
    if (FieldType::Media === $field->type || FieldType::Reference === $field->type) {
      $items = array_values(array_unique($items));
    }

    if (FieldType::Media === $field->type) {
      $files = $this->media->findMany($items);
      foreach ($items as $id) {
        $file = $files[$id] ?? null;
        if (null === $file) {
          $problems[] = I18n::t('One of the files does not exist (any more). Please upload it again.');
        } elseif (!MediaService::accepts($field->mediaAccept(), (string)$file['mime_type'])) {
          $problems[] = I18n::t('"{file}": only these file types are allowed here: {types}.', ['file' => $file['name'], 'types' => MediaService::describeAccept($field->mediaAccept())]);
        }
      }
    }
    if (FieldType::Reference === $field->type && [] !== $items) {
      $target = $this->entityRepository->findById((string)$field->referenceEntityId);
      $found = null !== $target ? $this->records->findMany($target, $items) : [];
      if (count($found) < count($items)) {
        $problems[] = I18n::t('One of the chosen records does not exist.');
      }
    }

    $count = count($items);
    if ($field->required && !$this->draft && 0 === $count && null === $language) {
      $problems[] = I18n::t('Please fill in.');
    } elseif (null !== $field->repeatMin && $count > 0 && $count < $field->repeatMin) {
      // An optional field may stay empty - but if it has values, then enough
      $problems[] = I18n::t('Please enter at least {count, plural, one{# value} other{# values}}.', ['count' => $field->repeatMin]);
    }
    if (null !== $field->repeatMax && $count > $field->repeatMax) {
      $problems[] = I18n::t('At most {max, plural, one{# value} other{# values}} allowed (there are {count}).', ['max' => $field->repeatMax, 'count' => $count]);
    }
    return $items;
  }

  /** @var array<string, true> keys of the blocks of the group value being checked */
  private array $blockKeys = [];

  /**
   * Group field: an object with the group's fields - or a list of objects if repeatable - stored as
   * JSON. Every field inside is checked like a field of an entity (nested groups too); problems
   * name their path ("SEO › Beschreibung: ...").
   *
   * @param list<string> $problems
   */
  private function groupStorage(FieldDefinition $field, mixed $raw, array &$problems, ?string $language = null): ?string
  {
    // Keys of blocks are unique in the whole value, nested blocks included
    $this->blockKeys = [];
    $value = $this->groupValue($field, $raw, '', $problems, $language, 0);
    return null !== $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
  }

  /**
   * @param list<string> $problems
   */
  private function groupValue(FieldDefinition $field, mixed $raw, string $prefix, array &$problems, ?string $language, int $depth): ?array
  {
    if ([] === $field->groups() || $depth > \App\Domain\Schema\FieldGroup::MAX_DEPTH) {
      $problems[] = $prefix.I18n::t('The field group does not exist (any more).');
      return null;
    }
    $objects = \App\Domain\Schema\GroupValues::objects($field, $raw);
    $result = [];
    foreach ($objects as $index => $object) {
      if (!$field->isBlocks()) {
        $values = $this->groupObject($field->group, $object, $prefix, $problems, $language, $depth);
        if (null !== $values) {
          $result[] = $values;
        }
        continue;
      }
      // Blocks: every item names its group; it keeps its key (a new one if missing or doubled)
      $group = $field->groupFor($object);
      $type = (string)($object[FieldDefinition::BLOCK_TYPE] ?? '');
      if (null === $group) {
        $problems[] = $prefix.I18n::t('Block {number}: "{type}" is no block type of this field.', ['number' => $index + 1, 'type' => $type]);
        continue;
      }
      $key = (string)($object[FieldDefinition::BLOCK_KEY] ?? '');
      if (1 !== preg_match('/^[A-Za-z0-9_-]{1,40}$/', $key) || isset($this->blockKeys[$key])) {
        $key = bin2hex(random_bytes(6));
      }
      $this->blockKeys[$key] = true;
      $values = $this->groupObject($group, $object, $prefix.$group->label.' › ', $problems, $language, $depth);
      // An empty block stays (e.g. a divider)
      $result[] = [FieldDefinition::BLOCK_TYPE => $group->name, FieldDefinition::BLOCK_KEY => $key] + ($values ?? array_fill_keys(array_map(static fn(FieldDefinition $f): string => $f->name, $group->fields), null));
    }

    $count = count($result);
    $label = '' !== $prefix ? rtrim($prefix, ' ›:').': ' : '';
    if ($field->required && !$this->draft && 0 === $count && null === $language) {
      $problems[] = $label.I18n::t('Please fill in.');
    } elseif ($field->repeatable && null !== $field->repeatMin && $count > 0 && $count < $field->repeatMin) {
      $problems[] = $label.I18n::t('Please enter at least {count, plural, one{# item} other{# items}}.', ['count' => $field->repeatMin]);
    }
    if ($field->repeatable && null !== $field->repeatMax && $count > $field->repeatMax) {
      $problems[] = $label.I18n::t('At most {max, plural, one{# item} other{# items}} allowed (there are {count}).', ['max' => $field->repeatMax, 'count' => $count]);
    }
    if ([] === $result) {
      return null;
    }
    return $field->repeatable ? $result : $result[0];
  }

  /**
   * One object of a group: its fields, converted - null if everything is empty.
   *
   * @param list<string> $problems
   */
  private function groupObject(\App\Domain\Schema\FieldGroup $group, array $object, string $prefix, array &$problems, ?string $language, int $depth): ?array
  {
    $result = [];
    $empty = true;
    foreach ($group->fields as $sub) {
      $raw = $object[$sub->name] ?? null;
      $path = $prefix.$sub->label;
      $inner = [];
      if (FieldType::Group === $sub->type) {
        $value = $this->groupValue($sub, $raw, $path.' › ', $problems, $language, $depth + 1);
      } elseif ($sub->repeatable) {
        $value = $this->repeatItems($sub, $raw, $inner, $language) ?: null;
      } else {
        // Files and records come back as the objects the API sent - other values (code, JSON, date range,
        // fields of plugins) are objects themselves
        if (is_array($raw) && $sub->type->hasForeignKey()) {
          $raw = $raw['id'] ?? null;
        }
        try {
          $value = $this->variables->toStorage($sub, $raw, $language);
        } catch (InvalidValueException $e) {
          $value = null;
          $inner[] = $e->getMessage();
        }
        if (null !== $value) {
          $this->checkSingle($sub, (string)$value, $inner);
        } elseif ($sub->required && !$this->draft && [] === $inner) {
          $inner[] = I18n::t('Please fill in.');
        }
      }
      foreach ($inner as $problem) {
        $problems[] = $path.': '.$problem;
      }
      $result[$sub->name] = $value;
      $empty = $empty && (null === $value || [] === $value);
    }
    return $empty ? null : $result;
  }

  /**
   * Files and records a single value points to must exist (and files fit the allowed types).
   *
   * @param list<string> $problems
   */
  private function checkSingle(FieldDefinition $field, string $value, array &$problems): void
  {
    if (FieldType::Media === $field->type) {
      $file = $this->media->find($value);
      if (null === $file) {
        $problems[] = I18n::t('This file does not exist (any more). Please upload it again.');
      } elseif (!MediaService::accepts($field->mediaAccept(), (string)$file['mime_type'])) {
        $problems[] = I18n::t('Only these file types are allowed here: {types}.', ['types' => MediaService::describeAccept($field->mediaAccept())]);
      }
    }
    if (FieldType::Reference === $field->type) {
      $target = $this->entityRepository->findById((string)$field->referenceEntityId);
      if (null === $target || null === $this->records->find($target, $value)) {
        $problems[] = I18n::t('The chosen record does not exist.');
      }
    }
  }

  /**
   * Slug of a record: the entered one, or - if empty - made from the source field (new records and
   * when the slug was cleared). Taken slugs get "-2", "-3" ...
   */
  private function slug(EntityDefinition $entity, FieldDefinition $field, ?string $value, array $data, ?array $existing, bool $sent): ?string
  {
    $length = $field->length ?? FieldType::DEFAULT_LENGTH;
    if (null === $value && null !== $field->slugSource && (null === $existing || $sent)) {
      $source = array_key_exists($field->slugSource, $data) ? $data[$field->slugSource] : ($existing[$field->slugSource] ?? null);
      $value = is_scalar($source) ? Slug::make((string)$source, $length) : '';
      $value = '' !== $value ? $value : null;
    }
    if (null === $value) {
      return null;
    }
    // Unchanged slugs stay as they are - unless the record moves to another level of the tree
    if (null !== $existing && $value === ($existing[$field->name] ?? null) && !$this->parentChanged($entity, $data, $existing)) {
      return $value;
    }
    return Slug::unique($value, $this->records->takenSlugs($entity, $field->name, $value, $existing['id'] ?? null, $this->slugScope($entity, $data, $existing)), $length);
  }

  /**
   * @param array|null $existing current row (update) or null (create)
   * @return array<string, mixed> column => converted value, only for the given fields
   */
  private function validate(EntityDefinition $entity, array $data, ?array $existing): array
  {
    $errors = [];
    $values = [];

    // Drafts: "draft": true/false, unchanged when not sent - new records are published unless sent as draft
    $this->draft = false;
    if ($entity->drafts) {
      $this->draft = array_key_exists(EntityDefinition::DRAFT, $data)
        ? (bool)filter_var($data[EntityDefinition::DRAFT], FILTER_VALIDATE_BOOL)
        : RecordRepository::isDraft($entity, $existing ?? []);
      if (null === $existing || array_key_exists(EntityDefinition::DRAFT, $data)) {
        $values[EntityDefinition::DRAFT] = $this->draft;
      }
      // Publishing checks the required fields that were not sent as well
      if (null !== $existing && !$this->draft && !$this->workingCopy && RecordRepository::isDraft($entity, $existing)) {
        foreach ($entity->fields as $field) {
          if ($field->required && !array_key_exists($field->name, $data) && in_array($existing[$field->name] ?? null, [null, '', '[]', '{}'], true)) {
            $errors[$field->name][] = I18n::t('Please fill in.');
          }
        }
      }
    }

    if ($this->workingCopy) {
      $this->draft = true;
    }

    foreach ($data as $name => $raw) {
      if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at', '_refs', '_i18n', '_path', '_children'], true) || ($entity->drafts && EntityDefinition::DRAFT === $name)) {
        continue;
      }
      if (null === $entity->field((string)$name)) {
        $errors[(string)$name][] = I18n::t('This field does not exist.');
      }
    }

    foreach ($entity->fields as $field) {
      $sent = array_key_exists($field->name, $data);
      if (!$sent && null !== $existing) {
        continue;
      }
      $raw = $sent ? $data[$field->name] : null;
      if (FieldType::Group === $field->type) {
        $problems = [];
        $values[$field->name] = $this->groupStorage($field, $raw, $problems);
        if ([] !== $problems) {
          $errors[$field->name] = $problems;
        }
        continue;
      }
      if ($field->repeatable) {
        $problems = [];
        $values[$field->name] = $this->repeatList($entity, $field, $raw, $problems);
        if ([] !== $problems) {
          $errors[$field->name] = $problems;
        }
        continue;
      }
      // Media fields come back as the file object the API sent out
      if (FieldType::Media === $field->type && is_array($raw)) {
        $raw = $raw['id'] ?? null;
      }
      try {
        // "{{url}}/impressum" is checked with the project's values and stored as typed
        $value = $this->variables->toStorage($field, $raw);
      } catch (InvalidValueException $e) {
        $errors[$field->name][] = $e->getMessage();
        continue;
      }
      // New records: empty UUID fields get a new UUID, empty counters the next number of the database
      if (null === $value && null === $existing) {
        $value = $field->generateValue();
      }
      if (FieldType::Slug === $field->type) {
        $value = $this->slug($entity, $field, $value, $data, $existing, $sent);
      }
      if (null === $value && $field->type->isGenerated()) {
        continue;
      }

      if (null === $value) {
        if ($field->required && !$this->draft) {
          $errors[$field->name][] = I18n::t('Please fill in.');
        }
        $values[$field->name] = null;
        continue;
      }

      if (FieldType::Reference === $field->type) {
        $target = $this->entityRepository->findById((string)$field->referenceEntityId);
        if (null === $target || null === $this->records->find($target, (string)$value)) {
          $errors[$field->name][] = I18n::t('The chosen record does not exist.');
          continue;
        }
        // New records have no children yet, so only changes can close a circle
        if (null !== $existing && $field->name === $entity->treeField()?->name) {
          $problem = $this->tree->invalidParent($entity, (string)$existing['id'], (string)$value);
          if (null !== $problem) {
            $errors[$field->name][] = $problem;
            continue;
          }
        }
      }
      if (FieldType::Media === $field->type) {
        $file = $this->media->find((string)$value);
        if (null === $file) {
          $errors[$field->name][] = I18n::t('This file does not exist (any more). Please upload it again.');
          continue;
        }
        if (!MediaService::accepts($field->mediaAccept(), (string)$file['mime_type'])) {
          $errors[$field->name][] = I18n::t('Only these file types are allowed here: {types}.', ['types' => MediaService::describeAccept($field->mediaAccept())]);
          continue;
        }
      }
      // Slugs are made unique above
      if ($field->unique && FieldType::Slug !== $field->type && $this->records->exists($entity, [$field->name => $value], $existing['id'] ?? null)) {
        $errors[$field->name][] = I18n::t('Another record uses this value already.');
        continue;
      }
      // The unique index counts records in the trash too
      if ($field->unique && FieldType::Slug !== $field->type && $entity->trash && $this->records->exists($entity, [$field->name => $value], $existing['id'] ?? null, withTrashed: true)) {
        $errors[$field->name][] = I18n::t('This value belongs to a record in the trash - restore it or delete it for good.');
        continue;
      }
      $values[$field->name] = $value;
    }

    $this->validateTranslations($entity, $data, $existing, $values, $errors);
    if (null !== $existing) {
      $this->moveSlugs($entity, $data, $existing, $values);
    }

    if ([] === $errors) {
      foreach ($entity->uniqueTogether as $set) {
        if ([] === array_intersect($set, array_keys($values))) {
          continue;
        }
        $where = [];
        foreach ($set as $name) {
          $where[$name] = array_key_exists($name, $values) ? $values[$name] : ($existing[$name] ?? null);
        }
        if (!in_array(null, $where, true) && $this->records->exists($entity, $where, $existing['id'] ?? null, withTrashed: true)) {
          $labels = array_map(static fn(string $n): string => $entity->field($n)?->label ?? $n, $set);
          $errors[$set[0]][] = I18n::t('This combination of {fields} exists already.', ['fields' => implode(' + ', $labels)]);
        }
      }
    }

    // Fields limited to roles: changing them needs one of the roles. Unchanged values may be sent
    // (the admin app and SDKs send whole records).
    if (null !== $this->fieldAccess) {
      foreach ($entity->fields as $field) {
        if ($this->fieldAccess->canWrite($field)) {
          continue;
        }
        $columns = array_merge([$field->name], array_map(static fn(string $code): string => $field->translationColumn($code), $field->translatable ? $entity->otherLanguages() : []));
        foreach ($columns as $column) {
          if (array_key_exists($column, $values) && self::comparable($values[$column]) !== self::comparable($existing[$column] ?? null)) {
            $errors[$field->name][] = I18n::t('You may not change this field.');
            break;
          }
        }
      }
    }

    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    return $values;
  }

  private static function comparable(mixed $value): string
  {
    return match (true) {
      null === $value => '',
      is_bool($value) => $value ? '1' : '0',
      // BIT columns can come back as a byte
      "\x00" === $value => '0',
      "\x01" === $value => '1',
      default => (string)$value,
    };
  }
}
