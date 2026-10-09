<?php

declare(strict_types=1);

namespace App\Domain\Schema;

/**
 * Walks through values of group fields: an object with the group's fields, or a list of objects
 * (repeatable, blocks - each object of its own group), with nested groups inside.
 */
final class GroupValues
{
  /**
   * The objects of a group field's value (one, or the items of a repeatable field).
   *
   * @return list<array<string, mixed>>
   */
  public static function objects(FieldDefinition $field, mixed $value): array
  {
    if (is_string($value)) {
      $value = '' !== $value ? json_decode($value, true) : null;
    }
    if (!is_array($value)) {
      return [];
    }
    $list = $field->repeatable || array_is_list($value) ? $value : [$value];
    return array_values(array_filter($list, 'is_array'));
  }

  /**
   * Calls $visit for every field with a value inside groups and blocks (nested ones too), with
   * the field and its value - not for group fields themselves.
   *
   * @param callable(FieldDefinition, mixed): void $visit
   */
  public static function each(FieldDefinition $groupField, mixed $value, callable $visit, int $depth = 0): void
  {
    if ([] === $groupField->groups() || $depth > FieldGroup::MAX_DEPTH) {
      return;
    }
    foreach (self::objects($groupField, $value) as $object) {
      foreach ($groupField->groupFor($object)?->fields ?? [] as $field) {
        $inner = $object[$field->name] ?? null;
        if (FieldType::Group === $field->type) {
          self::each($field, $inner, $visit, $depth + 1);
        } elseif (null !== $inner) {
          $visit($field, $inner);
        }
      }
    }
  }

  /**
   * Values of the fields that match (e.g. all media ids), at any depth.
   *
   * @param callable(FieldDefinition): bool $matches
   * @return list<string>
   */
  public static function collect(FieldDefinition $groupField, mixed $value, callable $matches, int $depth = 0): array
  {
    if ([] === $groupField->groups() || $depth > FieldGroup::MAX_DEPTH) {
      return [];
    }
    $result = [];
    foreach (self::objects($groupField, $value) as $object) {
      foreach ($groupField->groupFor($object)?->fields ?? [] as $field) {
        $inner = $object[$field->name] ?? null;
        if (FieldType::Group === $field->type) {
          array_push($result, ...self::collect($field, $inner, $matches, $depth + 1));
        } elseif ($matches($field) && null !== $inner) {
          foreach (is_array($inner) ? $inner : [$inner] as $item) {
            if (is_scalar($item) && '' !== (string)$item) {
              $result[] = (string)$item;
            }
          }
        }
      }
    }
    return $result;
  }

  /**
   * The value with the matching fields changed by $map (e.g. other ids), at any depth. Lists of
   * values (repeatable fields) are mapped item by item; null from $map removes an item.
   *
   * @param callable(FieldDefinition, mixed): mixed $map
   */
  public static function map(FieldDefinition $groupField, mixed $value, callable $matches, callable $map, int $depth = 0): mixed
  {
    if (is_string($value)) {
      $value = '' !== $value ? json_decode($value, true) : null;
    }
    if ([] === $groupField->groups() || !is_array($value) || $depth > FieldGroup::MAX_DEPTH) {
      return $value;
    }
    $mapObject = static function (array $object) use ($groupField, $matches, $map, $depth): array {
      foreach ($groupField->groupFor($object)?->fields ?? [] as $field) {
        if (!array_key_exists($field->name, $object) || null === $object[$field->name]) {
          continue;
        }
        if (FieldType::Group === $field->type) {
          $object[$field->name] = self::map($field, $object[$field->name], $matches, $map, $depth + 1);
        } elseif ($matches($field)) {
          $inner = $object[$field->name];
          $object[$field->name] = is_array($inner)
            ? array_values(array_filter(array_map(static fn($item) => $map($field, $item), $inner), static fn($item): bool => null !== $item))
            : $map($field, $inner);
        }
      }
      return $object;
    };
    if ($groupField->repeatable || array_is_list($value)) {
      return array_values(array_map(static fn($item) => is_array($item) ? $mapObject($item) : $item, $value));
    }
    return $mapObject($value);
  }
}
