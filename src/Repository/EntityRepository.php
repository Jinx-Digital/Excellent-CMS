<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldGroup;
use App\Application\Service\CurrentProject;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Entity and field definitions. All of them are loaded at once (a CMS has tens, not thousands of
 * entities) and kept for the rest of the request; every write resets that cache.
 *
 * Reads only see the entities of the current project (CurrentProject) - references, imports, API
 * clients etc. can never reach into another project. Without a project (console) all are visible.
 */
final class EntityRepository
{
  /** @var array<string, EntityDefinition>|null by id, ordered */
  private ?array $cache = null;
  /** @var array<string, FieldGroup>|null by id (all projects) */
  private ?array $groupCache = null;
  private ?string $globalProjectId = null;

  public function __construct(
    private ConnectionInterface $db,
    private CurrentProject $currentProject,
    private ?\App\Plugin\PluginManager $plugins = null,
  ) {
  }

  /**
   * @return list<EntityDefinition>
   */
  public function all(): array
  {
    return array_values($this->load());
  }

  /**
   * Entities of all projects (e.g. the media cleanup must know every file in use).
   *
   * @return list<EntityDefinition>
   */
  public function allProjects(): array
  {
    return array_values($this->loadAll());
  }

  public function findById(string $id): ?EntityDefinition
  {
    return $this->load()[$id] ?? null;
  }

  public function findBySlug(string $slug): ?EntityDefinition
  {
    foreach ($this->load() as $entity) {
      if ($entity->slug === $slug) {
        return $entity;
      }
    }
    return null;
  }

  /**
   * Fields of other entities (or the entity itself) that point to the given entity.
   *
   * @return list<array{entity: EntityDefinition, field: FieldDefinition}>
   */
  public function referencesTo(string $entityId): array
  {
    $result = [];
    // Global entities are referenced from every project
    $target = $this->loadAll()[$entityId] ?? null;
    foreach (null !== $target && $target->global ? $this->loadAll() : $this->load() as $entity) {
      foreach ($entity->fields as $field) {
        // Also group fields whose group points to the entity somewhere inside
        if ($field->referenceEntityId === $entityId || [] !== array_filter($field->groups(), static fn(FieldGroup $group): bool => $group->referencesEntity($entityId))) {
          $result[] = ['entity' => $entity, 'field' => $field];
        }
      }
    }
    return $result;
  }

  /**
   * Field groups of the current project.
   *
   * @return list<FieldGroup>
   */
  public function groups(): array
  {
    $this->loadAll();
    $projectId = $this->currentProject->id();
    return array_values(array_filter((array)$this->groupCache, fn(FieldGroup $group): bool => !$this->currentProject->isScoped() || $group->projectId === $projectId));
  }

  public function findGroup(string $idOrName): ?FieldGroup
  {
    foreach ($this->groups() as $group) {
      if ($group->id === $idOrName || $group->name === $idOrName) {
        return $group;
      }
    }
    return null;
  }

  public function saveGroup(FieldGroup $group, bool $isNew): void
  {
    $now = date('Y-m-d H:i:s');
    $row = $group->toRow();
    if ($isNew) {
      $this->db->createCommand()->insert('field_group', $row + ['created_at' => $now, 'updated_at' => $now])->execute();
    } else {
      unset($row['id'], $row['project_id']);
      $this->db->createCommand()->update('field_group', $row + ['updated_at' => $now], ['id' => $group->id])->execute();
    }
    $this->reset();
  }

  public function deleteGroup(string $id): void
  {
    $this->db->createCommand()->delete('field_group', ['id' => $id])->execute();
    $this->reset();
  }

  /**
   * Fields (of entities or groups) that use the group.
   *
   * @return list<FieldDefinition>
   */
  public function fieldsUsingGroup(string $groupId): array
  {
    $this->loadAll();
    $result = [];
    foreach (array_merge(...array_map(static fn(EntityDefinition $e): array => $e->fields, array_values((array)$this->cache)), ...array_map(static fn(FieldGroup $g): array => $g->fields, array_values((array)$this->groupCache))) as $field) {
      if ($field->fieldGroupId === $groupId || in_array($groupId, $field->blockGroupIds, true)) {
        $result[] = $field;
      }
    }
    return $result;
  }

  public function insert(EntityDefinition $entity): void
  {
    $now = date('Y-m-d H:i:s');
    $this->db->createCommand()->insert('entity', $entity->toRow() + ['created_at' => $now, 'updated_at' => $now])->execute();
    foreach ($entity->fields as $field) {
      $this->insertField($field);
    }
    $this->reset();
  }

  public function update(EntityDefinition $entity): void
  {
    $row = $entity->toRow();
    unset($row['id']);
    $this->db->createCommand()->update('entity', $row + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $entity->id])->execute();
    $this->reset();
  }

  public function delete(string $id): void
  {
    $this->db->createCommand()->delete('entity', ['id' => $id])->execute();
    $this->reset();
  }

  public function insertField(FieldDefinition $field): void
  {
    $now = date('Y-m-d H:i:s');
    $this->db->createCommand()->insert('entity_field', $field->toRow() + ['created_at' => $now, 'updated_at' => $now])->execute();
    $this->reset();
  }

  public function updateField(FieldDefinition $field): void
  {
    $row = $field->toRow();
    unset($row['id'], $row['entity_id'], $row['group_id']);
    $this->db->createCommand()->update('entity_field', $row + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $field->id])->execute();
    $this->reset();
  }

  public function deleteField(string $fieldId): void
  {
    $this->db->createCommand()->delete('entity_field', ['id' => $fieldId])->execute();
    $this->reset();
  }

  public function nextSortOrder(): int
  {
    return (int)$this->db->createQuery()->from('entity')->where(['project_id' => $this->currentProject->id()])->max('sort_order') + 1;
  }

  public function reset(): void
  {
    $this->cache = null;
    $this->groupCache = null;
  }

  /**
   * @return array<string, EntityDefinition> entities of the current project
   */
  private function load(): array
  {
    if (!$this->currentProject->isScoped()) {
      return $this->loadAll();
    }
    $projectId = $this->currentProject->id();
    // Entities of the area "Global" belong to every project
    return array_filter($this->loadAll(), static fn(EntityDefinition $entity): bool => $entity->projectId === $projectId || $entity->global);
  }

  /**
   * Id of the area "Global" (null before its migration).
   */
  public function globalProjectId(): ?string
  {
    $this->loadAll();
    return $this->globalProjectId;
  }

  /**
   * An entity of any project with this slug - project and global entities share one name space.
   */
  public function findBySlugAnywhere(string $slug): ?EntityDefinition
  {
    foreach ($this->loadAll() as $entity) {
      if ($entity->slug === $slug) {
        return $entity;
      }
    }
    return null;
  }

  /**
   * @return array<string, EntityDefinition>
   */
  private function loadAll(): array
  {
    if (null !== $this->cache) {
      return $this->cache;
    }
    // Field types of plugins are known from here on (values, search, media, schema)
    $this->plugins?->registry();

    // All columns: is_global does not exist yet while older migrations run
    $projects = array_column($this->db->createQuery()->from('project')->all(), null, 'id');
    $this->globalProjectId = null;
    foreach ($projects as $project) {
      if ((bool)($project['is_global'] ?? false)) {
        $this->globalProjectId = (string)$project['id'];
      }
    }
    $entityRows = $this->db->createQuery()->from('entity')->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC])->all();
    $slugs = array_column($entityRows, 'slug', 'id');

    $fieldsByEntity = [];
    $fieldsByGroup = [];
    $all = [];
    $fieldRows = $this->db->createQuery()->from('entity_field')->orderBy(['sort_order' => SORT_ASC, 'created_at' => SORT_ASC])->all();
    foreach ($fieldRows as $row) {
      $reference = null !== $row['reference_entity_id'] ? ($slugs[$row['reference_entity_id']] ?? null) : null;
      $field = $all[] = FieldDefinition::fromRow($row, $reference);
      null !== $field->groupId ? $fieldsByGroup[$field->groupId][] = $field : $fieldsByEntity[$row['entity_id']][] = $field;
    }

    // Field groups, then every field of type "group" gets its group (also inside groups)
    $this->groupCache = [];
    foreach ($this->db->createQuery()->from('field_group')->orderBy(['sort_order' => SORT_ASC, 'label' => SORT_ASC])->all() as $row) {
      $group = FieldGroup::fromRow($row);
      $group->fields = $fieldsByGroup[$group->id] ?? [];
      $this->groupCache[$group->id] = $group;
    }
    // The project of every field: of its entity or its group
    $entityProjects = array_column($entityRows, 'project_id', 'id');
    foreach ($all as $field) {
      if (null !== $field->fieldGroupId) {
        $field->group = $this->groupCache[$field->fieldGroupId] ?? null;
      }
      // Blocks: their groups by name, in the order of the field - then all blocks of its categories ("*": every
      // block; of the same project; never the block the field is in)
      $field->blocks = [];
      foreach ($field->blockGroupIds as $blockGroupId) {
        if (isset($this->groupCache[$blockGroupId])) {
          $field->blocks[$this->groupCache[$blockGroupId]->name] = $this->groupCache[$blockGroupId];
        }
      }
      if ([] !== $field->blockCategories) {
        $projectId = null !== $field->groupId ? ($this->groupCache[$field->groupId]->projectId ?? null) : ($entityProjects[$field->entityId] ?? null);
        foreach ($this->groupCache as $group) {
          if ($group->isBlock() && $group->projectId === $projectId && $group->id !== $field->groupId && (in_array('*', $field->blockCategories, true) || in_array($group->category, $field->blockCategories, true))) {
            $field->blocks[$group->name] ??= $group;
          }
        }
      }
    }

    $this->cache = [];
    foreach ($entityRows as $row) {
      $project = $projects[$row['project_id']] ?? null;
      $languages = json_decode((string)($project['languages'] ?? ''), true);
      $entity = $this->cache[(string)$row['id']] = EntityDefinition::fromRow($row, $fieldsByEntity[$row['id']] ?? [], (string)($project['table_prefix'] ?? ''), is_array($languages) ? $languages : []);
      $entity->global = null !== $project && (bool)($project['is_global'] ?? false);
    }
    return $this->cache;
  }
}
