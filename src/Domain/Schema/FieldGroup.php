<?php

declare(strict_types=1);

namespace App\Domain\Schema;

/**
 * A reusable set of fields of a project - of one of two kinds:
 *
 * - field group (KIND_GROUP, e.g. "seo" = keywords + description): entities and blocks use it with a
 *   field of type "group", whose value is an object with these fields - or a list of objects if the
 *   field is repeatable;
 * - block (KIND_BLOCK, e.g. "hero"): an item of block lists (page builder), chosen only there.
 *
 * Both contain fields of any type - field groups and block lists too (nested blocks), never themselves.
 */
final class FieldGroup
{
  public const KIND_GROUP = 'group';
  public const KIND_BLOCK = 'block';

  /** Deeper nesting is not shown (guards against loops in broken data) */
  public const MAX_DEPTH = 10;

  /**
   * @param list<FieldDefinition> $fields
   */
  public function __construct(
    public readonly string $id,
    public readonly string $projectId,
    public string $name,
    public string $label,
    public ?string $description = null,
    public array $fields = [],
    public int $sortOrder = 0,
    public string $kind = self::KIND_GROUP,
    /** To organize them (lists, block picker) - null: none */
    public ?string $category = null,
    /** The plugin that created it: it cannot be deleted or renamed, its locked fields stay */
    public ?string $managedBy = null,
    /** Blocks: the template of their HTML (Twig, see BlockTemplates) */
    public ?string $template = null,
  ) {
  }

  public function isBlock(): bool
  {
    return self::KIND_BLOCK === $this->kind;
  }

  public static function fromRow(array $row): self
  {
    return new self(
      id: (string)$row['id'],
      projectId: (string)$row['project_id'],
      name: (string)$row['name'],
      label: (string)$row['label'],
      description: null !== ($row['description'] ?? null) ? (string)$row['description'] : null,
      sortOrder: (int)$row['sort_order'],
      kind: self::KIND_BLOCK === ($row['kind'] ?? null) ? self::KIND_BLOCK : self::KIND_GROUP,
      category: isset($row['category']) && '' !== $row['category'] ? (string)$row['category'] : null,
      managedBy: isset($row['managed_by']) && '' !== $row['managed_by'] ? (string)$row['managed_by'] : null,
      template: isset($row['template']) && '' !== $row['template'] ? (string)$row['template'] : null,
    );
  }

  public function toRow(): array
  {
    return [
      'id' => $this->id,
      'project_id' => $this->projectId,
      'name' => $this->name,
      'label' => $this->label,
      'description' => $this->description,
      'sort_order' => $this->sortOrder,
      'kind' => $this->kind,
      'category' => $this->category,
      'managed_by' => $this->managedBy,
      'template' => $this->template,
    ];
  }

  public function field(string $name): ?FieldDefinition
  {
    foreach ($this->fields as $field) {
      if ($field->name === $name) {
        return $field;
      }
    }
    return null;
  }

  /**
   * Is $groupId this group or one nested in it (at any depth)? A group must not contain itself.
   */
  public function contains(string $groupId, int $depth = 0): bool
  {
    if ($this->id === $groupId) {
      return true;
    }
    foreach ($depth < self::MAX_DEPTH ? $this->fields : [] as $field) {
      // A block list taking every block ("*", e.g. the columns of Columns) nests on purpose - lists can be
      // empty, so it never becomes endless
      if (in_array('*', $field->blockCategories, true)) {
        continue;
      }
      foreach ($field->groups() as $inner) {
        if ($inner->contains($groupId, $depth + 1)) {
          return true;
        }
      }
    }
    return false;
  }

  /**
   * Does a field of this group (or of a nested one) point to the entity?
   */
  public function referencesEntity(string $entityId, int $depth = 0): bool
  {
    foreach ($depth < self::MAX_DEPTH ? $this->fields : [] as $field) {
      if (FieldType::Reference === $field->type && $field->referenceEntityId === $entityId) {
        return true;
      }
      foreach ($field->groups() as $inner) {
        if ($inner->referencesEntity($entityId, $depth + 1)) {
          return true;
        }
      }
    }
    return false;
  }

  public function toArray(int $depth = 0): array
  {
    return [
      'id' => $this->id,
      'name' => $this->name,
      'label' => $this->label,
      'kind' => $this->kind,
      'category' => $this->category,
      'managed_by' => $this->managedBy,
      'has_template' => null !== $this->template,
      'description' => $this->description,
      'fields' => $depth < self::MAX_DEPTH ? array_map(static fn(FieldDefinition $field): array => $field->toArray($depth + 1), $this->fields) : [],
    ];
  }
}
