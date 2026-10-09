<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Application\Content\TreeService;
use App\Application\Service\CurrentProject;
use App\Application\Media\MediaService;
use App\Domain\Schema\Access;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\CustomFieldTypes;
use App\Domain\Schema\FieldGroup;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\InvalidValueException;
use App\Domain\Schema\Slug;
use App\Domain\Schema\ValueConverter;
use App\Repository\EntityRepository;
use App\Repository\RecordRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;
use App\Shared\Naming;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder as C;

/**
 * Creates and changes entities and their fields - the definitions in `entity`/`entity_field` and
 * the content table _<project prefix><slug> together. Replaces the code generation of the Yii2 version (migration
 * + ActiveRecord class + relations.php per import): nothing is written to the code base any more.
 *
 * MySQL commits DDL statements implicitly, so the order is: validate everything, change the table,
 * then store the definitions - and undo the table change if storing fails.
 *
 * Index names are derived from the field id (uq_<id>, fk_<id>) so renaming a field or an entity
 * never touches them.
 */
final class SchemaService
{
  private const SLUG_MAX = 40;
  private const RESERVED_SLUGS = ['entities', 'schema', 'admin', 'auth', 'oauth', 'content', 'imports', 'media', 'variables', 'projects'];

  public function __construct(
    private ConnectionInterface $db,
    private EntityRepository $entityRepository,
    private RecordRepository $recordRepository,
    private TreeService $tree,
    private CurrentProject $currentProject,
    private ?\App\Application\Access\AccessControl $access = null,
    private ?\App\Application\Search\SearchIndex $search = null,
  ) {
  }

  /**
   * @param array{slug?: string, name?: string, description?: ?string, access?: string, label_field?: ?string, unique_together?: array, fields?: array} $data
   */
  public function createEntity(array $data): EntityDefinition
  {
    $entity = $this->prepareEntity($data);

    $this->createTable($entity);
    try {
      $this->db->transaction(fn() => $this->entityRepository->insert($entity));
    } catch (Throwable $e) {
      $this->db->createCommand()->dropTable($entity->tableName(), ifExists: true)->execute();
      throw $e;
    }

    $created = $this->get($entity->id);
    // A new entity has its search index from the start
    $this->search?->entityCreated($created);
    return $created;
  }

  /**
   * Validates a new entity without creating it (import preview).
   *
   * @throws ValidationException
   */
  public function prepareEntity(array $data): EntityDefinition
  {
    $errors = [];
    $slug = trim((string)($data['slug'] ?? ''));
    $this->validateSlug($slug, null, $errors);

    $entity = new EntityDefinition(
      id: Id::new(),
      slug: $slug,
      name: trim((string)($data['name'] ?? '')),
      description: $this->nullableText($data['description'] ?? null),
      access: $this->access($data['access'] ?? Access::Public->value, $errors),
      sortOrder: $this->entityRepository->nextSortOrder(),
      trash: (bool)filter_var($data['trash'] ?? false, FILTER_VALIDATE_BOOL),
      drafts: (bool)filter_var($data['drafts'] ?? false, FILTER_VALIDATE_BOOL),
      revisions: (bool)filter_var($data['revisions'] ?? false, FILTER_VALIDATE_BOOL),
      projectId: $this->currentProject->get()->id,
      tablePrefix: $this->currentProject->get()->tablePrefix,
      languages: $this->currentProject->get()->languages,
    );
    $this->validateName($entity->name, $errors);

    $fields = [];
    foreach (array_values((array)($data['fields'] ?? [])) as $index => $fieldData) {
      $field = $this->buildField((array)$fieldData, $entity, null, $errors, "fields.{$index}");
      if (null !== $field) {
        $field->sortOrder = $index;
        if (isset($fields[$field->name])) {
          $errors["fields.{$index}.name"][] = I18n::t('The field "{field}" is there twice.', ['field' => $field->name]);
        }
        $fields[$field->name] = $field;
      }
    }
    if ([] === $fields && [] === $errors) {
      $errors['fields'][] = I18n::t('An entity needs at least one field.');
    }
    $counters = array_keys(array_filter(array_values($fields), static fn(FieldDefinition $f): bool => $f->type->isGenerated()));
    if (count($counters) > 1) {
      $errors["fields.{$counters[1]}.type"][] = I18n::t('An entity can only have one counter.');
    }
    $orders = array_keys(array_filter(array_values($fields), static fn(FieldDefinition $f): bool => FieldType::Order === $f->type));
    if (count($orders) > 1) {
      $errors["fields.{$orders[1]}.type"][] = I18n::t('An entity can only have one order field.');
    }
    $uuids = array_keys(array_filter(array_values($fields), static fn(FieldDefinition $f): bool => FieldType::Uuid === $f->type));
    if (count($uuids) > 1) {
      $errors["fields.{$uuids[1]}.type"][] = I18n::t('An entity can only have one UUID field.');
    }
    $entity->fields = array_values($fields);
    foreach ($entity->fields as $index => $field) {
      $this->validateSlugSource($entity, $field, $errors, "fields.{$index}.slug_source");
    }
    $entity->labelField = $this->labelField($data['label_field'] ?? null, $entity, $errors);
    $entity->uniqueTogether = $this->uniqueTogether($data['unique_together'] ?? [], $entity, $errors);
    $entity->treeField = $this->treeField($data['tree_field'] ?? null, $entity, $errors);
    $entity->previewUrl = $this->previewUrl($data['preview_url'] ?? null, $errors);
    $entity->tabs = $this->tabs($data['tabs'] ?? null, $entity, $errors);

    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    return $entity;
  }

  /**
   * Validates a new field of an existing entity without adding it (import preview).
   *
   * @throws ValidationException
   */
  public function prepareField(EntityDefinition $entity, array $data, string $path = ''): FieldDefinition
  {
    $errors = [];
    $field = $this->buildField($data, $entity, null, $errors, $path);
    if (null !== $field && null !== $entity->field($field->name)) {
      $errors['' === $path ? 'name' : "{$path}.name"][] = I18n::t('The field "{field}" exists already.', ['field' => $field->name]);
    }
    if (null !== $field) {
      $this->validateSlugSource($entity, $field, $errors, '' === $path ? 'slug_source' : "{$path}.slug_source");
    }
    if (null === $field || [] !== $errors) {
      throw new ValidationException($errors);
    }
    return $field;
  }

  /**
   * A field of a field group (GroupService): the same checks as for entity fields, plus the rules
   * of groups. No table changes - group values are JSON in the entities' group fields.
   *
   * @throws ValidationException
   */
  public function buildGroupField(FieldGroup $group, array $data, ?FieldDefinition $existing = null): FieldDefinition
  {
    $errors = [];
    // Stand-in entity: references and slug sources are checked against the group's fields
    $context = new EntityDefinition(id: 'group:'.$group->id, slug: '', name: $group->label, fields: $group->fields, projectId: $group->projectId, tablePrefix: '-');
    $merged = null !== $existing ? $data + $existing->toRow() + ['unique' => false, 'reference' => $existing->referenceEntityId, 'group' => $existing->fieldGroupId] : $data;
    $field = $this->buildField($merged, $context, $existing, $errors, '', $group);
    if (null !== $field && $field->name !== $existing?->name && null !== $group->field($field->name)) {
      $errors['name'][] = I18n::t('The field "{field}" exists already.', ['field' => $field->name]);
    }
    if (null === $field || [] !== $errors) {
      throw new ValidationException($errors);
    }
    return $field;
  }

  public function get(string $idOrSlug): EntityDefinition
  {
    return $this->entityRepository->findById($idOrSlug)
      ?? $this->entityRepository->findBySlug($idOrSlug)
      ?? throw UserFacingException::notFound(I18n::t('This entity does not exist.'));
  }

  /**
   * An entity whose schema may be changed here: global entities only in the area "Global".
   */
  private function owned(string $idOrSlug): EntityDefinition
  {
    $entity = $this->get($idOrSlug);
    if ($entity->global && $entity->projectId !== $this->currentProject->id()) {
      throw UserFacingException::forbidden(I18n::t('"{entity}" is global - its schema is edited in the area "Global".', ['entity' => $entity->name]));
    }
    return $entity;
  }

  /**
   * Name, description, access, label field, order, combined unique indexes and the slug (renames
   * the table - API consumers must use the new name).
   */
  public function updateEntity(string $id, array $data): EntityDefinition
  {
    $entity = $this->owned($id);
    $errors = [];
    $oldTable = $entity->tableName();
    $oldUnique = $entity->uniqueTogether;
    $oldTrash = $entity->trash;
    $oldDrafts = $entity->drafts;
    $oldTree = $entity->treeField;
    // Counted before anything changes: $entity is the cached definition
    $trashed = $this->recordRepository->countTrashed($entity);

    if (array_key_exists('slug', $data)) {
      $slug = trim((string)$data['slug']);
      if (null !== $entity->managedBy && $slug !== $entity->slug) {
        // The plugin finds it by its slug
        $errors['slug'][] = I18n::t('The plugin "{plugin}" manages this entity - its slug cannot change.', ['plugin' => $entity->managedBy]);
      } else {
        $this->validateSlug($slug, $entity, $errors);
        $entity->slug = $slug;
      }
    }
    if (array_key_exists('name', $data)) {
      $entity->name = trim((string)$data['name']);
      $this->validateName($entity->name, $errors);
    }
    if (array_key_exists('description', $data)) {
      $entity->description = $this->nullableText($data['description']);
    }
    if (array_key_exists('access', $data)) {
      $entity->access = $this->access($data['access'], $errors);
    }
    if (array_key_exists('label_field', $data)) {
      $entity->labelField = $this->labelField($data['label_field'], $entity, $errors);
    }
    if (array_key_exists('sort_order', $data)) {
      $entity->sortOrder = (int)$data['sort_order'];
    }
    if (array_key_exists('unique_together', $data)) {
      $entity->uniqueTogether = $this->uniqueTogether($data['unique_together'], $entity, $errors);
    }
    if (array_key_exists('tree_field', $data)) {
      $entity->treeField = $this->treeField($data['tree_field'], $entity, $errors);
      // Existing data must already be a tree
      if (null !== $entity->treeField && [] === $errors) {
        try {
          $this->tree->assertNoCycles($entity);
        } catch (ValidationException $e) {
          $errors['tree_field'] = $e->getErrors()['tree'] ?? [I18n::t('The existing records do not form a tree.')];
        }
      }
    }
    if (array_key_exists('preview_url', $data)) {
      $entity->previewUrl = $this->previewUrl($data['preview_url'], $errors);
    }
    if (array_key_exists('tabs', $data)) {
      $entity->tabs = $this->tabs($data['tabs'], $entity, $errors);
    }
    if (array_key_exists('revisions', $data)) {
      // Off: no new revisions - the ones there are stay readable
      $entity->revisions = (bool)filter_var($data['revisions'], FILTER_VALIDATE_BOOL);
    }
    if (array_key_exists('drafts', $data)) {
      $entity->drafts = (bool)filter_var($data['drafts'], FILTER_VALIDATE_BOOL);
      if ($entity->drafts && !$oldDrafts && null !== $entity->field(EntityDefinition::DRAFT)) {
        $errors['drafts'][] = I18n::t('The field "{field}" must be renamed first - drafts need this name.', ['field' => EntityDefinition::DRAFT]);
      }
      $drafts = !$entity->drafts && $oldDrafts ? $this->recordRepository->countDrafts($entity) : 0;
      if ($drafts > 0) {
        $errors['drafts'][] = I18n::t('There are still {count, plural, one{# draft} other{# drafts}}. Please publish or delete them first.', ['count' => $drafts]);
      }
    }
    if (array_key_exists('trash', $data)) {
      $entity->trash = (bool)filter_var($data['trash'], FILTER_VALIDATE_BOOL);
      if ($entity->trash && !$oldTrash && null !== $entity->field(EntityDefinition::DELETED_AT)) {
        $errors['trash'][] = I18n::t('The field "{field}" must be renamed first - the trash needs this name.', ['field' => EntityDefinition::DELETED_AT]);
      }
      if (!$entity->trash && $oldTrash) {
        if ($trashed > 0) {
          $errors['trash'][] = I18n::t('There are still {count, plural, one{# record} other{# records}} in the trash. Please restore them or delete them for good first.', ['count' => $trashed]);
        }
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    $treeChanged = array_key_exists('tree_field', $data) && $entity->treeField !== $oldTree;
    if ($treeChanged) {
      $this->assertSlugsFitTree($entity, $oldTable);
    }

    if ($oldTable !== $entity->tableName()) {
      $this->db->createCommand()->renameTable($oldTable, $entity->tableName())->execute();
    }
    if ($entity->drafts !== $oldDrafts) {
      $entity->drafts
        ? $this->addDraftColumn($entity)
        : $this->db->createCommand()->dropColumn($entity->tableName(), EntityDefinition::DRAFT)->execute();
    }
    if ($entity->trash !== $oldTrash) {
      if ($entity->trash) {
        $this->addTrashColumn($entity);
      } else {
        $this->db->createCommand()->dropColumn($entity->tableName(), EntityDefinition::DELETED_AT)->execute();
        $this->db->createCommand()->dropColumn($entity->tableName(), EntityDefinition::DELETED_BY)->execute();
      }
    }
    $this->syncUniqueTogether($entity, $oldUnique, $entity->uniqueTogether);
    if ($treeChanged) {
      $this->syncSlugIndexes($entity, $entity->tableName());
    }
    $this->entityRepository->update($entity);

    return $this->get($entity->id);
  }

  /**
   * @param list<string> $ids Entities in the wanted order (navigation, dashboard)
   */
  public function reorderEntities(array $ids): void
  {
    foreach (array_values($ids) as $position => $id) {
      $entity = $this->entityRepository->findById((string)$id);
      if (null !== $entity) {
        $entity->sortOrder = $position;
        $this->entityRepository->update($entity);
      }
    }
  }

  public function deleteEntity(string $id): void
  {
    $entity = $this->owned($id);
    if (null !== $entity->managedBy) {
      throw UserFacingException::conflict(I18n::t('The plugin "{plugin}" manages this entity - uninstall the plugin to delete it.', ['plugin' => $entity->managedBy]), 'entity_managed');
    }
    $usedBy = array_filter($this->entityRepository->referencesTo($entity->id), static fn(array $ref): bool => $ref['entity']->id !== $entity->id);
    if ([] !== $usedBy) {
      $names = array_map(static fn(array $ref): string => sprintf('%s → %s', $ref['entity']->name, $ref['field']->label), $usedBy);
      throw UserFacingException::conflict(I18n::t('"{entity}" is still in use: {fields}. Please delete these fields first.', ['entity' => $entity->name, 'fields' => implode(', ', $names)]), 'entity_in_use');
    }

    // Self references first, otherwise the table cannot be dropped
    foreach ($entity->fields as $field) {
      if (FieldType::Reference === $field->type) {
        $this->dropForeignKey($entity, $field);
      }
    }
    $this->db->createCommand()->dropTable($entity->tableName(), ifExists: true)->execute();
    $this->entityRepository->delete($entity->id);
    // Its permissions in roles and of users and clients
    $this->access?->removeEntity($entity->id);
  }

  public function addField(string $entityId, array $data): FieldDefinition
  {
    $entity = $this->owned($entityId);
    $field = $this->prepareField($entity, $data);
    $field->sortOrder = count($entity->fields);

    $this->db->createCommand()->addColumn($entity->tableName(), $field->name, $field->column())->execute();
    try {
      $this->addConstraints($entity, $field);
      $this->addTranslations($entity, $field, $entity->otherLanguages());
      $this->entityRepository->insertField($field);
    } catch (Throwable $e) {
      $this->dropForeignKey($entity, $field);
      $this->dropTranslations($entity, $field, $entity->otherLanguages());
      $this->db->createCommand()->dropColumn($entity->tableName(), $field->name)->execute();
      throw $e;
    }

    return $this->get($entity->id)->field($field->name) ?? $field;
  }

  /**
   * Changes a field. A new type is applied to the existing records: every value is converted
   * (e.g. "29.10.2025" -> date); if any value does not fit, nothing is changed and the error names
   * the records. For the type "reference", `match` names the field of the target entity the
   * current values are looked up in (default: id).
   */
  public function updateField(string $entityId, string $fieldId, array $data): FieldDefinition
  {
    $entity = $this->owned($entityId);
    $old = $this->findField($entity, $fieldId);
    $errors = [];
    $new = $this->buildField($data + $old->toRow() + ['unique' => $old->unique, 'reference' => $old->referenceEntityId, 'group' => $old->fieldGroupId], $entity, $old, $errors, '');
    if (null !== $new && $new->name !== $old->name && null !== $entity->field($new->name)) {
      $errors['name'][] = I18n::t('The field "{field}" exists already.', ['field' => $new->name]);
    }
    if (null !== $new) {
      $this->validateSlugSource($entity, $new, $errors, 'slug_source');
    }
    if (null !== $new) {
      self::assertUnlocked($old, $new, $errors);
    }
    if (null !== $new && $new->type !== $old->type && ($old->repeatable || $new->repeatable)) {
      $errors['type'][] = I18n::t('The type of a repeatable field cannot be changed. Switch off "Repeatable" first.');
    }
    if (null !== $new && $new->repeatable !== $old->repeatable && ($old->translatable || $new->translatable) && [] !== $entity->otherLanguages()) {
      $errors['repeatable'][] = I18n::t('"Repeatable" cannot be switched for translatable fields. Switch off "Translatable" first.');
    }
    if (null !== $new && $new->type !== $old->type && ($old->translatable || $new->translatable)) {
      $errors['type'][] = I18n::t('The type of a translatable field cannot be changed. Switch off "Translatable" first.');
    }
    if (null !== $new && ($new->type !== $old->type || $new->customType !== $old->customType) && (FieldType::Custom === $new->type || FieldType::Custom === $old->type)) {
      $errors['type'][] = I18n::t('Fields of plugins cannot be converted into another type (and vice versa). Add a new field.');
    }
    if (null !== $new && $new->type !== $old->type && (FieldType::Group === $new->type || FieldType::Group === $old->type)) {
      $errors['type'][] = I18n::t('Group fields cannot be converted into another type (and vice versa). Add a new field.');
    }
    if (null !== $new && $new->isBlocks() !== $old->isBlocks() && FieldType::Group === $new->type && FieldType::Group === $old->type) {
      $errors['blocks'][] = I18n::t('A group field cannot become a blocks field (and vice versa). Add a new field.');
    } elseif (null !== $new && FieldType::Group === $new->type && FieldType::Group === $old->type && !$new->isBlocks() && $new->fieldGroupId !== $old->fieldGroupId) {
      $errors['group'][] = I18n::t('The group of a field cannot be changed. Add a new field.');
    }
    if (null !== $new && $new->type !== $old->type && (FieldType::Media === $new->type || FieldType::Media === $old->type)) {
      $errors['type'][] = I18n::t('Media fields cannot be converted into another type (and vice versa). Add a new field.');
    }
    if (null === $new || [] !== $errors) {
      throw new ValidationException($errors);
    }

    $table = $entity->tableName();
    // Fewer files allowed than records have: refuse instead of cutting lists
    // A new pattern must fit the values there are (all languages, every item of lists)
    if (FieldType::Regex === $new->type && FieldType::Regex === $old->type && $new->pattern !== $old->pattern && null !== $new->pattern) {
      $columns = array_merge([$old->name], $old->translatable ? array_map($old->translationColumn(...), $entity->otherLanguages()) : []);
      $failed = [];
      foreach ($columns as $column) {
        foreach ($this->recordRepository->column($entity, $column) as $row) {
          foreach ($old->repeatable ? FieldDefinition::decodeList($row[$column]) : [$row[$column]] as $value) {
            if (1 !== @preg_match(FieldDefinition::regex($new->pattern), (string)$value)) {
              $failed[] = (string)$value;
            }
          }
        }
      }
      if ([] !== $failed) {
        $examples = implode(', ', array_map(static fn(string $v): string => I18n::quote(mb_strimwidth($v, 0, 40, '…')), array_slice(array_unique($failed), 0, 3)));
        throw ValidationException::field('pattern', I18n::t('{count, plural, one{# existing value does} other{# existing values do}} not match the new pattern, e.g. {examples}.', ['count' => count($failed), 'examples' => $examples]));
      }
    }

    // Values taken out of an enum must not be in use (all languages, every item of lists)
    if (FieldType::Enum === $new->type && FieldType::Enum === $old->type) {
      $removed = array_diff($old->optionValues(), $new->optionValues());
      $used = [];
      foreach ([] !== $removed ? $this->recordRepository->column($entity, $old->name) : [] as $row) {
        foreach ($old->repeatable ? FieldDefinition::decodeList($row[$old->name]) : [$row[$old->name]] as $value) {
          if (in_array((string)$value, $removed, true)) {
            $used[(string)$value] = ($used[(string)$value] ?? 0) + 1;
          }
        }
      }
      if ([] !== $used) {
        $list = implode(', ', array_map(static fn(string $v, int $n): string => I18n::quote($v).' ('.$n.')', array_keys($used), $used));
        throw ValidationException::field('options', I18n::t('These values are still used: {values}. Please change the records first.', ['values' => $list]));
      }
    }

    // Lists longer than allowed (or than one value, when "repeatable" goes off): refuse instead of cutting
    $limit = $new->repeatable ? $new->repeatMax : 1;
    if ($old->repeatable && null !== $limit && ($limit !== $old->repeatMax || !$new->repeatable)) {
      $tooMany = 0;
      foreach ($this->recordRepository->column($entity, $old->name) as $row) {
        $tooMany += count(FieldDefinition::decodeList($row[$old->name])) > $limit ? 1 : 0;
      }
      if ($tooMany > 0) {
        throw ValidationException::field($new->repeatable ? 'repeat_max' : 'repeatable', I18n::t('{count, plural, one{# record has} other{# records have}} more than {limit, plural, one{one value} other{# values}}. Please remove values there first.', ['count' => $tooMany, 'limit' => $limit]));
      }
    }

    $columnChanged = $new->type !== $old->type
      || $new->repeatable !== $old->repeatable
      || ($new->type->hasLength() && $new->length !== $old->length)
      || ($new->type->hasScale() && $new->scale !== $old->scale)
      || (FieldType::Reference === $new->type && $new->referenceEntityId !== $old->referenceEntityId);

    if ($columnChanged) {
      $converted = $this->convertExistingValues($entity, $old, $new, isset($data['match']) ? (string)$data['match'] : null);
      $this->dropConstraints($entity, $old);
      $temporary = '__tmp_'.substr(md5($new->id), 0, 10);
      $this->db->createCommand()->addColumn($table, $temporary, $new->column())->execute();
      foreach ($converted as $id => $value) {
        $this->db->createCommand()->update($table, [$temporary => $value], ['id' => $id])->execute();
      }
      $this->db->createCommand()->dropColumn($table, $old->name)->execute();
      $this->db->createCommand()->renameColumn($table, $temporary, $new->name)->execute();
      $this->addConstraints($entity, $new);
    } else {
      if ($new->name !== $old->name) {
        $this->db->createCommand()->renameColumn($table, $old->name, $new->name)->execute();
      }
      if ($new->unique !== $old->unique) {
        if ($new->unique) {
          $this->assertNoDuplicates($entity, [$new->name], $new->label);
          $this->db->createCommand()->createIndex($table, self::uniqueIndexName($new), $new->name, 'UNIQUE')->execute();
        } else {
          $this->db->createCommand()->dropIndex($table, self::uniqueIndexName($old))->execute();
        }
      }
    }

    $this->updateTranslations($entity, $old, $new, $columnChanged);
    $this->entityRepository->updateField($new);
    if ($new->name !== $old->name) {
      $this->renameFieldInEntity($entity, $old->name, $new->name);
    }
    // A parent field that no longer points to the entity itself ends the tree
    $updated = $this->get($entity->id);
    if (null !== $updated->treeField && null === $updated->treeField()) {
      $updated->treeField = null;
      $this->assertSlugsFitTree($updated, $updated->tableName());
      $this->syncSlugIndexes($updated, $updated->tableName());
      $this->entityRepository->update($updated);
    }
    // What the search index holds of the field changed: rebuild it
    if ($old->isSearchable() !== $new->isSearchable() || $old->type !== $new->type || $old->translatable !== $new->translatable || $old->repeatable !== $new->repeatable) {
      $this->search?->markStale($entity);
    }

    return $this->findField($this->get($entity->id), $new->id);
  }

  public function deleteField(string $entityId, string $fieldId): void
  {
    $entity = $this->owned($entityId);
    $field = $this->findField($entity, $fieldId);
    if ($field->locked) {
      throw UserFacingException::conflict(I18n::t('A plugin relies on the field "{field}" - it cannot be deleted.', ['field' => $field->label]), 'field_locked');
    }
    if (1 === count($entity->fields)) {
      throw new UserFacingException(I18n::t('The last field cannot be deleted. Delete the entity instead.'));
    }

    // Combined unique indexes with this field go away with it
    $remaining = array_values(array_filter($entity->uniqueTogether, static fn(array $set): bool => !in_array($field->name, $set, true)));
    $this->syncUniqueTogether($entity, $entity->uniqueTogether, $remaining);
    $entity->uniqueTogether = $remaining;
    if ($entity->labelField === $field->name) {
      $entity->labelField = null;
    }
    if ($entity->treeField === $field->name) {
      // Slugs unique in the whole entity again - before the parent column (part of their indexes) goes
      $entity->treeField = null;
      $this->assertSlugsFitTree($entity, $entity->tableName());
      $this->syncSlugIndexes($entity, $entity->tableName());
    }
    foreach ($entity->fields as $other) {
      if ($other->slugSource === $field->name) {
        $other->slugSource = null;
        $this->entityRepository->updateField($other);
      }
    }
    $this->entityRepository->update($entity);

    $this->dropConstraints($entity, $field);
    $this->dropTranslations($entity, $field, $entity->otherLanguages());
    $this->db->createCommand()->dropColumn($entity->tableName(), $field->name)->execute();
    $this->entityRepository->deleteField($field->id);
    $this->search?->forgetField($field->id);
  }

  /**
   * @param list<string> $fieldIds
   */
  public function reorderFields(string $entityId, array $fieldIds): EntityDefinition
  {
    $entity = $this->owned($entityId);
    $position = array_flip(array_values(array_map('strval', $fieldIds)));
    foreach ($entity->fields as $field) {
      $field->sortOrder = $position[$field->id] ?? count($position) + $field->sortOrder;
      $this->entityRepository->updateField($field);
    }
    return $this->get($entity->id);
  }

  public static function uniqueIndexName(FieldDefinition $field): string
  {
    return 'uq_'.$field->id;
  }

  /**
   * Unique index of a slug column (uq_<field>, translations uq_<field>_<lang>). In trees slugs
   * are unique per level: (parent, slug) - the top level (parent NULL) is checked by RecordService.
   */
  private function createSlugIndex(EntityDefinition $entity, FieldDefinition $field, string $column, ?string $table = null): void
  {
    $table ??= $entity->tableName();
    $columns = $this->slugIndexColumns($entity, $field, $column);
    $this->assertNoDuplicates($entity, $columns, $field->label.($column !== $field->name ? ' ('.substr($column, strlen($field->name) + 2).')' : ''));
    $tree = $entity->treeField();
    if (null !== $tree && count($columns) > 1) {
      // MySQL silently drops the implicit index of the parent's foreign key once (parent, slug)
      // can serve it - and then refuses to drop (parent, slug). An index of our own prevents that.
      $parentIndex = 'ix_'.$tree->id;
      if (!in_array($parentIndex, array_map(static fn($index): string => $index->name, $this->db->getSchema()->getTableIndexes($table, true)), true)) {
        $this->db->createCommand()->createIndex($table, $parentIndex, $tree->name)->execute();
      }
    }
    $this->db->createCommand()->createIndex($table, self::slugIndexName($field, $column), $columns, 'UNIQUE')->execute();
  }

  /**
   * @return list<string>
   */
  private function slugIndexColumns(EntityDefinition $entity, FieldDefinition $field, string $column): array
  {
    $tree = $entity->treeField();
    return null !== $tree && $tree->name !== $field->name ? [$tree->name, $column] : [$column];
  }

  private static function slugIndexName(FieldDefinition $field, string $column): string
  {
    return $column === $field->name ? self::uniqueIndexName($field) : self::uniqueIndexName($field).'_'.substr($column, strlen($field->name) + 2);
  }

  /**
   * Slug columns of the entity: field and column (default language and translations).
   *
   * @return list<array{0: FieldDefinition, 1: string}>
   */
  private function slugColumns(EntityDefinition $entity, string $table): array
  {
    $schema = $this->db->getTableSchema($table, true);
    $result = [];
    foreach ($entity->fields as $field) {
      if (FieldType::Slug !== $field->type) {
        continue;
      }
      foreach ([$field->name, ...($field->translatable ? array_map($field->translationColumn(...), $entity->otherLanguages()) : [])] as $column) {
        if (null !== $schema?->getColumn($column)) {
          $result[] = [$field, $column];
        }
      }
    }
    return $result;
  }

  /**
   * The parent field changed (tree on, off or another field): slugs become unique per level or in
   * the whole entity. Leaving the tree fails clearly if two levels use the same slug.
   */
  private function assertSlugsFitTree(EntityDefinition $entity, string $table): void
  {
    foreach ($this->slugColumns($entity, $table) as [$field, $column]) {
      $columns = $this->slugIndexColumns($entity, $field, $column);
      if (1 === count($columns)) {
        $duplicate = $this->db->createQuery()->select($column)->from($table)->andWhere(['not', [$column => null]])->groupBy($column)->having('COUNT(*) > 1')->scalar();
        if (false !== $duplicate && null !== $duplicate) {
          throw ValidationException::field('tree_field', I18n::t('Without a tree, "{field}" must be unique in the whole entity - "{value}" is on several levels.', ['field' => $field->label, 'value' => (string)$duplicate]));
        }
      }
    }
  }

  /**
   * Existing trees of all projects (migration): slugs unique per level.
   */
  public function syncTreeSlugIndexes(): int
  {
    $count = 0;
    foreach ($this->entityRepository->allProjects() as $entity) {
      if (null !== $entity->treeField() && [] !== $this->slugColumns($entity, $entity->tableName())) {
        $this->syncSlugIndexes($entity, $entity->tableName());
        $count++;
      }
    }
    return $count;
  }

  private function syncSlugIndexes(EntityDefinition $entity, string $table): void
  {
    $existing = array_map(static fn($index): string => $index->name, $this->db->getSchema()->getTableIndexes($table, true));
    foreach ($this->slugColumns($entity, $table) as [$field, $column]) {
      if (in_array(self::slugIndexName($field, $column), $existing, true)) {
        $this->db->createCommand()->dropIndex($table, self::slugIndexName($field, $column))->execute();
      }
      $this->createSlugIndex($entity, $field, $column, $table);
    }
  }

  public static function foreignKeyName(FieldDefinition $field): string
  {
    return 'fk_'.$field->id;
  }

  /**
   * @param list<string> $fields
   */
  public static function uniqueTogetherIndexName(EntityDefinition $entity, array $fields): string
  {
    return 'uqt_'.substr(md5($entity->id.':'.implode(',', $fields)), 0, 20);
  }

  private function createTable(EntityDefinition $entity): void
  {
    $columns = ['id' => C::string(22)->primaryKey()];
    foreach ($entity->fields as $field) {
      $columns[$field->name] = $field->column();
      foreach ($field->translatable ? $entity->otherLanguages() : [] as $language) {
        $columns[$field->translationColumn($language)] = $field->column();
      }
    }
    $columns['created_at'] = C::datetime()->notNull();
    $columns['updated_at'] = C::datetime()->null();
    // Who created / last changed the record ("user:<id>" or "client:<id>")
    $columns[EntityDefinition::CREATED_BY] = C::string(40)->null();
    $columns[EntityDefinition::UPDATED_BY] = C::string(40)->null();

    $this->db->createCommand()->createTable($entity->tableName(), $columns, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
    try {
      if ($entity->trash) {
        $this->addTrashColumn($entity);
      }
      if ($entity->drafts) {
        $this->addDraftColumn($entity);
      }
      $this->db->createCommand()->createIndex($entity->tableName(), 'idx_'.substr($entity->id, 0, 20).'_created', 'created_at')->execute();
      foreach ($entity->fields as $field) {
        $this->addConstraints($entity, $field);
        $this->addTranslationConstraints($entity, $field, $entity->otherLanguages());
      }
      $this->syncUniqueTogether($entity, [], $entity->uniqueTogether);
    } catch (Throwable $e) {
      $this->db->createCommand()->dropTable($entity->tableName(), ifExists: true)->execute();
      throw $e;
    }
  }

  /**
   * Columns <field>__<language> of a translatable field, with the unique index of slugs (slugs are
   * unique per language) and slugs made from the source field in the same language.
   *
   * @param list<string> $languages
   */
  private function addTranslations(EntityDefinition $entity, FieldDefinition $field, array $languages): void
  {
    if (!$field->translatable) {
      return;
    }
    foreach ($languages as $language) {
      $this->db->createCommand()->addColumn($entity->tableName(), $field->translationColumn($language), $field->column())->execute();
    }
    $this->addTranslationConstraints($entity, $field, $languages);
  }

  /**
   * @param list<string> $languages
   */
  private function addTranslationConstraints(EntityDefinition $entity, FieldDefinition $field, array $languages): void
  {
    if (!$field->translatable || FieldType::Slug !== $field->type) {
      return;
    }
    foreach ($languages as $language) {
      $this->fillEmptySlugs($entity, $field, $language);
      $this->createSlugIndex($entity, $field, $field->translationColumn($language));
    }
  }

  /**
   * @param list<string> $languages
   */
  private function dropTranslations(EntityDefinition $entity, FieldDefinition $field, array $languages): void
  {
    if (!$field->translatable) {
      return;
    }
    $table = $this->db->getTableSchema($entity->tableName(), true);
    foreach ($languages as $language) {
      // Indexes go with their column
      if (null !== $table?->getColumn($field->translationColumn($language))) {
        $this->db->createCommand()->dropColumn($entity->tableName(), $field->translationColumn($language))->execute();
      }
    }
  }

  /**
   * After a field changed: translation columns follow its name, its length, and "translatable".
   */
  private function updateTranslations(EntityDefinition $entity, FieldDefinition $old, FieldDefinition $new, bool $columnChanged): void
  {
    $languages = $entity->otherLanguages();
    if ([] === $languages || (!$old->translatable && !$new->translatable)) {
      return;
    }
    if ($old->translatable && !$new->translatable) {
      $this->dropTranslations($entity, $old, $languages);
      return;
    }
    if (!$old->translatable && $new->translatable) {
      $this->addTranslations($entity, $new, $languages);
      return;
    }
    foreach ($languages as $language) {
      if ($old->name !== $new->name) {
        $this->db->createCommand()->renameColumn($entity->tableName(), $old->translationColumn($language), $new->translationColumn($language))->execute();
      }
      if ($columnChanged) {
        $column = $new->translationColumn($language);
        foreach ($this->recordRepository->column($entity, $column) as $row) {
          ValueConverter::toStorage($new, $row[$column]);
        }
        $this->db->createCommand()->alterColumn($entity->tableName(), $column, $new->column())->execute();
      }
    }
  }

  /**
   * The languages of a project changed (ProjectService): translation columns of all its entities
   * are added and removed; a new default language swaps places with the old one (its texts move
   * into the fields' own columns).
   *
   * @param list<EntityDefinition> $entities of the project
   * @param list<string> $old
   * @param list<string> $new
   */
  public function changeLanguages(array $entities, array $old, array $new): void
  {
    // Translations move: the search indexes are rebuilt
    foreach ($entities as $changed) {
      $this->search?->markStale($changed);
    }
    $oldDefault = $old[0] ?? null;
    $newDefault = $new[0] ?? null;
    $swap = null !== $oldDefault && null !== $newDefault && $oldDefault !== $newDefault;
    if ($swap && !in_array($newDefault, $old, true)) {
      throw ValidationException::field('languages', I18n::t('The new default language must belong to the project already. Please add it and save first.'));
    }
    $oldOthers = array_slice($old, 1);
    $newOthers = array_slice($new, 1);

    foreach ($entities as $entity) {
      foreach ($entity->translatableFields() as $field) {
        $table = $entity->tableName();
        if ($swap) {
          $keepOld = in_array($oldDefault, $new, true);
          $slug = FieldType::Slug === $field->type;
          if ($keepOld) {
            $this->db->createCommand()->addColumn($table, $field->translationColumn($oldDefault), $field->column())->execute();
          }
          if ($slug) {
            $this->db->createCommand()->dropIndex($table, self::uniqueIndexName($field))->execute();
          }
          // MySQL assigns from left to right with the new values: save the old default text first
          $columns = [];
          if ($keepOld) {
            $columns[$field->translationColumn($oldDefault)] = new \Yiisoft\Db\Expression\Expression($this->db->getQuoter()->quoteColumnName($field->name));
          }
          $columns[$field->name] = new \Yiisoft\Db\Expression\Expression($this->db->getQuoter()->quoteColumnName($field->translationColumn($newDefault)));
          $this->db->createCommand()->update($table, $columns, '1=1')->execute();
          $this->db->createCommand()->dropColumn($table, $field->translationColumn($newDefault))->execute();
          if ($slug) {
            $this->createSlugIndex($entity, $field, $field->name);
            if ($keepOld) {
              $this->addTranslationConstraints($entity, $field, [$oldDefault]);
            }
          }
        }
        $existing = array_values(array_diff($swap ? array_merge(array_diff($oldOthers, [$newDefault]), in_array($oldDefault, $new, true) ? [$oldDefault] : []) : $oldOthers, []));
        $this->dropTranslations($entity, $field, array_values(array_diff($existing, $newOthers)));
        $this->addTranslations($entity, $field, array_values(array_diff($newOthers, $existing)));
      }
    }
    $this->entityRepository->reset();
  }

  private function addDraftColumn(EntityDefinition $entity): void
  {
    // Existing records are published; the index goes with the column when drafts are switched off
    $this->db->createCommand()->addColumn($entity->tableName(), EntityDefinition::DRAFT, C::boolean()->notNull()->defaultValue(false))->execute();
    $this->db->createCommand()->createIndex($entity->tableName(), 'idx_'.substr($entity->id, 0, 20).'_draft', EntityDefinition::DRAFT)->execute();
  }

  private function addTrashColumn(EntityDefinition $entity): void
  {
    // The index goes with the column when the trash is switched off again
    $this->db->createCommand()->addColumn($entity->tableName(), EntityDefinition::DELETED_AT, C::datetime()->null())->execute();
    $this->db->createCommand()->addColumn($entity->tableName(), EntityDefinition::DELETED_BY, C::string(40)->null())->execute();
    $this->db->createCommand()->createIndex($entity->tableName(), 'idx_'.substr($entity->id, 0, 20).'_deleted', EntityDefinition::DELETED_AT)->execute();
  }

  private function addConstraints(EntityDefinition $entity, FieldDefinition $field): void
  {
    if ($field->type->isGenerated()) {
      $this->makeAutoIncrement($entity, $field);
      return;
    }
    if (FieldType::Uuid === $field->type) {
      $this->fillEmptyUuids($entity, $field);
    }
    if (FieldType::Slug === $field->type) {
      $this->fillEmptySlugs($entity, $field);
    }
    if (FieldType::Slug === $field->type) {
      $this->createSlugIndex($entity, $field, $field->name);
    } elseif ($field->unique) {
      $this->assertNoDuplicates($entity, [$field->name], $field->label);
      $this->db->createCommand()->createIndex($entity->tableName(), self::uniqueIndexName($field), $field->name, 'UNIQUE')->execute();
    }
    // Lists have no foreign key - their references are checked by the application
    if (FieldType::Reference === $field->type && null !== $field->referenceEntityId && !$field->repeatable) {
      $target = $field->referenceEntityId === $entity->id ? $entity : $this->entityRepository->findById($field->referenceEntityId);
      if (null !== $target) {
        // A referenced record cannot be deleted while it is in use
        $this->db->createCommand()->addForeignKey($entity->tableName(), self::foreignKeyName($field), $field->name, $target->tableName(), 'id', 'RESTRICT', 'CASCADE')->execute();
      }
    }
    if (FieldType::Media === $field->type && !$field->isMultipleMedia()) {
      // Files in use are never removed by the cleanup (lists of files are checked by MediaRepository)
      $this->db->createCommand()->addForeignKey($entity->tableName(), self::foreignKeyName($field), $field->name, 'media', 'id', 'RESTRICT', 'CASCADE')->execute();
    }
  }

  private function dropConstraints(EntityDefinition $entity, FieldDefinition $field): void
  {
    // The index of an AUTO_INCREMENT column cannot be dropped on its own - it goes with the column
    if ($field->unique && !$field->type->isGenerated()) {
      $this->db->createCommand()->dropIndex($entity->tableName(), self::uniqueIndexName($field))->execute();
    }
    $this->dropForeignKey($entity, $field);
  }

  private function dropForeignKey(EntityDefinition $entity, FieldDefinition $field): void
  {
    if (!$field->type->hasForeignKey()) {
      return;
    }
    $exists = $this->db->createQuery()->from('information_schema.TABLE_CONSTRAINTS')->where([
      'CONSTRAINT_SCHEMA' => new \Yiisoft\Db\Expression\Expression('DATABASE()'),
      'TABLE_NAME' => $entity->tableName(),
      'CONSTRAINT_NAME' => self::foreignKeyName($field),
    ])->exists();
    if ($exists) {
      $this->db->createCommand()->dropForeignKey($entity->tableName(), self::foreignKeyName($field))->execute();
      // MySQL keeps the index of the foreign key
      $this->db->createCommand()->dropIndex($entity->tableName(), self::foreignKeyName($field))->execute();
    }
  }

  /**
   * Turns the (nullable BIGINT) column into the counter: existing records without a number get the
   * next ones in the order they were created, then MySQL continues from the highest number.
   * AUTO_INCREMENT needs an index in the same statement.
   */
  private function makeAutoIncrement(EntityDefinition $entity, FieldDefinition $field): void
  {
    $table = $this->db->getQuoter()->quoteTableName($entity->tableName());
    $column = $this->db->getQuoter()->quoteColumnName($field->name);
    $index = $this->db->getQuoter()->quoteColumnName(self::uniqueIndexName($field));

    $this->assertNoDuplicates($entity, [$field->name], $field->label);
    $start = (int)$this->db->createQuery()->from($entity->tableName())->max($field->name);
    $this->db->createCommand("SET @excellent_counter := {$start}")->execute();
    $this->db->createCommand("UPDATE {$table} SET {$column} = (@excellent_counter := @excellent_counter + 1) WHERE {$column} IS NULL ORDER BY created_at, CAST(id AS BINARY)")->execute();
    $this->db->createCommand("ALTER TABLE {$table} MODIFY {$column} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD UNIQUE INDEX {$index} ({$column})")->execute();
  }

  /**
   * A new slug field with a source gets a slug in every existing record ("ueber-uns", "team-2" ...).
   */
  private function fillEmptySlugs(EntityDefinition $entity, FieldDefinition $field, ?string $language = null): void
  {
    $source = null !== $field->slugSource ? $entity->field($field->slugSource) : null;
    // A translatable slug is made from the source in the same language
    $column = null !== $language ? $field->translationColumn($language) : $field->name;
    $sourceColumn = null !== $source && null !== $language && $source->translatable ? $source->translationColumn($language) : $source?->name;
    $schema = $this->db->getTableSchema($entity->tableName(), true);
    if (null === $sourceColumn || null === $schema?->getColumn($sourceColumn) || null === $schema->getColumn($column)) {
      return;
    }
    $rows = $this->db->createQuery()->select(['id', 'value' => $column, 'source' => $sourceColumn])->from($entity->tableName())->orderBy(['created_at' => SORT_ASC])->all();
    $taken = [];
    foreach ($rows as $row) {
      if (null !== $row['value']) {
        $taken[(string)$row['value']] = true;
      }
    }
    $length = $field->length ?? FieldType::DEFAULT_LENGTH;
    $this->db->transaction(function () use ($rows, $column, $entity, $length, &$taken): void {
      foreach ($rows as $row) {
        $base = null === $row['value'] && null !== $row['source'] ? Slug::make((string)$row['source'], $length) : '';
        if ('' === $base) {
          continue;
        }
        $slug = Slug::unique($base, $taken, $length);
        $taken[$slug] = true;
        $this->db->createCommand()->update($entity->tableName(), [$column => $slug], ['id' => $row['id']])->execute();
      }
    });
  }

  /**
   * @param array<string, string[]> $errors
   */
  private function validateSlugSource(EntityDefinition $entity, FieldDefinition $field, array &$errors, string $key): void
  {
    if (FieldType::Slug !== $field->type || null === $field->slugSource) {
      return;
    }
    $source = null;
    foreach ($entity->fields as $candidate) {
      if ($candidate->name === $field->slugSource) {
        $source = $candidate;
      }
    }
    if (null === $source || $source->name === $field->name || !$source->type->canBeSlugSource() || $source->repeatable) {
      $errors[$key][] = I18n::t('The slug can only be made from a text field of the same entity.');
    }
  }

  /**
   * A new UUID field (or a field that becomes one) gets a value in every existing record.
   */
  private function fillEmptyUuids(EntityDefinition $entity, FieldDefinition $field): void
  {
    $ids = $this->db->createQuery()->select('id')->from($entity->tableName())->where([$field->name => null])->column();
    if ([] === $ids) {
      return;
    }
    $this->db->transaction(function () use ($entity, $field, $ids): void {
      foreach ($ids as $id) {
        $this->db->createCommand()->update($entity->tableName(), [$field->name => $field->generateValue()], ['id' => $id])->execute();
      }
    });
  }

  /**
   * @param list<list<string>> $old
   * @param list<list<string>> $new
   */
  private function syncUniqueTogether(EntityDefinition $entity, array $old, array $new): void
  {
    $key = static fn(array $set): string => implode(',', $set);
    $oldByKey = array_combine(array_map($key, $old), $old);
    $newByKey = array_combine(array_map($key, $new), $new);

    foreach (array_diff_key($oldByKey, $newByKey) as $set) {
      $this->db->createCommand()->dropIndex($entity->tableName(), self::uniqueTogetherIndexName($entity, $set))->execute();
    }
    foreach (array_diff_key($newByKey, $oldByKey) as $set) {
      $labels = array_map(fn(string $name): string => $entity->field($name)?->label ?? $name, $set);
      $this->assertNoDuplicates($entity, $set, implode(' + ', $labels));
      $this->db->createCommand()->createIndex($entity->tableName(), self::uniqueTogetherIndexName($entity, $set), $set, 'UNIQUE')->execute();
    }
  }

  /**
   * Clear message instead of "SQLSTATE[23000] Duplicate entry" when a unique index cannot be built.
   *
   * @param list<string> $columns
   */
  private function assertNoDuplicates(EntityDefinition $entity, array $columns, string $label): void
  {
    if (!$this->db->getTableSchema($entity->tableName(), true)) {
      return;
    }
    $query = $this->db->createQuery()->select($columns)->from($entity->tableName())->groupBy($columns)->having('COUNT(*) > 1')->limit(1);
    foreach ($columns as $column) {
      $query->andWhere(['not', [$column => null]]);
    }
    $duplicate = $query->one();
    if (is_array($duplicate)) {
      throw new ValidationException(['unique' => [I18n::t('"{field}" cannot be unique: the value "{value}" is there more than once.', ['field' => $label, 'value' => implode(' / ', array_map('strval', $duplicate))])]]);
    }
  }

  /**
   * @return array<string, string|int|bool|null> record id => value in the new type
   */
  private function convertExistingValues(EntityDefinition $entity, FieldDefinition $old, FieldDefinition $new, ?string $match): array
  {
    $rows = $this->recordRepository->column($entity, $old->name);
    $converted = [];
    $failed = [];

    if ($new->type === $old->type && $new->repeatable !== $old->repeatable) {
      // One value <-> list of values (longer lists were refused before)
      foreach ($rows as $row) {
        $converted[(string)$row['id']] = $new->repeatable
          ? json_encode([(string)$row[$old->name]], JSON_UNESCAPED_UNICODE)
          : (FieldDefinition::decodeList($row[$old->name])[0] ?? null);
      }
    } elseif (FieldType::Reference === $new->type && FieldType::Reference !== $old->type) {
      $target = $new->referenceEntityId === $entity->id ? $entity : $this->entityRepository->findById((string)$new->referenceEntityId);
      $match ??= 'id';
      if (null === $target || ('id' !== $match && null === $target->field($match))) {
        throw ValidationException::field('match', I18n::t('Please choose the field that finds the current values.'));
      }
      $ids = $this->recordRepository->idsByValues($target, $match, array_map(static fn(array $row): string => (string)$row[$old->name], $rows));
      foreach ($rows as $row) {
        $id = $ids[(string)$row[$old->name]] ?? null;
        null !== $id ? $converted[(string)$row['id']] = $id : $failed[] = (string)$row[$old->name];
      }
    } else {
      foreach ($rows as $row) {
        try {
          $converted[(string)$row['id']] = ValueConverter::toStorage($new, $row[$old->name]);
        } catch (InvalidValueException) {
          $failed[] = (string)$row[$old->name];
        }
      }
      if (FieldType::Reference === $new->type && [] !== $converted) {
        $target = $this->entityRepository->findById((string)$new->referenceEntityId);
        $known = null !== $target ? $this->recordRepository->findMany($target, array_values(array_filter(array_map('strval', $converted)))) : [];
        foreach ($converted as $id => $value) {
          if (null !== $value && !isset($known[$value])) {
            $failed[] = (string)$value;
            unset($converted[$id]);
          }
        }
      }
    }

    if ([] !== $failed) {
      $examples = implode(', ', array_map(static fn(string $v): string => I18n::quote(mb_strimwidth($v, 0, 40, '…')), array_slice(array_unique($failed), 0, 3)));
      throw ValidationException::field('type', I18n::t('{count, plural, one{# existing value does} other{# existing values do}} not fit the new type "{type}", e.g. {examples}.', ['count' => count($failed), 'type' => $new->type->label(), 'examples' => $examples]));
    }
    if (FieldType::Slug === $new->type) {
      $taken = [];
      foreach ($converted as $id => $value) {
        if (null !== $value) {
          $converted[$id] = Slug::unique((string)$value, $taken, $new->length ?? FieldType::DEFAULT_LENGTH);
          $taken[$converted[$id]] = true;
        }
      }
    }
    if ($new->unique) {
      $values = array_filter($converted, static fn($v): bool => null !== $v);
      if (count($values) !== count(array_unique(array_map('strval', $values)))) {
        throw ValidationException::field('unique', I18n::t('"{field}" cannot be unique: values are there more than once.', ['field' => $new->label]));
      }
    }
    return $converted;
  }

  private function renameFieldInEntity(EntityDefinition $entity, string $oldName, string $newName): void
  {
    $entity = $this->get($entity->id);
    if ($entity->labelField === $oldName) {
      $entity->labelField = $newName;
    }
    if ($entity->treeField === $oldName) {
      $entity->treeField = $newName;
    }
    foreach ($entity->fields as $field) {
      if ($field->slugSource === $oldName) {
        $field->slugSource = $newName;
        $this->entityRepository->updateField($field);
      }
    }
    $entity->uniqueTogether = array_map(static fn(array $set): array => array_map(static fn(string $n): string => $n === $oldName ? $newName : $n, $set), $entity->uniqueTogether);
    // The field stays in its tab
    $entity->tabs = array_map(static fn(array $tab): array => ['fields' => array_map(static fn(string $n): string => $n === $oldName ? $newName : $n, $tab['fields'])] + $tab, $entity->tabs);
    $this->entityRepository->update($entity);
  }

  /**
   * Validates and normalizes field data. Keys: name, label, type, length, scale, uuid_version,
   * media_accept, required, unique, reference (slug or id of the target entity).
   *
   * @param array<string, string[]> $errors
   */
  private function buildField(array $data, EntityDefinition $entity, ?FieldDefinition $existing, array &$errors, string $path, ?FieldGroup $inGroup = null): ?FieldDefinition
  {
    $key = static fn(string $name): string => '' === $path ? $name : "{$path}.{$name}";
    $count = count($errors);

    $name = trim((string)($data['name'] ?? ''));
    if (!Naming::isValid($name)) {
      $errors[$key('name')][] = I18n::t('Please enter a technical name of lower case letters, digits and _ (starting with a letter).');
    } elseif (in_array($name, FieldDefinition::RESERVED, true) || str_contains($name, '__')) {
      $errors[$key('name')][] = I18n::t('"{name}" is reserved.', ['name' => $name]);
    }

    $label = trim((string)($data['label'] ?? ''));
    if ('' === $label) {
      $label = Naming::label($name);
    }
    if (mb_strlen($label) > 100) {
      $errors[$key('label')][] = I18n::t('The label is too long.');
    }

    // Field types of plugins: "geo.point" (or type "custom" with custom_type)
    $typeName = (string)($data['type'] ?? '');
    $customType = null;
    if (str_contains($typeName, '.') || FieldType::Custom->value === $typeName) {
      $customType = FieldType::Custom->value === $typeName ? (string)($data['custom_type'] ?? '') : $typeName;
      // A field of a plugin that is not active (any more) keeps its type
      if (null === CustomFieldTypes::get($customType) && $existing?->customType !== $customType) {
        $errors[$key('type')][] = CustomFieldTypes::unknown($customType);
        return null;
      }
      $typeName = FieldType::Custom->value;
    }
    $type = FieldType::tryFrom($typeName);
    if (null === $type) {
      $errors[$key('type')][] = I18n::t('Please choose a type.');
      return null;
    }

    $length = null;
    if ($type->hasLength()) {
      $length = (int)($data['length'] ?? FieldType::DEFAULT_LENGTH);
      if ($length < 1 || $length > FieldType::MAX_LENGTH) {
        $errors[$key('length')][] = I18n::t('The length must be from 1 to {max} (choose "Long text" for longer texts).', ['max' => FieldType::MAX_LENGTH]);
      }
    }
    $scale = null;
    if ($type->hasScale()) {
      $scale = (int)($data['scale'] ?? FieldType::DEFAULT_SCALE);
      if ($scale < 0 || $scale > FieldType::MAX_SCALE) {
        $errors[$key('scale')][] = I18n::t('The decimal places must be from 0 to {max}.', ['max' => FieldType::MAX_SCALE]);
      }
    }

    $uuidVersion = null;
    if ($type->hasUuidVersion()) {
      $uuidVersion = (int)($data['uuid_version'] ?? FieldType::DEFAULT_UUID_VERSION);
      if (!isset(FieldType::UUID_VERSIONS[$uuidVersion])) {
        $errors[$key('uuid_version')][] = I18n::t('Please choose a UUID version ({versions}).', ['versions' => implode(', ', array_keys(FieldType::UUID_VERSIONS))]);
      }
    }

    // Type "group" as blocks: the groups an item can be of (ids or names) - always a list
    $blocksInput = $data['blocks'] ?? $data['block_groups'] ?? null;
    if (is_string($blocksInput)) {
      $blocksInput = json_decode($blocksInput, true);
    }
    $blocksInput = FieldType::Group === $type && is_array($blocksInput) ? array_values(array_filter($blocksInput, static fn($item): bool => null !== $item && '' !== $item)) : [];

    // ... and all blocks of these categories (also ones added later)
    $categoriesInput = $data['block_categories'] ?? null;
    if (is_string($categoriesInput)) {
      $categoriesInput = json_decode($categoriesInput, true);
    }
    $blockCategories = FieldType::Group === $type && is_array($categoriesInput)
      ? array_values(array_unique(array_filter(array_map(static fn($c): string => mb_substr(trim((string)$c), 0, 60), $categoriesInput), static fn(string $c): bool => '' !== $c)))
      : [];
    $isBlocks = [] !== $blocksInput || [] !== $blockCategories;

    // Repeatable: a list of values with fewest/most items
    $repeatable = (bool)filter_var($data['repeatable'] ?? $data['is_repeatable'] ?? false, FILTER_VALIDATE_BOOL) || $isBlocks;
    $sortable = (bool)filter_var($data['sortable'] ?? $data['is_sortable'] ?? true, FILTER_VALIDATE_BOOL);
    $repeatMin = self::nullableCount($data['repeat_min'] ?? null);
    $repeatMax = self::nullableCount($data['repeat_max'] ?? null);
    if ($repeatable && !$type->canRepeat()) {
      $errors[$key('repeatable')][] = I18n::t('Fields of the type "{type}" cannot be repeatable.', ['type' => $type->label()]);
    } elseif ($repeatable && null !== $repeatMax && $repeatMax < 1) {
      $errors[$key('repeat_max')][] = I18n::t('The maximum must be at least 1 (empty = unlimited).');
    } elseif ($repeatable && null !== $repeatMin && ($repeatMin < 0 || (null !== $repeatMax && $repeatMin > $repeatMax))) {
      $errors[$key('repeat_min')][] = I18n::t('The minimum must be from 0 to the maximum.');
    }
    // Type "regex": a valid pattern is required
    [$pattern, $patternMessage] = [null, null];
    if (FieldType::Regex === $type) {
      $pattern = trim((string)($data['pattern'] ?? ''));
      $patternMessage = trim((string)($data['pattern_message'] ?? '')) ?: null;
      if ('' === $pattern) {
        $errors[$key('pattern')][] = I18n::t('Please enter a regular expression, e.g. ^[A-Z]{2}-\\d{4}$.');
      } elseif (mb_strlen($pattern) > 500 || !FieldDefinition::isValidPattern($pattern)) {
        $errors[$key('pattern')][] = I18n::t('This is no valid regular expression.');
      }
      if (null !== $patternMessage && mb_strlen($patternMessage) > 255) {
        $errors[$key('pattern_message')][] = I18n::t('The message is too long.');
      }
    }
    // Numbers: a range (also for the slider, which needs both ends)
    [$minValue, $maxValue, $slider] = [null, null, false];
    if (in_array($type, [FieldType::Integer, FieldType::Decimal], true)) {
      foreach (['min_value', 'max_value'] as $limit) {
        $raw = $data[$limit] ?? null;
        if (null !== $raw && '' !== trim((string)$raw) && !is_numeric(str_replace(',', '.', (string)$raw))) {
          $errors[$key($limit)][] = I18n::t('Please enter a number.');
        }
      }
      $minValue = self::nullableNumber($data['min_value'] ?? null);
      $maxValue = self::nullableNumber($data['max_value'] ?? null);
      $slider = (bool)filter_var($data['slider'] ?? $data['is_slider'] ?? false, FILTER_VALIDATE_BOOL);
      if (null !== $minValue && null !== $maxValue && $minValue > $maxValue) {
        $errors[$key('min_value')][] = I18n::t('The minimum must not be larger than the maximum.');
      } elseif ($slider && (null === $minValue || null === $maxValue)) {
        $errors[$key('slider')][] = I18n::t('A slider needs a minimum and a maximum.');
      }
    }
    $mediaAccept = [];
    if ($type->hasMediaAccept()) {
      $mediaAccept = self::mediaAccept($data['media_accept'] ?? null);
      $invalid = array_filter($mediaAccept, static fn(string $entry): bool => !MediaService::isValidAccept($entry));
      if ([] !== $invalid) {
        $errors[$key('media_accept')][] = I18n::t('These file types are not allowed: {types}.', ['types' => implode(', ', $invalid)]);
      }
    }

    $unique = (bool)filter_var($data['unique'] ?? $data['is_unique'] ?? false, FILTER_VALIDATE_BOOL);
    if ($unique && !$type->canBeUnique()) {
      $errors[$key('unique')][] = I18n::t('Fields of the type "{type}" cannot be unique.', ['type' => $type->label()]);
    } elseif ($unique && $repeatable) {
      $errors[$key('unique')][] = I18n::t('Repeatable fields cannot be unique.');
    }
    $required = (bool)filter_var($data['required'] ?? false, FILTER_VALIDATE_BOOL);
    $translatable = (bool)filter_var($data['translatable'] ?? $data['is_translatable'] ?? false, FILTER_VALIDATE_BOOL);
    if ($translatable && !$type->canBeTranslated()) {
      $errors[$key('translatable')][] = I18n::t('Fields of the type "{type}" cannot be translatable.', ['type' => $type->label()]);
    }
    $slugSource = null;
    if (FieldType::Slug === $type) {
      // Taken slugs get a number, so the field is always unique
      $unique = true;
      $slugSource = trim((string)($data['slug_source'] ?? '')) ?: null;
    }
    $options = [];
    if (FieldType::Enum === $type) {
      $options = self::enumOptions($data['options'] ?? null);
      $values = array_column($options, 'value');
      if ([] === $options) {
        $errors[$key('options')][] = I18n::t('Please enter at least one value.');
      } elseif (count($values) !== count(array_unique($values))) {
        $errors[$key('options')][] = I18n::t('Every value can only be there once.');
      } elseif ([] !== array_filter($values, static fn(string $v): bool => mb_strlen($v) > FieldType::ENUM_LENGTH)) {
        $errors[$key('options')][] = I18n::t('Values can have at most {length} characters.', ['length' => FieldType::ENUM_LENGTH]);
      }
    }
    if (FieldType::Order === $type) {
      // Filled in by the CMS (end of the list) - never required, one per entity
      $required = false;
      foreach ($entity->fields as $other) {
        if (FieldType::Order === $other->type && $other->id !== $existing?->id) {
          $errors[$key('type')][] = I18n::t('"{field}" orders this entity already - there can only be one.', ['field' => $other->label]);
        }
      }
    }
    if (FieldType::Uuid === $type) {
      // The id of a record for other systems - one per entity
      foreach ($entity->fields as $other) {
        if (FieldType::Uuid === $other->type && $other->id !== $existing?->id) {
          $errors[$key('type')][] = I18n::t('"{field}" is the UUID of this entity already - there can only be one.', ['field' => $other->label]);
        }
      }
    }
    if ($type->isGenerated()) {
      // The database assigns the numbers: unique by definition, never left empty by the user
      [$unique, $required] = [true, false];
      foreach ($entity->fields as $other) {
        if ($other->type->isGenerated() && $other->id !== $existing?->id) {
          $errors[$key('type')][] = I18n::t('"{field}" is the counter of this entity already - there can only be one.', ['field' => $other->label]);
        }
      }
    }

    $referenceId = null;
    $referenceSlug = null;
    if (FieldType::Reference === $type) {
      $reference = trim((string)($data['reference'] ?? $data['reference_entity_id'] ?? ''));
      if (null === $inGroup && ($reference === $entity->id || $reference === $entity->slug)) {
        [$referenceId, $referenceSlug] = [$entity->id, $entity->slug];
      } else {
        $target = '' !== $reference ? ($this->entityRepository->findById($reference) ?? $this->entityRepository->findBySlug($reference)) : null;
        if (null === $target) {
          $errors[$key('reference')][] = I18n::t('Please choose the entity the field references.');
        } else {
          [$referenceId, $referenceSlug] = [$target->id, $target->slug];
        }
      }
    }

    // Type "group": which group; a group must not contain itself (also not through other groups)
    $fieldGroup = null;
    $blockGroups = [];
    if ($isBlocks) {
      foreach ($blocksInput as $wanted) {
        $wanted = is_array($wanted) ? (string)($wanted['id'] ?? $wanted['name'] ?? '') : trim((string)$wanted);
        $blockGroup = '' !== $wanted ? $this->entityRepository->findGroup($wanted) : null;
        if (null === $blockGroup) {
          $errors[$key('blocks')][] = I18n::t('There is no block "{group}".', ['group' => $wanted]);
        } elseif (!$blockGroup->isBlock()) {
          $errors[$key('blocks')][] = I18n::t('"{group}" is a field group, not a block.', ['group' => $blockGroup->label]);
        } elseif (null !== $inGroup && $blockGroup->contains($inGroup->id)) {
          // Nested blocks: a block type must not contain the group itself (not even deeper down)
          $errors[$key('blocks')][] = I18n::t('"{group}" contains "{parent}" already - a group cannot contain itself.', ['group' => $blockGroup->label, 'parent' => $inGroup->label]);
        } else {
          $blockGroups[$blockGroup->name] = $blockGroup;
        }
      }
    } elseif (FieldType::Group === $type) {
      $wanted = trim((string)($data['group'] ?? $data['field_group_id'] ?? ''));
      $fieldGroup = '' !== $wanted ? $this->entityRepository->findGroup($wanted) : null;
      if (null === $fieldGroup) {
        $errors[$key('group')][] = I18n::t('Please choose the field group.');
      } elseif ($fieldGroup->isBlock()) {
        $errors[$key('group')][] = I18n::t('"{group}" is a block - blocks are chosen in block lists only.', ['group' => $fieldGroup->label]);
      } elseif (null !== $inGroup && $fieldGroup->contains($inGroup->id)) {
        $errors[$key('group')][] = I18n::t('"{group}" contains "{parent}" already - a group cannot contain itself.', ['group' => $fieldGroup->label, 'parent' => $inGroup->label]);
      }
    }
    if (null !== $inGroup) {
      if (!$type->canBeInGroup()) {
        $errors[$key('type')][] = I18n::t('Field groups cannot have fields of the type "{type}".', ['type' => $type->label()]);
      }
      if ($translatable) {
        $errors[$key('translatable')][] = I18n::t('Fields in field groups cannot be translatable on their own - switch on "Translatable" at the group field of the entity.');
      }
      if ($unique) {
        $errors[$key('unique')][] = I18n::t('Fields in field groups cannot be unique.');
      }
    }

    // Roles that may see / change the field (fields of groups follow the group field)
    $roles = [];
    foreach (['read_roles', 'write_roles'] as $prop) {
      $roles[$prop] = null === $inGroup ? ($existing?->{'read_roles' === $prop ? 'readRoles' : 'writeRoles'}) : null;
      if (null === $inGroup && array_key_exists($prop, $data)) {
        // From the API a list, from a stored row (updateField) JSON
        $value = is_string($data[$prop]) ? (json_decode($data[$prop], true) ?? []) : $data[$prop];
        $list = null === $value ? [] : array_values(array_unique(array_map('strval', (array)$value)));
        foreach ($list as $role) {
          if (null === $this->access || !$this->access->roleExists($role)) {
            $errors[$key($prop)][] = I18n::t('This role does not exist: {role}', ['role' => $role]);
          }
        }
        $roles[$prop] = [] !== $list ? $list : null;
      }
    }

    // Search and filters: on unless switched off (fields of groups are never filtered on their own)
    $flag = static fn(string $key, string $column, bool $current): bool => array_key_exists($key, $data)
      ? (bool)filter_var($data[$key], FILTER_VALIDATE_BOOL)
      : (array_key_exists($column, $data) ? (bool)$data[$column] : $current);
    $searchable = $flag('searchable', 'is_searchable', $existing->searchable ?? true);
    $filterable = $flag('filterable', 'is_filterable', $existing->filterable ?? true);
    $searchWeight = $existing->searchWeight ?? 1;
    if (array_key_exists('search_weight', $data) && null !== $data['search_weight'] && '' !== $data['search_weight']) {
      $weight = filter_var($data['search_weight'], FILTER_VALIDATE_INT);
      if (false === $weight || $weight < 1 || $weight > 10) {
        $errors[$key('search_weight')][] = I18n::t('Please enter a weight from 1 to 10.');
      } else {
        $searchWeight = $weight;
      }
    }

    if (count($errors) > $count) {
      return null;
    }

    return new FieldDefinition(
      id: $existing->id ?? Id::new(),
      entityId: null !== $inGroup ? '' : $entity->id,
      name: $name,
      label: $label,
      type: $type,
      length: $length,
      scale: $scale,
      required: $required,
      unique: $unique,
      referenceEntityId: $referenceId,
      referenceEntity: $referenceSlug,
      sortOrder: $existing->sortOrder ?? 0,
      uuidVersion: $uuidVersion,
      mediaAccept: $mediaAccept,
      slugSource: $slugSource,
      repeatable: $repeatable && $type->canRepeat(),
      sortable: $sortable,
      repeatMin: $repeatable ? ($repeatMin ?: null) : null,
      repeatMax: $repeatable ? $repeatMax : null,
      translatable: $translatable,
      groupId: $inGroup?->id,
      fieldGroupId: [] === $blockGroups ? $fieldGroup?->id : null,
      group: [] === $blockGroups ? $fieldGroup : null,
      pattern: '' !== (string)$pattern ? $pattern : null,
      patternMessage: $patternMessage,
      options: $options,
      readRoles: $roles['read_roles'],
      writeRoles: $roles['write_roles'],
      searchable: $searchable,
      filterable: $filterable,
      searchWeight: $searchWeight,
      blockGroupIds: array_values(array_map(static fn(FieldGroup $group): string => $group->id, $blockGroups)),
      blockCategories: $blockCategories,
      customType: $customType,
      minValue: $minValue,
      maxValue: $maxValue,
      slider: $slider,
      blocks: $blockGroups,
    );
  }

  /**
   * Preview address of an entity: http(s), placeholders and $NAME .env variables allowed.
   *
   * @param array<string, list<string>> $errors
   */
  /**
   * A field a plugin relies on keeps its name, type and kind (labels, help … may change) - and its lock.
   *
   * @param array<string, list<string>> $errors
   */
  public static function assertUnlocked(FieldDefinition $old, FieldDefinition $new, array &$errors): void
  {
    $new->locked = $old->locked;
    if (!$old->locked) {
      return;
    }
    if ($new->name !== $old->name) {
      $errors['name'][] = I18n::t('A plugin relies on the field "{field}" - its name cannot change.', ['field' => $old->label]);
    }
    if ($new->type !== $old->type || $new->customType !== $old->customType || $new->isBlocks() !== $old->isBlocks() || $new->repeatable !== $old->repeatable) {
      $errors['type'][] = I18n::t('A plugin relies on the field "{field}" - its type cannot change.', ['field' => $old->label]);
    }
  }

  /**
   * Tabs of the record form: [{key?, label, fields: [names]}] - 1 to 20, each with a label (one tab
   * only: may be empty); fields no tab names go into the first. One tab without label = no design.
   *
   * @param array<string, list<string>> $errors
   * @return list<array{key: string, label: string, fields: list<string>}>
   */
  private function tabs(mixed $value, EntityDefinition $entity, array &$errors): array
  {
    if (null === $value || [] === $value) {
      return [];
    }
    if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
      $errors['tabs'][] = I18n::t('Please send a list of at most 20 tabs.');
      return $entity->tabs;
    }
    $tabs = [];
    $keys = [];
    $seen = [];
    foreach ($value as $i => $tab) {
      $tab = is_array($tab) ? $tab : [];
      $label = trim((string)($tab['label'] ?? ''));
      if (count($value) > 1 && ('' === $label || mb_strlen($label) > 60)) {
        $errors['tabs.'.$i][] = I18n::t('Please give the tab a name (at most 60 characters).');
      }
      $key = trim((string)($tab['key'] ?? ''));
      if (1 !== preg_match('/^[a-z0-9_-]{1,40}$/', $key) || isset($keys[$key])) {
        $key = 'tab'.($i + 1);
        while (isset($keys[$key])) {
          $key .= '_';
        }
      }
      $keys[$key] = true;
      $fields = [];
      foreach ((array)($tab['fields'] ?? []) as $name) {
        $name = (string)$name;
        if (null === $entity->field($name)) {
          $errors['tabs.'.$i][] = I18n::t('The field "{field}" does not exist.', ['field' => $name]);
        } elseif (isset($seen[$name])) {
          $errors['tabs.'.$i][] = I18n::t('"{field}" is in two tabs.', ['field' => $name]);
        } else {
          $seen[$name] = true;
          $fields[] = $name;
        }
      }
      $tabs[] = ['key' => $key, 'label' => $label, 'fields' => $fields];
    }
    // One tab without a name: the form as always
    return 1 === count($tabs) && '' === $tabs[0]['label'] ? [] : $tabs;
  }

  private function previewUrl(mixed $value, array &$errors): ?string
  {
    $url = trim((string)$value);
    if ('' === $url) {
      return null;
    }
    if (mb_strlen($url) > 500 || 1 !== preg_match('~^https?://[^\s]+$~i', $url)) {
      $errors['preview_url'][] = I18n::t('Please enter an address starting with http:// or https:// (at most 500 characters).');
    } elseif (!str_contains($url, '{{token}}') && !str_contains($url, '{{ token }}')) {
      $errors['preview_url'][] = I18n::t('The address needs {placeholder} - the website uses it to load the draft.', ['placeholder' => '{{token}}']);
    }
    return $url;
  }

  /**
   * Options of an enum: [{value, label}], ["a", "b"] or text with one per line ("a" or "a = Label").
   *
   * @return list<array{value: string, label: string}>
   */
  private static function enumOptions(mixed $value): array
  {
    if (is_string($value)) {
      $decoded = json_decode($value, true);
      $value = is_array($decoded) ? $decoded : preg_split('/\R/', $value);
    }
    $options = [];
    foreach (is_array($value) ? $value : [] as $option) {
      if (is_array($option)) {
        [$optionValue, $label] = [trim((string)($option['value'] ?? '')), trim((string)($option['label'] ?? ''))];
      } else {
        [$optionValue, $label] = array_map('trim', array_pad(explode('=', (string)$option, 2), 2, ''));
      }
      if ('' !== $optionValue) {
        $options[] = ['value' => $optionValue, 'label' => '' !== $label ? $label : $optionValue];
      }
    }
    return $options;
  }

  private function findField(EntityDefinition $entity, string $fieldId): FieldDefinition
  {
    foreach ($entity->fields as $field) {
      if ($field->id === $fieldId || $field->name === $fieldId) {
        return $field;
      }
    }
    throw UserFacingException::notFound(I18n::t('This field does not exist.'));
  }

  /**
   * @param array<string, string[]> $errors
   */
  private function validateSlug(string $slug, ?EntityDefinition $current, array &$errors): void
  {
    if (!Naming::isValid($slug, self::SLUG_MAX)) {
      $errors['slug'][] = I18n::t('Please enter a technical name of lower case letters, digits and _ (at most {max} characters).', ['max' => self::SLUG_MAX]);
    } elseif (in_array($slug, self::RESERVED_SLUGS, true)) {
      $errors['slug'][] = I18n::t('"{name}" is reserved.', ['name' => $slug]);
    } elseif ($slug !== $current?->slug && null !== ($taken = $this->currentProject->get()->isGlobal ? $this->entityRepository->findBySlugAnywhere($slug) : $this->entityRepository->findBySlug($slug))) {
      // Global entities share the name space of every project
      $errors['slug'][] = $taken->global && !$this->currentProject->get()->isGlobal
        ? I18n::t('"{entity}" exists as a global entity already.', ['entity' => $slug])
        : ($this->currentProject->get()->isGlobal && !$taken->global
          ? I18n::t('A project has an entity "{entity}" already - global names must be free everywhere.', ['entity' => $slug])
          : I18n::t('An entity "{entity}" exists already.', ['entity' => $slug]));
    } elseif ($slug !== $current?->slug && null !== $this->db->getTableSchema(EntityDefinition::contentTable($this->currentProject->get()->tablePrefix, $slug), true)) {
      $errors['slug'][] = I18n::t('The table "{table}" exists in the database already.', ['table' => EntityDefinition::contentTable($this->currentProject->get()->tablePrefix, $slug)]);
    }
  }

  /**
   * @param array<string, string[]> $errors
   */
  private function validateName(string $name, array &$errors): void
  {
    if ('' === $name) {
      $errors['name'][] = I18n::t('Please enter a name.');
    } elseif (mb_strlen($name) > 100) {
      $errors['name'][] = I18n::t('The name is too long.');
    }
  }

  /**
   * @param array<string, string[]> $errors
   */
  private function access(mixed $value, array &$errors): Access
  {
    $access = Access::tryFrom((string)$value);
    if (null === $access) {
      $errors['access'][] = I18n::t('Please choose "public" or "OAuth".');
    }
    return $access ?? Access::OAuth;
  }

  /**
   * @param array<string, string[]> $errors
   */
  private function labelField(mixed $value, EntityDefinition $entity, array &$errors): ?string
  {
    $name = trim((string)$value);
    if ('' === $name) {
      return null;
    }
    if (null === $entity->field($name)) {
      $errors['label_field'][] = I18n::t('The display field must be a field of the entity.');
      return null;
    }
    return $name;
  }

  /**
   * "" / null = none, otherwise a whole number.
   */
  private static function nullableCount(mixed $value): ?int
  {
    return null === $value || '' === trim((string)$value) ? null : (int)$value;
  }

  private static function nullableNumber(mixed $value): int|float|null
  {
    $value = null === $value ? '' : str_replace(',', '.', trim((string)$value));
    if ('' === $value || !is_numeric($value)) {
      return null;
    }
    $number = 0 + $value;
    return is_float($number) && floor($number) === $number && abs($number) < PHP_INT_MAX ? (int)$number : $number;
  }

  /**
   * List of MIME types (also as JSON or comma separated text, as stored in entity_field).
   *
   * @return list<string>
   */
  private static function mediaAccept(mixed $value): array
  {
    if (is_string($value)) {
      $decoded = json_decode($value, true);
      $value = is_array($decoded) ? $decoded : explode(',', $value);
    }
    $list = array_map(static fn($entry): string => strtolower(trim((string)$entry)), is_array($value) ? $value : []);
    // Values of the first version
    $list = array_map(static fn(string $entry): string => 'image' === $entry ? 'image/*' : $entry, $list);
    return array_values(array_unique(array_filter($list, static fn(string $entry): bool => '' !== $entry && 'any' !== $entry && '*/*' !== $entry)));
  }

  /**
   * @param array<string, string[]> $errors
   */
  private function treeField(mixed $value, EntityDefinition $entity, array &$errors): ?string
  {
    $name = trim((string)$value);
    if ('' === $name) {
      return null;
    }
    $field = $entity->field($name);
    if (null === $field || !$entity->isSelfReference($field)) {
      $errors['tree_field'][] = I18n::t('The parent field must reference "{entity}" itself.', ['entity' => $entity->name]);
      return null;
    }
    return $name;
  }

  /**
   * @param array<string, string[]> $errors
   * @return list<list<string>>
   */
  private function uniqueTogether(mixed $value, EntityDefinition $entity, array &$errors): array
  {
    $result = [];
    foreach (is_array($value) ? $value : [] as $set) {
      $names = array_values(array_unique(array_filter(array_map(static fn($n): string => trim((string)$n), is_array($set) ? $set : explode(',', (string)$set)))));
      if (count($names) < 2) {
        $errors['unique_together'][] = I18n::t('A combined unique index needs at least two fields.');
        continue;
      }
      foreach ($names as $name) {
        $field = $entity->field($name);
        if (null === $field || !$field->type->canBeUnique()) {
          $errors['unique_together'][] = I18n::t('"{field}" cannot be part of a combined unique index.', ['field' => $name]);
          continue 2;
        }
      }
      $result[implode(',', $names)] = $names;
    }
    return array_values($result);
  }

  private function nullableText(mixed $value): ?string
  {
    $text = trim((string)$value);
    return '' === $text ? null : $text;
  }
}
