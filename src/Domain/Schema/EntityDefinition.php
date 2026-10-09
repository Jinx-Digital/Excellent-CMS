<?php

declare(strict_types=1);

namespace App\Domain\Schema;

/**
 * A content type ("Entity"): its records live in the table _<project prefix><slug>, its fields are the columns.
 */
final class EntityDefinition
{
  /** Before the prefix of the project in the names of content tables */
  public const CONTENT_PREFIX = '_';

  /** Belongs to the area "Global": every project sees, references and serves it */
  public bool $global = false;

  /** Column of the content table when drafts are on: 1 = draft, not delivered by the content API */
  public const DRAFT = 'draft';
  /** Column of the content table when the trash is on: set = in the trash */
  public const DELETED_AT = 'deleted_at';
  /** Who created / last changed / deleted a record: "user:<id>" or "client:<id>" */
  public const CREATED_BY = 'created_by';
  public const UPDATED_BY = 'updated_by';
  public const DELETED_BY = 'deleted_by';

  /**
   * @param list<FieldDefinition> $fields
   * @param list<list<string>> $uniqueTogether
   */
  public function __construct(
    public readonly string $id,
    public string $slug,
    public string $name,
    public ?string $description = null,
    public Access $access = Access::Public,
    public ?string $labelField = null,
    public array $uniqueTogether = [],
    public int $sortOrder = 0,
    public array $fields = [],
    public ?string $createdAt = null,
    /** Deleted records go to the trash first */
    public bool $trash = false,
    /** Name of the reference field to this entity that makes the records a tree ("parent") */
    public ?string $treeField = null,
    public string $projectId = '',
    /** Table prefix of the project: the content table is <tablePrefix><slug> */
    public string $tablePrefix = '',
    /** Languages of the project, the default language first (empty = one language) */
    public array $languages = [],
    /** Records can be saved as drafts: visible in the admin app, not in the content API */
    public bool $drafts = false,
    /** Every change of a record is kept as revision (history, compare, restore) */
    public bool $revisions = false,
    /** Address of the website that shows a record as preview: {{id}}, {{token}}, {{lang}}, {{record.slug}} … (see PreviewService) */
    public ?string $previewUrl = null,
    /** @var list<array{key: string, label: string, fields: list<string>}> tabs of the record form, as stored (see formTabs()) */
    public array $tabs = [],
    /** The plugin that created it: it cannot be deleted or renamed, its locked fields stay */
    public ?string $managedBy = null,
  ) {
  }

  /**
   * The tabs of the record form: as designed, every field in exactly one - fields no tab names
   * (e.g. new ones) are in the first. Without a design: one tab with all fields.
   *
   * @return list<array{key: string, label: string, fields: list<string>}>
   */
  public function formTabs(): array
  {
    $names = array_map(static fn(FieldDefinition $field): string => $field->name, $this->fields);
    $tabs = [] !== $this->tabs ? $this->tabs : [['key' => 'main', 'label' => '', 'fields' => []]];
    $seen = [];
    foreach ($tabs as $i => $tab) {
      $tabs[$i]['fields'] = array_values(array_filter($tab['fields'], static function (string $name) use ($names, &$seen): bool {
        $keep = in_array($name, $names, true) && !isset($seen[$name]);
        $seen[$name] = true;
        return $keep;
      }));
    }
    array_push($tabs[0]['fields'], ...array_values(array_filter($names, static fn(string $name): bool => !isset($seen[$name]))));
    return $tabs;
  }

  /**
   * @param list<FieldDefinition> $fields
   */
  public static function fromRow(array $row, array $fields, string $tablePrefix, array $languages = []): self
  {
    $uniqueTogether = json_decode((string)($row['unique_together'] ?? ''), true);
    return new self(
      id: (string)$row['id'],
      slug: (string)$row['slug'],
      name: (string)$row['name'],
      description: null !== $row['description'] ? (string)$row['description'] : null,
      access: Access::tryFrom((string)$row['access']) ?? Access::OAuth,
      labelField: null !== $row['label_field'] ? (string)$row['label_field'] : null,
      uniqueTogether: is_array($uniqueTogether) ? array_values(array_filter($uniqueTogether, 'is_array')) : [],
      sortOrder: (int)$row['sort_order'],
      fields: $fields,
      createdAt: null !== $row['created_at'] ? (string)$row['created_at'] : null,
      trash: (bool)($row['has_trash'] ?? false),
      treeField: isset($row['tree_field']) ? (string)$row['tree_field'] : null,
      projectId: (string)($row['project_id'] ?? ''),
      tablePrefix: $tablePrefix,
      languages: $languages,
      drafts: (bool)($row['has_drafts'] ?? false),
      revisions: (bool)($row['has_revisions'] ?? false),
      previewUrl: isset($row['preview_url']) && '' !== $row['preview_url'] ? (string)$row['preview_url'] : null,
      tabs: self::tabsFrom($row['form_tabs'] ?? null),
      managedBy: isset($row['managed_by']) && '' !== $row['managed_by'] ? (string)$row['managed_by'] : null,
    );
  }

  /**
   * @return list<array{key: string, label: string, fields: list<string>}>
   */
  private static function tabsFrom(mixed $json): array
  {
    $tabs = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($tabs)) {
      return [];
    }
    return array_values(array_map(static fn(array $tab): array => [
      'key' => (string)($tab['key'] ?? ''),
      'label' => (string)($tab['label'] ?? ''),
      'fields' => array_values(array_map('strval', array_filter((array)($tab['fields'] ?? []), 'is_string'))),
    ], array_filter($tabs, 'is_array')));
  }

  public function toRow(): array
  {
    return [
      'id' => $this->id,
      'slug' => $this->slug,
      'name' => $this->name,
      'description' => $this->description,
      'access' => $this->access->value,
      'label_field' => $this->labelField,
      'unique_together' => [] !== $this->uniqueTogether ? json_encode($this->uniqueTogether) : null,
      'sort_order' => $this->sortOrder,
      'has_trash' => $this->trash,
      'has_drafts' => $this->drafts,
      'has_revisions' => $this->revisions,
      'preview_url' => $this->previewUrl,
      'managed_by' => $this->managedBy,
      'form_tabs' => [] !== $this->tabs ? json_encode($this->tabs, JSON_UNESCAPED_UNICODE) : null,
      'tree_field' => $this->treeField,
      'project_id' => $this->projectId,
    ];
  }

  /**
   * The parent field if the records form a tree.
   */
  public function treeField(): ?FieldDefinition
  {
    $field = null !== $this->treeField ? $this->field($this->treeField) : null;
    return null !== $field && $this->isSelfReference($field) && !$field->repeatable ? $field : null;
  }

  /**
   * The field of type "order" that orders the records (drag & drop, default sort) - at most one.
   */
  public function orderField(): ?FieldDefinition
  {
    foreach ($this->fields as $field) {
      if (FieldType::Order === $field->type) {
        return $field;
      }
    }
    return null;
  }

  public function isSelfReference(FieldDefinition $field): bool
  {
    return FieldType::Reference === $field->type && $field->referenceEntityId === $this->id;
  }

  public function defaultLanguage(): ?string
  {
    return $this->languages[0] ?? null;
  }

  /**
   * Languages that have their own columns for translatable fields.
   *
   * @return list<string>
   */
  public function otherLanguages(): array
  {
    return array_values(array_slice($this->languages, 1));
  }

  /**
   * @return list<FieldDefinition>
   */
  public function translatableFields(): array
  {
    return array_values(array_filter($this->fields, static fn(FieldDefinition $field): bool => $field->translatable));
  }

  /**
   * Column of a field in a language: the field's own column for the default language.
   */
  public function column(FieldDefinition $field, ?string $language): string
  {
    return $field->translatable && null !== $language && $language !== $this->defaultLanguage() && in_array($language, $this->languages, true)
      ? $field->translationColumn($language)
      : $field->name;
  }

  public function tableName(): string
  {
    // Without the project's prefix the table could hit a table of another project
    if ('' === $this->tablePrefix) {
      throw new \LogicException(sprintf('Entity "%s" has no table prefix.', $this->slug));
    }
    return self::contentTable($this->tablePrefix, $this->slug);
  }

  /**
   * The content table of an entity: "_" (content - never a system table), the prefix of the
   * project, the slug - e.g. _lib_books. Plugins that read content tables use it too.
   */
  public static function contentTable(string $projectPrefix, string $slug): string
  {
    return self::CONTENT_PREFIX.$projectPrefix.$slug;
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
   * @return array<string, FieldDefinition>
   */
  public function fieldsByName(): array
  {
    $result = [];
    foreach ($this->fields as $field) {
      $result[$field->name] = $field;
    }
    return $result;
  }

  /**
   * Field used to show a record in lists and reference pickers: the configured one, otherwise the
   * first short text field.
   */
  public function displayField(): ?string
  {
    if (null !== $this->labelField && null !== $this->field($this->labelField)) {
      return $this->labelField;
    }
    foreach ($this->fields as $field) {
      if ((FieldType::String === $field->type || FieldType::Email === $field->type) && !$field->repeatable) {
        return $field->name;
      }
    }
    return null;
  }

  public function toArray(bool $withFields = true): array
  {
    $data = [
      'id' => $this->id,
      'slug' => $this->slug,
      'name' => $this->name,
      'description' => $this->description,
      'access' => $this->access->value,
      'label_field' => $this->displayField(),
      'unique_together' => $this->uniqueTogether,
      'sort_order' => $this->sortOrder,
      'trash' => $this->trash,
      'drafts' => $this->drafts,
      'revisions' => $this->revisions,
      'preview_url' => $this->previewUrl,
      'managed_by' => $this->managedBy,
      // How the admin app arranges the fields in tabs (always at least one)
      'tabs' => $this->formTabs(),
      'tree_field' => $this->treeField()?->name,
      'order_field' => $this->orderField()?->name,
      'languages' => $this->languages,
      // Shared by all projects (area "Global")
      'global' => $this->global,
      'created_at' => $this->createdAt,
    ];
    if ($withFields) {
      $data['fields'] = array_map(static fn(FieldDefinition $field): array => $field->toArray(), $this->fields);
    }
    return $data;
  }
}
