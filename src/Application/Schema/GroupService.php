<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Application\Service\CurrentProject;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldGroup;
use App\Domain\Schema\FieldType;
use App\Repository\EntityRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;
use App\Shared\Naming;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Field groups of the current project (admins): reusable sets of fields. Changing a group changes
 * every entity that uses it at once - group values are JSON, so no table changes are needed. Values
 * of removed fields stay in the JSON but are not delivered any more.
 */
final class GroupService
{
  public function __construct(
    private EntityRepository $entities,
    private SchemaService $schema,
    private CurrentProject $currentProject,
    private ConnectionInterface $db,
    private ?\App\Application\Content\BlockTemplates $templates = null,
  ) {
  }

  /**
   * Turns fields of an entity into a group field - a new group made from them, or an existing group
   * with fields of the same names and types. All values (and translations) move into the group, the
   * old columns go away.
   *
   * @param array{fields?: list<string>, group?: string, group_name?: string, group_label?: string, name?: string, label?: string} $data
   */
  public function fromFields(string $entityId, array $data): EntityDefinition
  {
    $entity = $this->schema->get($entityId);
    $names = array_values(array_unique(array_map('strval', (array)($data['fields'] ?? []))));
    $fields = [];
    $errors = [];
    foreach ($names as $name) {
      $field = $entity->field($name);
      if (null === $field) {
        $errors['fields'][] = I18n::t('The field "{field}" does not exist.', ['field' => $name]);
      } elseif (!$field->type->canBeInGroup()) {
        $errors['fields'][] = I18n::t('"{field}" ({type}) cannot be in a field group.', ['field' => $field->label, 'type' => $field->type->label()]);
      } elseif ($entity->treeField === $field->name) {
        $errors['fields'][] = I18n::t('"{field}" is the parent field of the tree.', ['field' => $field->label]);
      } elseif ([] !== array_filter($entity->uniqueTogether, static fn(array $set): bool => in_array($field->name, $set, true))) {
        $errors['fields'][] = I18n::t('"{field}" is part of a combined unique index.', ['field' => $field->label]);
      } elseif ([] !== array_filter($entity->fields, static fn(FieldDefinition $other): bool => $other->slugSource === $field->name && !in_array($other->name, $names, true))) {
        $errors['fields'][] = I18n::t('A slug is made from "{field}".', ['field' => $field->label]);
      } else {
        $fields[] = $field;
      }
    }
    if ([] === $names) {
      $errors['fields'][] = I18n::t('Please choose at least one field.');
    }
    $groupFieldName = trim((string)($data['name'] ?? '')) ?: trim((string)($data['group_name'] ?? $data['group'] ?? ''));
    if ('' !== $groupFieldName && null !== $entity->field($groupFieldName) && !in_array($groupFieldName, $names, true)) {
      $errors['name'][] = I18n::t('The field "{field}" exists already.', ['field' => $groupFieldName]);
    } elseif (in_array($groupFieldName, $names, true)) {
      $errors['name'][] = I18n::t('The group field needs another name than the converted fields.');
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }

    // The group: an existing one with matching fields, or a new one made from the fields
    if ('' !== trim((string)($data['group'] ?? ''))) {
      $group = $this->get((string)$data['group']);
      if ($group->isBlock()) {
        throw ValidationException::field('group', I18n::t('"{group}" is a block - blocks are chosen in block lists only.', ['group' => $group->label]));
      }
      foreach ($fields as $field) {
        $target = $group->field($field->name);
        if (null === $target || $target->type !== $field->type || $target->repeatable !== $field->repeatable) {
          $errors['group'][] = I18n::t('"{group}" has no field "{field}" of type {type}.', ['group' => $group->label, 'field' => $field->name, 'type' => $field->type->label().($field->repeatable ? ' ('.I18n::t('repeatable').')' : '')]);
        }
      }
      if ([] !== $errors) {
        throw new ValidationException($errors);
      }
    } else {
      $group = $this->create([
        'name' => $data['group_name'] ?? '',
        'label' => $data['group_label'] ?? '',
        'fields' => array_map(static fn(FieldDefinition $field): array => [
          'name' => $field->name,
          'label' => $field->label,
          'type' => $field->type->value,
          'length' => $field->length,
          'scale' => $field->scale,
          'required' => $field->required,
          'reference' => $field->referenceEntityId,
          'group' => $field->fieldGroupId,
          'media_accept' => $field->mediaAccept(),
          'repeatable' => $field->repeatable,
          'sortable' => $field->sortable,
          'repeat_min' => $field->repeatMin,
          'repeat_max' => $field->repeatMax,
        ], $fields),
      ]);
    }

    // The group field takes the place of the first field
    $translatable = [] !== $entity->otherLanguages() && [] !== array_filter($fields, static fn(FieldDefinition $f): bool => $f->translatable);
    $groupField = $this->schema->addField($entity->id, [
      'name' => $data['name'] ?? $group->name,
      'label' => $data['label'] ?? $group->label,
      'type' => 'group',
      'group' => $group->id,
      'translatable' => $translatable,
    ]);
    $entity = $this->schema->get($entity->id);
    $groupField = $entity->field($groupField->name) ?? $groupField;

    // Values (records in the trash too) move into the group
    $table = $entity->tableName();
    $rows = $this->db->createQuery()->from($table)->all();
    $this->db->transaction(function () use ($rows, $fields, $groupField, $entity, $table, $translatable): void {
      foreach ($rows as $row) {
        $values = [$groupField->name => self::object($fields, $row, null)];
        foreach ($translatable ? $entity->otherLanguages() : [] as $language) {
          $values[$groupField->translationColumn($language)] = self::object($fields, $row, $language);
        }
        $this->db->createCommand()->update($table, $values, ['id' => $row['id']])->execute();
      }
    });

    $first = min(array_map(static fn(FieldDefinition $f): int => $f->sortOrder, $fields));
    foreach ($fields as $field) {
      $this->schema->deleteField($entity->id, $field->id);
    }
    $entity = $this->schema->get($entity->id);
    $order = array_map(static fn(FieldDefinition $f): string => $f->id, array_filter($entity->fields, static fn(FieldDefinition $f): bool => $f->id !== $groupField->id));
    $position = count(array_filter($entity->fields, static fn(FieldDefinition $f): bool => $f->id !== $groupField->id && $f->sortOrder < $first));
    array_splice($order, $position, 0, [$groupField->id]);
    return $this->schema->reorderFields($entity->id, $order);
  }

  /**
   * One record's values as the group object (stored form) - null if all are empty. Languages:
   * translatable fields from their language column, the others with their single value; null when
   * there is no translation at all (the default language is shown then).
   *
   * @param list<FieldDefinition> $fields
   */
  private static function object(array $fields, array $row, ?string $language): ?string
  {
    $object = [];
    $empty = true;
    $translated = false;
    foreach ($fields as $field) {
      $column = null !== $language && $field->translatable ? $field->translationColumn($language) : $field->name;
      $stored = $row[$column] ?? null;
      $translated = $translated || (null !== $language && $field->translatable && null !== $stored);
      $value = $field->repeatable || FieldType::Group === $field->type
        ? (is_string($stored) && '' !== $stored ? json_decode($stored, true) : null)
        : (null !== $stored ? (string)$stored : null);
      if (FieldType::Boolean === $field->type && null !== $stored) {
        $value = (bool)$stored;
      }
      $object[$field->name] = $value;
      $empty = $empty && (null === $value || [] === $value);
    }
    if ($empty || (null !== $language && !$translated)) {
      return null;
    }
    return json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  /**
   * @return list<FieldGroup>
   */
  /**
   * @param string|null $kind only field groups or only blocks
   * @return list<FieldGroup>
   */
  public function all(?string $kind = null): array
  {
    return array_values(array_filter($this->entities->groups(), static fn(FieldGroup $group): bool => null === $kind || $group->kind === $kind));
  }

  public function get(string $idOrName): FieldGroup
  {
    return $this->entities->findGroup($idOrName) ?? throw UserFacingException::notFound(I18n::t('This field group does not exist.'));
  }

  public function create(array $data): FieldGroup
  {
    $group = new FieldGroup(id: Id::new(), projectId: $this->currentProject->get()->id, name: '', label: '', sortOrder: count($this->all()), kind: FieldGroup::KIND_BLOCK === ($data['kind'] ?? null) ? FieldGroup::KIND_BLOCK : FieldGroup::KIND_GROUP);
    $this->apply($group, $data, true);
    $this->entities->saveGroup($group, true);
    foreach (array_values((array)($data['fields'] ?? [])) as $fieldData) {
      $this->addField($group->id, (array)$fieldData);
    }
    return $this->get($group->id);
  }

  public function update(string $id, array $data): FieldGroup
  {
    $group = $this->get($id);
    $this->apply($group, $data, false);
    $this->entities->saveGroup($group, false);
    return $this->get($group->id);
  }

  public function delete(string $id): void
  {
    $group = $this->get($id);
    if (null !== $group->managedBy) {
      throw UserFacingException::conflict(I18n::t('The plugin "{plugin}" manages "{group}" - uninstall the plugin to delete it.', ['plugin' => $group->managedBy, 'group' => $group->label]), 'group_managed');
    }
    $users = $this->entities->fieldsUsingGroup($group->id);
    if ([] !== $users) {
      throw UserFacingException::conflict(I18n::t('"{group}" is still used by {count, plural, one{# field} other{# fields}}. Please delete them first.', ['group' => $group->label, 'count' => count($users)]), 'group_in_use');
    }
    $this->entities->deleteGroup($group->id);
  }

  public function addField(string $groupId, array $data): FieldGroup
  {
    $group = $this->get($groupId);
    $field = $this->schema->buildGroupField($group, $data);
    $field->sortOrder = count($group->fields);
    $this->entities->insertField($field);
    return $this->get($group->id);
  }

  public function updateField(string $groupId, string $fieldId, array $data): FieldGroup
  {
    $group = $this->get($groupId);
    $old = $this->field($group, $fieldId);
    $field = $this->schema->buildGroupField($group, $data, $old);
    $errors = [];
    SchemaService::assertUnlocked($old, $field, $errors);
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    $this->entities->updateField($field);
    return $this->get($group->id);
  }

  public function deleteField(string $groupId, string $fieldId): FieldGroup
  {
    $group = $this->get($groupId);
    $field = $this->field($group, $fieldId);
    if ($field->locked) {
      throw UserFacingException::conflict(I18n::t('A plugin relies on the field "{field}" - it cannot be deleted.', ['field' => $field->label]), 'field_locked');
    }
    if (1 === count($group->fields)) {
      throw new UserFacingException(I18n::t('The last field of a group cannot be deleted. Delete the group instead.'));
    }
    $this->entities->deleteField($field->id);
    return $this->get($group->id);
  }

  /**
   * @param list<string> $fieldIds
   */
  public function reorderFields(string $groupId, array $fieldIds): FieldGroup
  {
    $group = $this->get($groupId);
    $position = array_flip(array_values(array_map('strval', $fieldIds)));
    foreach ($group->fields as $field) {
      $field->sortOrder = $position[$field->id] ?? count($position) + $field->sortOrder;
      $this->entities->updateField($field);
    }
    return $this->get($group->id);
  }

  private function field(FieldGroup $group, string $fieldId): \App\Domain\Schema\FieldDefinition
  {
    foreach ($group->fields as $field) {
      if ($field->id === $fieldId || $field->name === $fieldId) {
        return $field;
      }
    }
    throw UserFacingException::notFound(I18n::t('This field does not exist.'));
  }

  private function apply(FieldGroup $group, array $data, bool $isNew): void
  {
    $errors = [];
    if (!$isNew && null !== $group->managedBy && array_key_exists('name', $data) && trim((string)$data['name']) !== $group->name) {
      // Blocks are found by name (_type of the items)
      $errors['name'][] = I18n::t('The plugin "{plugin}" manages "{group}" - its name cannot change.', ['plugin' => $group->managedBy, 'group' => $group->label]);
    } elseif ($isNew || array_key_exists('name', $data)) {
      $group->name = trim((string)($data['name'] ?? ''));
      $other = Naming::isValid($group->name, 40) ? $this->entities->findGroup($group->name) : null;
      if (!Naming::isValid($group->name, 40)) {
        $errors['name'][] = I18n::t('Please enter a technical name of lower case letters, digits and _ (at most 40 characters).');
      } elseif (null !== $other && $other->id !== $group->id) {
        $errors['name'][] = I18n::t('A field group "{group}" exists already.', ['group' => $group->name]);
      }
    }
    if ($isNew || array_key_exists('label', $data)) {
      $group->label = trim((string)($data['label'] ?? '')) ?: Naming::label($group->name);
      if (mb_strlen($group->label) > 100) {
        $errors['label'][] = I18n::t('The label is too long.');
      }
    }
    if (array_key_exists('description', $data)) {
      $description = trim((string)$data['description']);
      $group->description = '' === $description ? null : $description;
    }
    if (array_key_exists('template', $data)) {
      $template = trim((string)$data['template']);
      if ('' !== $template && null !== $this->templates && null !== ($problem = $this->templates->check($template))) {
        $errors['template'][] = $problem;
      }
      $group->template = '' === $template ? null : (string)$data['template'];
    }
    if (array_key_exists('category', $data)) {
      $category = trim((string)$data['category']);
      if (mb_strlen($category) > 60) {
        $errors['category'][] = I18n::t('At most {count} characters.', ['count' => 60]);
      }
      $group->category = '' === $category ? null : $category;
    }
    // A field group becomes a block (or back) only while nothing uses it the old way
    if (!$isNew && array_key_exists('kind', $data) && in_array($data['kind'], [FieldGroup::KIND_GROUP, FieldGroup::KIND_BLOCK], true) && $data['kind'] !== $group->kind) {
      if (null !== $group->managedBy) {
        $errors['kind'][] = I18n::t('The plugin "{plugin}" manages "{group}" - its kind cannot change.', ['plugin' => $group->managedBy, 'group' => $group->label]);
      } elseif ([] !== $this->entities->fieldsUsingGroup($group->id)) {
        $errors['kind'][] = FieldGroup::KIND_BLOCK === $data['kind']
          ? I18n::t('Group fields use "{group}" - it can only become a block when none does.', ['group' => $group->label])
          : I18n::t('Block lists use "{group}" - it can only become a field group when none does.', ['group' => $group->label]);
      } else {
        $group->kind = (string)$data['kind'];
      }
    }
    if ($isNew && [] === (array)($data['fields'] ?? [])) {
      $errors['fields'][] = I18n::t('A field group needs at least one field.');
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
  }
}
