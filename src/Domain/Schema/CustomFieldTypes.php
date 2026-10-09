<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Shared\I18n;

/**
 * The field types of the active plugins (filled by PluginManager) - and what fields of type
 * "custom" do with their values. A field whose plugin is not active keeps its values: they are
 * stored and delivered as they are (JSON or text).
 */
final class CustomFieldTypes
{
  /** @var array<string, CustomFieldType> */
  private static array $types = [];

  public static function add(CustomFieldType $type): void
  {
    self::$types[$type->type] = $type;
  }

  public static function reset(): void
  {
    self::$types = [];
  }

  public static function get(?string $type): ?CustomFieldType
  {
    return null !== $type ? self::$types[$type] ?? null : null;
  }

  /**
   * @return array<string, CustomFieldType>
   */
  public static function all(): array
  {
    return self::$types;
  }

  /**
   * A value as it is stored: checked by the plugin - without it, JSON or text.
   *
   * @throws InvalidValueException
   */
  public static function toStorage(FieldDefinition $field, mixed $value): ?string
  {
    if (null === $value || '' === $value || [] === $value) {
      return null;
    }
    $type = self::get($field->customType);
    if (null !== $type?->toStorage) {
      try {
        $stored = ($type->toStorage)($value, $field);
      } catch (\InvalidArgumentException $e) {
        throw new InvalidValueException($e->getMessage());
      }
      return null === $stored || '' === $stored ? null : (string)$stored;
    }
    return is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  public static function fromStorage(FieldDefinition $field, mixed $stored): mixed
  {
    if (null === $stored || '' === $stored) {
      return null;
    }
    if (!is_string($stored)) {
      // Inside groups the value may be decoded already
      return $stored;
    }
    $type = self::get($field->customType);
    if (null !== $type?->fromStorage) {
      return ($type->fromStorage)($stored);
    }
    $decoded = json_decode($stored, true);
    return JSON_ERROR_NONE === json_last_error() && (is_array($decoded)) ? $decoded : $stored;
  }

  /**
   * @param array<string, array> $files presented files by id (see mediaIds)
   */
  public static function present(FieldDefinition $field, mixed $value, array $files): mixed
  {
    $type = self::get($field->customType);
    return null !== $value && null !== $type?->present ? ($type->present)($value, $files) : $value;
  }

  public static function text(FieldDefinition $field, mixed $value): string
  {
    $type = self::get($field->customType);
    if (null === $value || null === $type?->text) {
      return '';
    }
    return (string)($type->text)($value);
  }

  /**
   * @return list<string>
   */
  public static function mediaIds(FieldDefinition $field, mixed $value): array
  {
    $type = self::get($field->customType);
    if (null === $value || null === $type?->mediaIds) {
      return [];
    }
    return array_values(array_map('strval', (array)($type->mediaIds)($value)));
  }

  public static function usesMedia(FieldDefinition $field): bool
  {
    return null !== self::get($field->customType)?->mediaIds;
  }

  /**
   * For the admin app: label, component and the public config of the plugin.
   */
  public static function describe(?string $type): ?array
  {
    $custom = self::get($type);
    if (null === $custom) {
      return null;
    }
    return [
      'type' => $custom->type,
      'plugin' => $custom->plugin,
      'label' => $custom->label,
      'description' => $custom->description,
      'icon' => $custom->icon,
      'component' => $custom->component,
      'config' => null !== $custom->config ? (object)($custom->config)() : (object)[],
    ];
  }

  public static function unknown(string $type): string
  {
    return I18n::t('There is no field type "{type}" - is its plugin active?', ['type' => $type]);
  }
}
