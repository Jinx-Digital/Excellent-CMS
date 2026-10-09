<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Application\Service\CurrentProject;
use App\Domain\Project\Project;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldGroup;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\GroupValues;
use App\Infrastructure\Media\MediaStorages;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Copies an entity into another project: the schema (fields, field groups, settings) and - if
 * wanted - its records.
 *
 * Records keep their ids. Copying related entities one after the other (countries, then authors)
 * therefore keeps their references; references to records the target project does not have are
 * emptied and counted. Files of media fields are copied into the target project.
 */
final class EntityCopyService
{
  private const CHUNK = 500;

  public function __construct(
    private SchemaService $schema,
    private GroupService $groups,
    private EntityRepository $entities,
    private ProjectRepository $projects,
    private CurrentProject $currentProject,
    private ConnectionInterface $db,
    private MediaStorages $storages,
  ) {
  }

  /**
   * @return array{entity: EntityDefinition, project: Project, records: int, media: int, cleared: array<string, int>}
   */
  public function copy(string $entityId, string $targetProject, ?string $slug = null, bool $withRecords = false): array
  {
    $source = $this->schema->get($entityId);
    $sourceProject = $this->currentProject->get();
    $target = $this->projects->find($targetProject) ?? throw ValidationException::field('project', I18n::t('This project does not exist.'));
    if ($target->id === $sourceProject->id) {
      throw ValidationException::field('project', I18n::t('Please choose another project - copying within the same project is not possible.'));
    }
    $slug = trim((string)$slug) ?: $source->slug;

    $this->currentProject->set($target);
    try {
      $data = $this->entityData($source, $slug);
      $copy = $this->schema->createEntity($data);
      $result = ['entity' => $copy, 'project' => $target, 'records' => 0, 'media' => 0, 'cleared' => []];
      if ($withRecords) {
        try {
          $result = $this->copyRecords($source, $copy, $sourceProject, $target) + $result;
        } catch (Throwable $e) {
          // No half copies: without its records the new entity goes again
          $this->schema->deleteEntity($copy->id);
          throw $e;
        }
      }
      return $result;
    } finally {
      $this->currentProject->set($sourceProject);
      $this->entities->reset();
    }
  }

  /**
   * Data for SchemaService::createEntity in the target project. References point to the entity
   * with the same slug there - it must exist (copy it first). Field groups are taken by name or
   * copied.
   */
  private function entityData(EntityDefinition $source, string $slug): array
  {
    $missing = [];
    $fields = [];
    foreach ($source->fields as $field) {
      $fields[] = $this->fieldData($field, $source, $slug, $missing);
    }
    if ([] !== $missing) {
      throw ValidationException::field('project', I18n::t(
        'The target project lacks {entities}, which "{entity}" references. Please copy them there first.',
        ['entities' => implode(', ', array_unique($missing)), 'entity' => $source->name],
      ));
    }
    return [
      'slug' => $slug,
      'name' => $source->name,
      'description' => $source->description,
      'access' => $source->access->value,
      'trash' => $source->trash,
      'drafts' => $source->drafts,
      'revisions' => $source->revisions,
      'label_field' => $source->labelField,
      'tree_field' => $source->treeField,
      'unique_together' => $source->uniqueTogether,
      'fields' => $fields,
    ];
  }

  /**
   * @param list<string> $missing names of referenced entities the target project does not have
   */
  private function fieldData(FieldDefinition $field, EntityDefinition $source, string $slug, array &$missing): array
  {
    $data = $field->toArray();
    unset($data['id'], $data['sort_order']);
    if (FieldType::Reference === $field->type) {
      if ($field->referenceEntityId === $source->id) {
        $data['reference'] = $slug;
      } elseif (null === $this->entities->findBySlug((string)$field->referenceEntity)) {
        $missing[] = (string)$field->referenceEntity;
      }
    }
    if (FieldType::Group === $field->type && null !== $field->group && !$field->isBlocks()) {
      $data['group'] = $this->targetGroup($field->group, $missing)->id;
    }
    if ($field->isBlocks()) {
      $data['blocks'] = array_map(fn(FieldGroup $group): string => $this->targetGroup($group, $missing)->id, array_values($field->blocks));
    }
    return $data;
  }

  /**
   * The group of the same name in the target project, or a copy (with the groups it contains).
   *
   * @param list<string> $missing
   */
  private function targetGroup(FieldGroup $group, array &$missing, int $depth = 0): FieldGroup
  {
    $existing = $this->entities->findGroup($group->name);
    if (null !== $existing) {
      return $existing;
    }
    if ($depth > FieldGroup::MAX_DEPTH) {
      throw new UserFacingException(I18n::t('The field group "{group}" is nested too deeply.', ['group' => $group->label]));
    }
    $fields = [];
    foreach ($group->fields as $field) {
      $data = $field->toArray();
      unset($data['id'], $data['sort_order']);
      if (FieldType::Reference === $field->type && null === $this->entities->findBySlug((string)$field->referenceEntity)) {
        $missing[] = (string)$field->referenceEntity;
      }
      if (FieldType::Group === $field->type && null !== $field->group && !$field->isBlocks()) {
        $data['group'] = $this->targetGroup($field->group, $missing, $depth + 1)->id;
      }
      if ($field->isBlocks()) {
        $data['blocks'] = array_map(fn(FieldGroup $inner): string => $this->targetGroup($inner, $missing, $depth + 1)->id, array_values($field->blocks));
      }
      $fields[] = $data;
    }
    if ([] !== $missing) {
      // Reported by entityData - nothing is created
      return $group;
    }
    return $this->groups->create(['name' => $group->name, 'label' => $group->label, 'kind' => $group->kind, 'category' => $group->category, 'description' => $group->description, 'fields' => $fields]);
  }

  /**
   * @return array{records: int, media: int, cleared: array<string, int>}
   */
  private function copyRecords(EntityDefinition $source, EntityDefinition $copy, Project $sourceProject, Project $target): array
  {
    $mediaMap = [];
    $cleared = [];
    $mediaCount = 0;
    $tree = $copy->treeField();
    // Records of the referenced entities in the target project: only these ids can stay
    $known = [];
    $exists = function (FieldDefinition $field, string $id) use (&$known, $source, $copy): bool {
      if ($field->referenceEntityId === $source->id || $field->referenceEntityId === $copy->id) {
        return true;
      }
      $targetEntity = $this->entities->findBySlug((string)$field->referenceEntity);
      if (null === $targetEntity) {
        return false;
      }
      $known[$targetEntity->id] ??= array_fill_keys(array_map('strval', $this->db->createQuery()->select('id')->from($targetEntity->tableName())->column()), true);
      return isset($known[$targetEntity->id][$id]);
    };
    $copyMedia = function (string $id) use (&$mediaMap, &$mediaCount, $sourceProject, $target): ?string {
      if (array_key_exists($id, $mediaMap)) {
        return $mediaMap[$id];
      }
      $row = $this->db->createQuery()->from('media')->where(['id' => $id, 'project_id' => $sourceProject->id])->one();
      $source = null !== $row ? $this->storages->find((string)$row['disk']) : null;
      if (null === $source) {
        return $mediaMap[$id] = null;
      }
      $newId = Id::new();
      $extension = pathinfo((string)$row['path'], PATHINFO_EXTENSION);
      $path = $target->slug.'/'.date('Y/m').'/'.$newId.('' !== $extension ? '.'.$extension : '');
      // Into the storage of the target project: copied inside one storage, transferred between two
      $storage = $this->storages->forProject($target);
      if ($storage->disk() === $source->disk()) {
        $storage->copy((string)$row['path'], $path);
      } else {
        $stream = $source->read((string)$row['path']);
        if (null === $stream) {
          return $mediaMap[$id] = null;
        }
        try {
          $storage->put($path, $stream, (string)$row['mime_type']);
        } finally {
          fclose($stream);
        }
      }
      $this->db->createCommand()->insert('media', ['id' => $newId, 'path' => $path, 'disk' => $storage->disk(), 'project_id' => $target->id, 'created_at' => date('Y-m-d H:i:s')] + array_diff_key($row, array_flip(['id', 'path', 'disk', 'project_id', 'created_at'])))->execute();
      $mediaCount++;
      return $mediaMap[$id] = $newId;
    };
    $clear = static function (FieldDefinition $field) use (&$cleared): void {
      $cleared[$field->label] = ($cleared[$field->label] ?? 0) + 1;
    };

    $count = 0;
    $parents = [];
    $offset = 0;
    do {
      $rows = $this->db->createQuery()->from($source->tableName())->orderBy(['created_at' => SORT_ASC, 'id' => SORT_ASC])->offset($offset)->limit(self::CHUNK)->all();
      $offset += self::CHUNK;
      $insert = [];
      foreach ($rows as $row) {
        $values = $this->systemValues($copy, $row);
        foreach ($copy->fields as $field) {
          foreach ($this->languageColumns($source, $copy, $field) as $targetColumn => $sourceColumn) {
            $value = $row[$sourceColumn] ?? null;
            $values[$targetColumn] = null === $value ? null : $this->mapValue($field, $value, $exists, $copyMedia, $clear);
          }
        }
        // Parents come in a second step: they may be later in the table
        if (null !== $tree && null !== ($values[$tree->name] ?? null)) {
          $parents[(string)$row['id']] = $values[$tree->name];
          $values[$tree->name] = null;
        }
        $insert[] = $values;
      }
      if ([] !== $insert) {
        $this->db->createCommand()->insertBatch($copy->tableName(), array_map('array_values', $insert), array_keys($insert[0]))->execute();
        $count += count($insert);
      }
    } while (count($rows) === self::CHUNK);

    foreach ($parents as $id => $parent) {
      $this->db->createCommand()->update($copy->tableName(), [(string)$tree?->name => $parent], ['id' => $id])->execute();
    }
    return ['records' => $count, 'media' => $mediaCount, 'cleared' => $cleared];
  }

  /**
   * id, times and actors - and the trash columns if both have a trash.
   */
  private function systemValues(EntityDefinition $copy, array $row): array
  {
    $values = [];
    foreach (['id', 'created_at', 'updated_at', EntityDefinition::CREATED_BY, EntityDefinition::UPDATED_BY] as $column) {
      $values[$column] = $row[$column] ?? null;
    }
    if ($copy->trash) {
      $values[EntityDefinition::DELETED_AT] = $row[EntityDefinition::DELETED_AT] ?? null;
      $values[EntityDefinition::DELETED_BY] = $row[EntityDefinition::DELETED_BY] ?? null;
    }
    return $values;
  }

  /**
   * Target column => source column. Translatable fields: per language of the target project,
   * from the same language of the source - missing languages from the source's default.
   *
   * @return array<string, string>
   */
  private function languageColumns(EntityDefinition $source, EntityDefinition $copy, FieldDefinition $field): array
  {
    if (!$field->translatable) {
      return [$field->name => $field->name];
    }
    $sourceColumn = static fn(string $language): string => in_array($language, $source->otherLanguages(), true) ? $field->translationColumn($language) : $field->name;
    $columns = [];
    $default = $copy->defaultLanguage();
    if (null !== $default) {
      $columns[$field->name] = $sourceColumn($default);
    } else {
      $columns[$field->name] = $field->name;
    }
    foreach ($copy->otherLanguages() as $language) {
      if (in_array($language, $source->languages, true)) {
        $columns[$field->translationColumn($language)] = $sourceColumn($language);
      }
    }
    return $columns;
  }

  /**
   * @param callable(FieldDefinition, string): bool $exists
   * @param callable(string): ?string $copyMedia
   * @param callable(FieldDefinition): void $clear
   */
  private function mapValue(FieldDefinition $field, mixed $value, callable $exists, callable $copyMedia, callable $clear): mixed
  {
    $one = function (FieldDefinition $inner, mixed $item) use ($exists, $copyMedia, $clear): ?string {
      if (null === $item || '' === (string)$item) {
        return null;
      }
      if (FieldType::Media === $inner->type) {
        return $copyMedia((string)$item) ?? $this->cleared($inner, $clear);
      }
      return $exists($inner, (string)$item) ? (string)$item : $this->cleared($inner, $clear);
    };
    $matches = static fn(FieldDefinition $inner): bool => FieldType::Reference === $inner->type || FieldType::Media === $inner->type;

    if (FieldType::Group === $field->type) {
      $mapped = GroupValues::map($field, $value, $matches, $one);
      return null === $mapped ? null : json_encode($mapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!$matches($field)) {
      return $value;
    }
    if ($field->repeatable) {
      $list = is_string($value) ? json_decode($value, true) : $value;
      $mapped = array_values(array_filter(array_map(static fn($item) => $one($field, $item), is_array($list) ? $list : []), static fn($item): bool => null !== $item));
      return [] === $mapped ? null : json_encode($mapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $one($field, $value);
  }

  /**
   * @param callable(FieldDefinition): void $clear
   */
  private function cleared(FieldDefinition $field, callable $clear): null
  {
    $clear($field);
    return null;
  }
}
