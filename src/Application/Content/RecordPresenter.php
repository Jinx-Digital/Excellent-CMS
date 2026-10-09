<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Media\MediaService;
use App\Application\Project\ProjectVariables;
use App\Domain\Schema\Access;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldGroup;
use App\Domain\Schema\GroupValues;
use App\Domain\Schema\CustomFieldTypes;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\ValueConverter;
use App\Repository\EntityRepository;
use App\Repository\RecordRepository;

/**
 * Turns database rows into API records: typed values (numbers, booleans) and, on request,
 * referenced records - always loaded for a whole page at once.
 */
final class RecordPresenter
{
  public function __construct(
    private EntityRepository $entityRepository,
    private RecordRepository $recordRepository,
    private MediaService $media,
    private ProjectVariables $variables,
    private \App\Repository\UserRepository $users,
    private \App\Repository\OAuthClientRepository $clients,
    private \App\Repository\EventRepository $events,
    /** Fields limited to roles are left out for whoever may not read them */
    private ?\App\Application\Access\FieldAccess $fieldAccess = null,
  ) {
  }

  /**
   * Replaces the file id of media fields by the file: {id, url, name, mime_type, size, width,
   * height, is_image} - loaded for a whole page at once.
   *
   * Files of a public entity get plain addresses, all others signed ones (see MediaService::present()).
   *
   * @param list<array> $records presented records
   * @param bool $signed always signed addresses (admin app: records in the trash are not public)
   * @return list<array>
   */
  public function withMedia(EntityDefinition $entity, array $records, bool $signed = false): array
  {
    $fields = [];
    $groups = [];
    $ids = [];
    $isMedia = static fn(FieldDefinition $field): bool => FieldType::Media === $field->type;
    // Fields of plugins (also inside groups and blocks): the files they use, then present() of the plugin
    $customIds = static function (FieldDefinition $groupField, mixed $value) use (&$ids): void {
      GroupValues::each($groupField, $value, static function (FieldDefinition $field, mixed $inner) use (&$ids): void {
        if (FieldType::Custom === $field->type) {
          array_push($ids, ...CustomFieldTypes::mediaIds($field, CustomFieldTypes::fromStorage($field, $inner)));
        }
      });
    };
    $customs = [];
    foreach ($entity->fields as $field) {
      if (FieldType::Custom === $field->type) {
        $customs[] = $field;
        foreach ($records as $record) {
          foreach ($this->customByLanguage($entity, $field, $record[$field->name] ?? null) as $value) {
            array_push($ids, ...CustomFieldTypes::mediaIds($field, $value));
          }
          foreach ((array)($record['_i18n'][$field->name] ?? []) as $value) {
            array_push($ids, ...CustomFieldTypes::mediaIds($field, $value));
          }
        }
        continue;
      }
      if (FieldType::Group === $field->type) {
        $groups[] = $field;
        foreach ($records as $record) {
          // Translatable groups with ?lang=all: an object per language
          foreach (self::byLanguage($field, $record[$field->name] ?? null) as $value) {
            array_push($ids, ...GroupValues::collect($field, $value, $isMedia));
            $customIds($field, $value);
          }
        }
        continue;
      }
      if (FieldType::Media !== $field->type) {
        continue;
      }
      $fields[] = $field;
      foreach ($records as $record) {
        array_push($ids, ...self::mediaIds($field, $record[$field->name] ?? null));
      }
    }
    if ([] === $fields && [] === $groups && [] === $customs) {
      return $records;
    }
    // Admin app: the translations (_i18n) of media and group fields too
    foreach ($records as $record) {
      foreach ((array)($record['_i18n'] ?? []) as $name => $byLanguage) {
        $field = $entity->field((string)$name);
        foreach (null !== $field ? (array)$byLanguage : [] as $value) {
          if (FieldType::Group === $field->type) {
            array_push($ids, ...GroupValues::collect($field, $value, $isMedia));
            $customIds($field, $value);
          } elseif (FieldType::Media === $field->type) {
            array_push($ids, ...self::mediaIds($field, $value));
          }
        }
      }
    }
    $files = [] !== $ids ? $this->media->presentMany($ids, !$signed && Access::Public === $entity->access) : [];
    $present = static function (FieldDefinition $field, mixed $value) use ($files): mixed {
      if (FieldType::Group === $field->type) {
        return self::groupMedia($field, $value, $files);
      }
      $list = array_values(array_filter(array_map(static fn(string $id): ?array => $files[$id] ?? null, self::mediaIds($field, $value))));
      return $field->isMultipleMedia() ? $list : ($list[0] ?? null);
    };
    foreach ($records as &$record) {
      foreach ($customs as $field) {
        if (array_key_exists($field->name, $record)) {
          $byLanguage = $this->isCustomLanguageMap($entity, $field, $record[$field->name]);
          $record[$field->name] = $byLanguage
            ? array_map(static fn($value) => CustomFieldTypes::present($field, $value, $files), $record[$field->name])
            : CustomFieldTypes::present($field, $record[$field->name], $files);
        }
        if (isset($record['_i18n'][$field->name])) {
          $record['_i18n'] = (array)$record['_i18n'];
          $record['_i18n'][$field->name] = (object)array_map(static fn($value) => CustomFieldTypes::present($field, $value, $files), (array)$record['_i18n'][$field->name]);
        }
      }
      foreach ($groups as $field) {
        if (array_key_exists($field->name, $record)) {
          $record[$field->name] = self::isLanguageMap($field, $record[$field->name])
            ? array_map(static fn($value) => self::groupMedia($field, $value, $files), $record[$field->name])
            : self::groupMedia($field, $record[$field->name], $files);
        }
      }
      foreach ($fields as $field) {
        if (!array_key_exists($field->name, $record)) {
          continue;
        }
        $list = array_values(array_filter(array_map(static fn(string $id): ?array => $files[$id] ?? null, self::mediaIds($field, $record[$field->name]))));
        // Several files: always a list (in their order); one file: the file or null
        $record[$field->name] = $field->isMultipleMedia() ? $list : ($list[0] ?? null);
      }
      if (isset($record['_i18n'])) {
        $translations = (array)$record['_i18n'];
        foreach ($translations as $name => $byLanguage) {
          $field = $entity->field((string)$name);
          if (null !== $field && (FieldType::Group === $field->type || FieldType::Media === $field->type)) {
            $translations[$name] = (object)array_map(static fn($value) => null !== $value ? $present($field, $value) : null, (array)$byLanguage);
          }
        }
        $record['_i18n'] = (object)$translations;
      }
    }
    unset($record);
    return $records;
  }

  /**
   * @param list<string>|null $fields Only these fields (null = all)
   */
  /**
   * @param string|null $language translatable fields in this language (empty ones fall back to the
   *   default language), "all" = an object with every language; null = the default language
   * @param bool $resolve fill in the project variables ("{{url}}/impressum" -> "https://…/impressum")
   */
  public function present(EntityDefinition $entity, array $row, ?array $fields = null, ?string $language = null, bool $resolve = false): array
  {
    $result = ['id' => (string)$row['id']];
    foreach ($entity->fields as $field) {
      if (null !== $fields && !in_array($field->name, $fields, true)) {
        continue;
      }
      if (null !== $this->fieldAccess && !$this->fieldAccess->canRead($field)) {
        continue;
      }
      $value = self::typed($field, $row[$field->name] ?? null);
      $byLanguage = false;
      if ($field->translatable && [] !== $entity->otherLanguages() && null !== $language) {
        if (self::ALL_LANGUAGES === $language) {
          $value = [(string)$entity->defaultLanguage() => $value] + $this->translations($entity, $field, $row);
          $byLanguage = true;
        } elseif ($language !== $entity->defaultLanguage()) {
          $translated = self::typed($field, $row[$field->translationColumn($language)] ?? null);
          $value = null !== $translated && [] !== $translated ? $translated : $value;
        }
      }
      if ($resolve) {
        $fill = fn(mixed $text, ?string $code): mixed => FieldType::Group === $field->type
          ? $this->resolveGroup($field, $text, $code)
          : (is_array($text) ? array_map(fn($item) => $this->variables->resolve($field, $item, $code), $text) : $this->variables->resolve($field, $text, $code));
        $value = $byLanguage
          ? array_combine(array_keys($value), array_map($fill, $value, array_map('strval', array_keys($value))))
          : $fill($value, self::ALL_LANGUAGES === $language ? null : $language);
      }
      $result[$field->name] = $value;
    }
    $result['created_at'] = $row['created_at'] ?? null;
    $result['updated_at'] = $row['updated_at'] ?? null;
    // Only records in the trash (admin app) have it
    if (null !== ($row[EntityDefinition::DELETED_AT] ?? null)) {
      $result[EntityDefinition::DELETED_AT] = $row[EntityDefinition::DELETED_AT];
    }
    return $result;
  }

  /**
   * A group value - or, for ?lang=all, its value per language.
   *
   * @return list<mixed>
   */
  private static function byLanguage(FieldDefinition $field, mixed $value): array
  {
    return self::isLanguageMap($field, $value) ? array_values($value) : [$value];
  }

  /**
   * Translatable group with ?lang=all: {"de": {...}, "en": {...}} instead of the group's object.
   */
  private static function isLanguageMap(FieldDefinition $field, mixed $value): bool
  {
    if (!$field->translatable || !is_array($value) || array_is_list($value) || [] === $field->groups()) {
      return false;
    }
    $names = [];
    foreach ($field->groups() as $group) {
      array_push($names, ...array_map(static fn(FieldDefinition $f): string => $f->name, $group->fields));
    }
    return [] === array_intersect(array_keys($value), $names) && [] !== $value;
  }

  /**
   * @return list<string>
   */
  public static function mediaIds(FieldDefinition $field, mixed $value): array
  {
    if (is_array($value)) {
      return array_values(array_filter(array_map('strval', array_filter($value, 'is_scalar'))));
    }
    if (!is_string($value) || '' === $value) {
      return [];
    }
    return $field->isMultipleMedia() ? array_map('strval', FieldDefinition::decodeList($value)) : [$value];
  }

  /**
   * Admin app: created_by / updated_by / deleted_by as {type: "user"|"client", id, name}.
   * Users and clients that were deleted keep their id, with name null.
   *
   * @param list<array> $records
   * @return list<array>
   */
  public function withActors(array $records): array
  {
    $columns = [EntityDefinition::CREATED_BY, EntityDefinition::UPDATED_BY, EntityDefinition::DELETED_BY];
    $ids = ['user' => [], 'client' => [], 'event' => []];
    foreach ($records as $record) {
      foreach ($columns as $column) {
        if (is_string($record[$column] ?? null) && str_contains($record[$column], ':')) {
          [$type, $id] = explode(':', $record[$column], 2);
          $ids[$type][] = $id;
        }
      }
    }
    $names = [
      'user' => $this->users->names(array_values(array_unique($ids['user'] ?? []))),
      'client' => $this->clients->names(array_values(array_unique($ids['client'] ?? []))),
      // Changes made by the steps of an event
      'event' => $this->events->names(array_values(array_unique($ids['event'] ?? []))),
    ];
    return array_map(static function (array $record) use ($columns, $names): array {
      foreach ($columns as $column) {
        if (is_string($record[$column] ?? null) && str_contains($record[$column], ':')) {
          [$type, $id] = explode(':', $record[$column], 2);
          $record[$column] = ['type' => $type, 'id' => $id, 'name' => $names[$type][$id] ?? null];
        }
      }
      return $record;
    }, $records);
  }

  /** ?lang=all: every language of translatable fields */
  public const ALL_LANGUAGES = 'all';

  /**
   * Admin app: the default language in the fields, the other languages in `_i18n`
   * ({"title": {"en": "...", "fr": null}}).
   */
  public function presentForAdmin(EntityDefinition $entity, array $row): array
  {
    $result = $this->present($entity, $row);
    // Raw "user:<id>" / "client:<id>" - withActors() turns them into names
    foreach ([EntityDefinition::CREATED_BY, EntityDefinition::UPDATED_BY, EntityDefinition::DELETED_BY] as $column) {
      if (array_key_exists($column, $row)) {
        $result[$column] = $row[$column];
      }
    }
    if ($entity->drafts) {
      $result[EntityDefinition::DRAFT] = (bool)($row[EntityDefinition::DRAFT] ?? false);
    }
    $translations = [];
    foreach ([] !== $entity->otherLanguages() ? $entity->translatableFields() : [] as $field) {
      if (null !== $this->fieldAccess && !$this->fieldAccess->canRead($field)) {
        continue;
      }
      $translations[$field->name] = (object)$this->translations($entity, $field, $row);
    }
    if ([] !== $translations) {
      $result['_i18n'] = (object)$translations;
    }
    return $result;
  }

  /**
   * @return array<string, mixed> language => value (languages besides the default one)
   */
  private function translations(EntityDefinition $entity, FieldDefinition $field, array $row): array
  {
    $result = [];
    foreach ($entity->otherLanguages() as $language) {
      $result[$language] = self::typed($field, $row[$field->translationColumn($language)] ?? null);
    }
    return $result;
  }

  /**
   * Typed value of a column: a list for repeatable fields (empty list when there is nothing).
   */
  private static function typed(FieldDefinition $field, mixed $stored, int $depth = 0): mixed
  {
    if (FieldType::Group === $field->type) {
      // Blocks of a type the field does not offer (any more) are left out
      $objects = array_filter(GroupValues::objects($field, $stored), static fn(array $object): bool => null !== $field->groupFor($object));
      $objects = array_values(array_map(static fn(array $object): array => self::typedObject($field, $object, $depth), $objects));
      return $field->repeatable ? $objects : ($objects[0] ?? null);
    }
    if (FieldType::Custom === $field->type) {
      return CustomFieldTypes::fromStorage($field, $stored);
    }
    return $field->repeatable
      ? array_map(static fn($item) => ValueConverter::fromStorage($field->type, $item), FieldDefinition::decodeList($stored))
      : ValueConverter::fromStorage($field->type, $stored);
  }

  /**
   * One object of a group: every field of the group, typed (nested groups too).
   */
  private static function typedObject(FieldDefinition $groupField, array $object, int $depth): array
  {
    // Blocks: type and key first
    $result = $groupField->isBlocks() ? self::blockHead($object) : [];
    foreach ($depth <= FieldGroup::MAX_DEPTH ? ($groupField->groupFor($object)?->fields ?? []) : [] as $field) {
      $value = $object[$field->name] ?? null;
      $result[$field->name] = FieldType::Group === $field->type
        ? self::typed($field, $value, $depth + 1)
        : (FieldType::Custom === $field->type ? CustomFieldTypes::fromStorage($field, $value) : ($field->repeatable
          ? array_map(static fn($item) => ValueConverter::fromStorage($field->type, $item), is_array($value) ? array_values($value) : [])
          : ValueConverter::fromStorage($field->type, $value)));
    }
    return $result;
  }

  /**
   * Values of a plugin field: one, or one per language (?lang=all).
   *
   * @return list<mixed>
   */
  private function customByLanguage(EntityDefinition $entity, FieldDefinition $field, mixed $value): array
  {
    return $this->isCustomLanguageMap($entity, $field, $value) ? array_values($value) : [$value];
  }

  private function isCustomLanguageMap(EntityDefinition $entity, FieldDefinition $field, mixed $value): bool
  {
    return $field->translatable && is_array($value) && [] !== $value && !array_is_list($value) && [] === array_diff(array_keys($value), $entity->languages);
  }

  /**
   * Blocks: "_type" and "_key" of an item.
   *
   * @return array{_type: string, _key: string}
   */
  private static function blockHead(array $object): array
  {
    return [FieldDefinition::BLOCK_TYPE => (string)($object[FieldDefinition::BLOCK_TYPE] ?? ''), FieldDefinition::BLOCK_KEY => (string)($object[FieldDefinition::BLOCK_KEY] ?? '')];
  }

  /**
   * Group values with project variables filled in ({{url}} ...), at any depth.
   */
  private function resolveGroup(FieldDefinition $groupField, mixed $value, ?string $language, int $depth = 0): mixed
  {
    if (!is_array($value) || [] === $groupField->groups() || $depth > FieldGroup::MAX_DEPTH) {
      return $value;
    }
    $resolveObject = function (array $object) use ($groupField, $language, $depth): array {
      foreach ($groupField->groupFor($object)?->fields ?? [] as $field) {
        if (!array_key_exists($field->name, $object)) {
          continue;
        }
        $inner = $object[$field->name];
        $object[$field->name] = FieldType::Group === $field->type
          ? $this->resolveGroup($field, $inner, $language, $depth + 1)
          : (is_array($inner) ? array_map(fn($item) => $this->variables->resolve($field, $item, $language), $inner) : $this->variables->resolve($field, $inner, $language));
      }
      return $object;
    };
    return $groupField->repeatable ? array_map($resolveObject, $value) : $resolveObject($value);
  }

  /**
   * Media ids inside group values replaced by the files (one query for all of them).
   *
   * @param array<string, array> $files id => presented file
   */
  private static function groupMedia(FieldDefinition $groupField, mixed $value, array $files, int $depth = 0): mixed
  {
    if (!is_array($value) || [] === $groupField->groups() || $depth > FieldGroup::MAX_DEPTH) {
      return $value;
    }
    $replace = static function (array $object) use ($groupField, $files, $depth): array {
      foreach ($groupField->groupFor($object)?->fields ?? [] as $field) {
        $inner = $object[$field->name] ?? null;
        if (FieldType::Group === $field->type) {
          $object[$field->name] = self::groupMedia($field, $inner, $files, $depth + 1);
        } elseif (FieldType::Custom === $field->type) {
          $object[$field->name] = CustomFieldTypes::present($field, $inner, $files);
        } elseif (FieldType::Media === $field->type) {
          $object[$field->name] = is_array($inner)
            ? array_values(array_filter(array_map(static fn($id) => $files[(string)$id] ?? null, $inner)))
            : (null !== $inner ? ($files[(string)$inner] ?? null) : null);
        }
      }
      return $object;
    };
    return $groupField->repeatable ? array_map($replace, $value) : $replace($value);
  }

  /**
   * Ids in a reference value: one id or a list of them.
   *
   * @return list<string>
   */
  private static function ids(mixed $value): array
  {
    return array_values(array_filter(array_map('strval', is_array($value) ? $value : (null !== $value ? [$value] : []))));
  }

  /**
   * Label of a record (display field, otherwise its id).
   */
  public function label(EntityDefinition $entity, array $row): string
  {
    $field = $entity->displayField();
    $value = null !== $field ? ($row[$field] ?? null) : null;
    return null !== $value && '' !== (string)$value ? (string)$value : (string)$row['id'];
  }

  /**
   * Admin app: adds `_refs` with id and label of every referenced record, so lists can show
   * "Germany" instead of an id.
   *
   * @param list<array> $records presented records
   * @return list<array>
   */
  public function withReferenceLabels(EntityDefinition $entity, array $records): array
  {
    $labels = [];
    foreach ($entity->fields as $field) {
      if (FieldType::Reference !== $field->type || null === $field->referenceEntityId) {
        continue;
      }
      $target = $this->entityRepository->findById($field->referenceEntityId);
      $ids = array_merge(...array_map(static fn(array $r): array => self::ids($r[$field->name] ?? null), $records ?: [[]]));
      if (null === $target || [] === $ids) {
        continue;
      }
      $labels[$field->name] = [];
      foreach ($this->recordRepository->findMany($target, $ids) as $id => $row) {
        $labels[$field->name][$id] = ['id' => $id, 'label' => $this->label($target, $row), 'entity' => $target->slug];
      }
    }

    return array_map(static function (array $record) use ($labels): array {
      $refs = [];
      foreach ($labels as $name => $byId) {
        $value = $record[$name] ?? null;
        $ref = static fn(string $id): array => $byId[$id] ?? ['id' => $id, 'label' => $id, 'entity' => null];
        // Lists of references: a list of labels in the same order
        if (is_array($value)) {
          $refs[$name] = array_map($ref, self::ids($value));
        } elseif (null !== $value) {
          $refs[$name] = $ref((string)$value);
        }
      }
      $record['_refs'] = (object)$refs;
      return $record;
    }, $records);
  }

  /**
   * Content API: replaces the id in the given reference fields by the referenced record.
   * $allowed decides per target entity whether the reader may see it (otherwise the id stays).
   *
   * @param list<array> $records presented records
   * @param list<string> $include reference field names
   * @param callable(EntityDefinition): bool $allowed
   * @return list<array>
   */
  public function expand(EntityDefinition $entity, array $records, array $include, callable $allowed, ?string $language = null): array
  {
    foreach ($include as $name) {
      $field = $entity->field($name);
      if (null === $field || FieldType::Reference !== $field->type || null === $field->referenceEntityId) {
        continue;
      }
      $target = $this->entityRepository->findById($field->referenceEntityId);
      if (null === $target || !$allowed($target)) {
        continue;
      }
      $ids = array_merge(...array_map(static fn(array $r): array => self::ids($r[$name] ?? null), $records ?: [[]]));
      // Drafts are not delivered - the id stays
      $rows = array_filter([] !== $ids ? $this->recordRepository->findMany($target, $ids) : [], static fn(array $row): bool => !RecordRepository::isDraft($target, $row));
      $embedded = array_combine(array_keys($rows), $this->withMedia($target, array_values(array_map(fn(array $row): array => $this->present($target, $row, null, $language, true), $rows))));
      foreach ($records as &$record) {
        $value = $record[$name] ?? null;
        if (is_array($value)) {
          $record[$name] = array_map(static fn(string $id) => $embedded[$id] ?? $id, self::ids($value));
        } elseif (null !== $value && isset($embedded[$value])) {
          $record[$name] = $embedded[$value];
        }
      }
      unset($record);
    }
    return $records;
  }
}
