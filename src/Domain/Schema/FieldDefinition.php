<?php

declare(strict_types=1);

namespace App\Domain\Schema;

final class FieldDefinition
{
  /** Blocks: the type of an item (name of its field group) and its id within the list */
  public const BLOCK_TYPE = '_type';
  public const BLOCK_KEY = '_key';

  /** Columns every content table has; fields cannot use these names */
  public const RESERVED = ['id', 'created_at', 'updated_at', EntityDefinition::DELETED_AT, EntityDefinition::DRAFT, EntityDefinition::CREATED_BY, EntityDefinition::UPDATED_BY, EntityDefinition::DELETED_BY];

  public function __construct(
    public readonly string $id,
    public readonly string $entityId,
    public string $name,
    public string $label,
    public FieldType $type,
    public ?int $length = null,
    public ?int $scale = null,
    public bool $required = false,
    public bool $unique = false,
    public ?string $referenceEntityId = null,
    /** Slug of the referenced entity (resolved by the repository) */
    public ?string $referenceEntity = null,
    public int $sortOrder = 0,
    /** Type "uuid": version of generated values */
    public ?int $uuidVersion = null,
    /** Type "media": allowed MIME types ("image/*", "application/pdf" ...), empty = all allowed */
    public array $mediaAccept = [],
    /** Type "slug": text field an empty slug is made from */
    public ?string $slugSource = null,
    /** A list of values (JSON) instead of one, with fewest/most items (null = no limit) */
    public bool $repeatable = false,
    /** Repeatable: the order of the items can be changed (drag & drop) */
    public bool $sortable = true,
    public ?int $repeatMin = null,
    public ?int $repeatMax = null,
    /** One value per language of the project (default language in the field's own column) */
    public bool $translatable = false,
    /** The field belongs to this field group (instead of an entity) */
    public ?string $groupId = null,
    /** Type "group": the group whose fields make up the value */
    public ?string $fieldGroupId = null,
    /** Type "group": resolved by the repository */
    public ?FieldGroup $group = null,
    /** Type "regex": the pattern (without delimiters) and the message when a value does not match */
    public ?string $pattern = null,
    public ?string $patternMessage = null,
    /** @var list<array{value: string, label: string}> type "enum": the allowed values with their labels */
    public array $options = [],
    /** @var list<string>|null roles (slugs) that may see the field - null: everyone who may read the entity */
    public ?array $readRoles = null,
    /** @var list<string>|null roles that may change it - null: everyone who may change records */
    public ?array $writeRoles = null,
    /** Part of the text search (?s=) */
    public bool $searchable = true,
    /** Can be used in filters (filter[field]) - fields that cannot are not searched either */
    public bool $filterable = true,
    /** Weight in the search (1 - 10): matches in heavier fields come first */
    public int $searchWeight = 1,
    /** @var list<string> type "group" as blocks: the field groups an item can be of (ids) - see isBlocks() */
    public array $blockGroupIds = [],
    /** @var list<string> blocks: all blocks of these categories too (also ones added later) */
    public array $blockCategories = [],
    /** @var array<string, FieldGroup> blocks: their groups by name, resolved by the repository */
    public array $blocks = [],
    /** Type "custom": the field type of a plugin ("geo.point") - see CustomFieldTypes */
    public ?string $customType = null,
    /** A plugin relies on it: cannot be deleted, renamed or change its type */
    public bool $locked = false,
    /** Numbers: the smallest and largest allowed value (null: no limit) */
    public int|float|null $minValue = null,
    public int|float|null $maxValue = null,
    /** Numbers with both limits: edited with a slider */
    public bool $slider = false,
  ) {
  }

  /**
   * The type as the API names it: the built-in one, or the plugin's ("geo.point").
   */
  public function typeName(): string
  {
    return FieldType::Custom === $this->type ? (string)$this->customType : $this->type->value;
  }

  /**
   * Blocks (page builder): a list of items, each one of several field groups -
   * [{"_type": "hero", "_key": "a1b2c3d4", "title": …}, {"_type": "text", …}].
   */
  public function isBlocks(): bool
  {
    return FieldType::Group === $this->type && ([] !== $this->blockGroupIds || [] !== $this->blockCategories);
  }

  /**
   * The group of one object of the value: the field's group, for blocks the one of its "_type".
   */
  public function groupFor(array $object): ?FieldGroup
  {
    return $this->isBlocks() ? ($this->blocks[(string)($object[self::BLOCK_TYPE] ?? '')] ?? null) : $this->group;
  }

  /**
   * Every group the value can contain.
   *
   * @return list<FieldGroup>
   */
  public function groups(): array
  {
    return $this->isBlocks() ? array_values($this->blocks) : (null !== $this->group ? [$this->group] : []);
  }

  /**
   * Searched by ?s= - only fields that can be filtered by
   */
  public function isSearchable(): bool
  {
    return $this->searchable && $this->filterable;
  }

  /**
   * Limited to roles (see FieldAccess)?
   */
  public function isRestricted(): bool
  {
    return null !== $this->readRoles || null !== $this->writeRoles;
  }

  /**
   * Type "enum": the allowed values.
   *
   * @return list<string>
   */
  public function optionValues(): array
  {
    return array_column($this->options, 'value');
  }

  public static function fromRow(array $row, ?string $referenceSlug = null): self
  {
    return new self(
      id: (string)$row['id'],
      entityId: (string)($row['entity_id'] ?? ''),
      name: (string)$row['name'],
      label: (string)$row['label'],
      type: FieldType::from((string)$row['type']),
      length: null !== $row['length'] ? (int)$row['length'] : null,
      scale: null !== $row['scale'] ? (int)$row['scale'] : null,
      required: (bool)$row['required'],
      unique: (bool)$row['is_unique'],
      referenceEntityId: null !== $row['reference_entity_id'] ? (string)$row['reference_entity_id'] : null,
      referenceEntity: $referenceSlug,
      sortOrder: (int)$row['sort_order'],
      uuidVersion: isset($row['uuid_version']) ? (int)$row['uuid_version'] : null,
      mediaAccept: self::decodeAccept($row['media_accept'] ?? null),
      slugSource: isset($row['slug_source']) ? (string)$row['slug_source'] : null,
      repeatable: (bool)($row['is_repeatable'] ?? false),
      sortable: (bool)($row['is_sortable'] ?? true),
      repeatMin: isset($row['repeat_min']) ? (int)$row['repeat_min'] : null,
      repeatMax: isset($row['repeat_max']) ? (int)$row['repeat_max'] : null,
      translatable: (bool)($row['is_translatable'] ?? false),
      groupId: isset($row['group_id']) ? (string)$row['group_id'] : null,
      fieldGroupId: isset($row['field_group_id']) ? (string)$row['field_group_id'] : null,
      pattern: isset($row['pattern']) ? (string)$row['pattern'] : null,
      patternMessage: isset($row['pattern_message']) ? (string)$row['pattern_message'] : null,
      options: self::decodeOptions($row['options'] ?? null),
      readRoles: self::decodeRoles($row['read_roles'] ?? null),
      writeRoles: self::decodeRoles($row['write_roles'] ?? null),
      searchable: (bool)($row['is_searchable'] ?? true),
      filterable: (bool)($row['is_filterable'] ?? true),
      searchWeight: max(1, min(10, (int)($row['search_weight'] ?? 1))),
      blockGroupIds: self::decodeAccept($row['block_groups'] ?? null),
      blockCategories: self::decodeAccept($row['block_categories'] ?? null),
      customType: isset($row['custom_type']) && '' !== $row['custom_type'] ? (string)$row['custom_type'] : null,
      locked: (bool)($row['is_locked'] ?? false),
      minValue: self::number($row['min_value'] ?? null),
      maxValue: self::number($row['max_value'] ?? null),
      slider: (bool)($row['is_slider'] ?? false),
    );
  }

  /**
   * A limit of a number field: int where it is whole (DECIMAL comes as "20.000000").
   */
  private static function number(mixed $value): int|float|null
  {
    if (null === $value || '' === $value || !is_numeric($value)) {
      return null;
    }
    $number = 0 + $value;
    return is_float($number) && floor($number) === $number && abs($number) < PHP_INT_MAX ? (int)$number : $number;
  }

  /**
   * Is it a number field (integer, decimal) - those can have limits and a slider.
   */
  public function isNumber(): bool
  {
    return in_array($this->type, [FieldType::Integer, FieldType::Decimal], true);
  }

  /**
   * @return list<string>|null
   */
  private static function decodeRoles(mixed $value): ?array
  {
    if (!is_string($value) || '' === $value) {
      return null;
    }
    $roles = json_decode($value, true);
    return is_array($roles) && [] !== $roles ? array_values(array_map('strval', $roles)) : null;
  }

  public function toRow(): array
  {
    return [
      'id' => $this->id,
      'entity_id' => '' !== $this->entityId ? $this->entityId : null,
      'group_id' => $this->groupId,
      'field_group_id' => FieldType::Group === $this->type && !$this->isBlocks() ? $this->fieldGroupId : null,
      'block_groups' => $this->isBlocks() && [] !== $this->blockGroupIds ? json_encode(array_values($this->blockGroupIds)) : null,
      'block_categories' => $this->isBlocks() && [] !== $this->blockCategories ? json_encode(array_values($this->blockCategories), JSON_UNESCAPED_UNICODE) : null,
      'custom_type' => FieldType::Custom === $this->type ? $this->customType : null,
      'is_locked' => $this->locked,
      'min_value' => $this->isNumber() ? $this->minValue : null,
      'max_value' => $this->isNumber() ? $this->maxValue : null,
      'is_slider' => $this->isNumber() && $this->slider,
      'pattern' => FieldType::Regex === $this->type ? $this->pattern : null,
      'pattern_message' => FieldType::Regex === $this->type ? $this->patternMessage : null,
      'options' => FieldType::Enum === $this->type ? json_encode($this->options, JSON_UNESCAPED_UNICODE) : null,
      'read_roles' => null !== $this->readRoles ? json_encode(array_values($this->readRoles)) : null,
      'write_roles' => null !== $this->writeRoles ? json_encode(array_values($this->writeRoles)) : null,
      'is_searchable' => $this->searchable,
      'is_filterable' => $this->filterable,
      'search_weight' => $this->searchWeight,
      'name' => $this->name,
      'label' => $this->label,
      'type' => $this->type->value,
      'length' => $this->type->hasLength() ? ($this->length ?? FieldType::DEFAULT_LENGTH) : null,
      'scale' => $this->type->hasScale() ? ($this->scale ?? FieldType::DEFAULT_SCALE) : null,
      'uuid_version' => $this->type->hasUuidVersion() ? $this->uuidVersion() : null,
      'media_accept' => $this->type->hasMediaAccept() && [] !== $this->mediaAccept ? json_encode(array_values($this->mediaAccept)) : null,
      'slug_source' => FieldType::Slug === $this->type ? $this->slugSource : null,
      'is_repeatable' => $this->repeatable,
      'is_sortable' => $this->sortable,
      'repeat_min' => $this->repeatable ? $this->repeatMin : null,
      'repeat_max' => $this->repeatable ? $this->repeatMax : null,
      'is_translatable' => $this->translatable && $this->type->canBeTranslated(),
      'required' => $this->required,
      'is_unique' => $this->unique,
      'reference_entity_id' => FieldType::Reference === $this->type ? $this->referenceEntityId : null,
      'sort_order' => $this->sortOrder,
    ];
  }

  /**
   * @return list<string> allowed MIME types of a media field, empty = all allowed
   */
  public function mediaAccept(): array
  {
    return array_values($this->mediaAccept);
  }

  /**
   * @return list<array{value: string, label: string}>
   */
  private static function decodeOptions(mixed $value): array
  {
    $list = is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : null);
    $options = [];
    foreach (is_array($list) ? $list : [] as $option) {
      if (is_array($option) && isset($option['value'])) {
        $options[] = ['value' => (string)$option['value'], 'label' => (string)($option['label'] ?? $option['value'])];
      }
    }
    return $options;
  }

  /**
   * @return list<string>
   */
  private static function decodeAccept(mixed $value): array
  {
    $list = is_string($value) ? json_decode($value, true) : null;
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
  }

  public function uuidVersion(): int
  {
    return $this->uuidVersion ?? FieldType::DEFAULT_UUID_VERSION;
  }

  /**
   * Value for a new record that leaves the field empty: a new UUID (type "uuid"), otherwise null.
   * Auto increment values are assigned by the database.
   */
  public function generateValue(): ?string
  {
    return FieldType::Uuid === $this->type ? FieldType::newUuid($this->uuidVersion()) : null;
  }

  /**
   * Values of a stored list (JSON) - empty for null or a single value.
   *
   * @return list<string|int|float|bool>
   */
  public static function decodeList(mixed $stored): array
  {
    $list = is_string($stored) && '' !== $stored ? json_decode($stored, true) : null;
    return is_array($list) ? array_values(array_filter($list, static fn($item): bool => null !== $item && '' !== $item)) : [];
  }

  /**
   * Column of a language other than the default one: title__en, title__de_at.
   */
  /**
   * PCRE of a pattern as the admin typed it: "^[A-Z]{2}-\d{4}$" -> "~^[A-Z]{2}-\d{4}$~u".
   */
  public static function regex(string $pattern): string
  {
    return '~'.str_replace('~', '\~', $pattern).'~u';
  }

  /**
   * Is the pattern a valid regular expression? (Checked when the field is saved.)
   */
  public static function isValidPattern(string $pattern): bool
  {
    return '' !== $pattern && false !== @preg_match(self::regex($pattern), '');
  }

  public function translationColumn(string $language): string
  {
    return $this->name.'__'.strtolower(str_replace('-', '_', $language));
  }

  /**
   * Media field holding a list of files (JSON list of ids, in their order).
   */
  public function isMultipleMedia(): bool
  {
    return FieldType::Media === $this->type && $this->repeatable;
  }

  public function column(): \Yiisoft\Db\Schema\Column\ColumnInterface
  {
    // Lists are JSON: MEDIUMTEXT, up to 16 MB
    return $this->repeatable ? \Yiisoft\Db\Schema\Column\ColumnBuilder::text(16777215)->null() : $this->type->column($this->length, $this->scale);
  }

  public function toArray(int $depth = 0): array
  {
    return [
      'id' => $this->id,
      'name' => $this->name,
      'label' => $this->label,
      'type' => $this->typeName(),
      // Field type of a plugin: label, web component and config for the admin app (null: plugin not active)
      'custom' => FieldType::Custom === $this->type ? CustomFieldTypes::describe($this->customType) : null,
      'locked' => $this->locked,
      // Numbers: the allowed range, edited with a slider
      'min_value' => $this->isNumber() ? $this->minValue : null,
      'max_value' => $this->isNumber() ? $this->maxValue : null,
      'slider' => $this->isNumber() && $this->slider,
      'length' => $this->type->hasLength() ? ($this->length ?? FieldType::DEFAULT_LENGTH) : null,
      'scale' => $this->type->hasScale() ? ($this->scale ?? FieldType::DEFAULT_SCALE) : null,
      'uuid_version' => $this->type->hasUuidVersion() ? $this->uuidVersion() : null,
      'media_accept' => $this->type->hasMediaAccept() ? array_values($this->mediaAccept) : null,
      'slug_source' => FieldType::Slug === $this->type ? $this->slugSource : null,
      'repeatable' => $this->repeatable,
      'sortable' => $this->repeatable && $this->sortable,
      'repeat_min' => $this->repeatable ? $this->repeatMin : null,
      'repeat_max' => $this->repeatable ? $this->repeatMax : null,
      'translatable' => $this->translatable,
      // Type "group": the group with its fields (nested groups too)
      'group' => FieldType::Group === $this->type && !$this->isBlocks() ? $this->group?->toArray($depth) : null,
      // Blocks: the groups an item can be of, in the order of the field
      'blocks' => $this->isBlocks() ? array_map(static fn(FieldGroup $group): array => $group->toArray($depth), array_values($this->blocks)) : null,
      // The categories it takes all blocks of (blocks: the chosen ones and these together)
      'block_categories' => $this->isBlocks() ? array_values($this->blockCategories) : null,
      'block_ids' => $this->isBlocks() ? array_values($this->blockGroupIds) : null,
      'pattern' => FieldType::Regex === $this->type ? $this->pattern : null,
      'pattern_message' => FieldType::Regex === $this->type ? $this->patternMessage : null,
      'options' => FieldType::Enum === $this->type ? $this->options : null,
      // Roles that may see / change the field, null: like the entity
      'read_roles' => $this->readRoles,
      'write_roles' => $this->writeRoles,
      // Text search (?s=) and filters (filter[field]); not filterable = not searched either
      'searchable' => $this->isSearchable(),
      'filterable' => $this->filterable,
      'search_weight' => $this->searchWeight,
      'required' => $this->required,
      'unique' => $this->unique,
      'reference' => $this->referenceEntity,
      'sort_order' => $this->sortOrder,
    ];
  }
}
