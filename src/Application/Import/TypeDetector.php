<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\Schema\FieldType;
use App\Domain\Schema\Slug;
use App\Domain\Schema\ValueConverter;

/**
 * Suggests the field type of a column from all its values. Each type is a candidate until a value
 * does not fit; the most specific remaining one wins (Ja/Nein before Ganzzahl before Dezimalzahl,
 * Datum before Datum+Zeit). Columns with mixed content become text.
 *
 * Fixes of the old detection: decimals ("12,50") are no integers, d.m.Y dates and 0/1 columns are
 * recognized, the undefined DATE/TIME constants are gone, and the separator of the file is used.
 */
final class TypeDetector
{
  /** Detection order = priority */
  private const CANDIDATES = [
    FieldType::Boolean, FieldType::Integer, FieldType::Decimal, FieldType::Date,
    FieldType::DateTime, FieldType::Time, FieldType::Uuid, FieldType::Email, FieldType::Url,
  ];

  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-([0-9a-f])[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

  /** Column names that suggest a unique key */
  private const KEY_NAMES = '/((^|_)(id|uuid|guid|key|code|slug|sku|ean|gtin|isbn|iso_?\d?|nr)|nummer|number|alpha\d?code)$/';

  /**
   * @param list<string|null> $values
   * @return array{type: string, length: ?int, scale: ?int, uuid_version: ?int, required: bool, unique: bool, empty: int, distinct: int}
   */
  public static function detect(array $values, string $name = ''): array
  {
    $candidates = self::CANDIDATES;
    $maxLength = 0;
    $scale = 0;
    $uuidVersions = [];
    $empty = 0;
    $seen = [];

    foreach ($values as $value) {
      if (null === $value || '' === trim($value)) {
        $empty++;
        continue;
      }
      $value = trim($value);
      $seen[$value] = true;
      $maxLength = max($maxLength, mb_strlen($value));

      foreach ($candidates as $index => $type) {
        if (!self::fits($type, $value)) {
          unset($candidates[$index]);
        }
      }
      if (in_array(FieldType::Decimal, $candidates, true)) {
        $scale = max($scale, ValueConverter::decimalPlaces($value));
      }
      if (in_array(FieldType::Uuid, $candidates, true) && preg_match(self::UUID_PATTERN, $value, $match)) {
        $uuidVersions[(int)hexdec($match[1])] = true;
      }
    }

    $filled = count($values) - $empty;
    $distinct = count($seen);
    $type = 0 === $filled ? null : (reset($candidates) ?: null);
    if (null === $type) {
      $type = $maxLength > FieldType::DEFAULT_LENGTH ? FieldType::Text : FieldType::String;
    }
    // "slug" columns with URL names become slug fields
    if (FieldType::String === $type && 1 === preg_match('/(^|_)slug$/', $name) && [] === array_filter(array_keys($seen), static fn($v): bool => !Slug::isValid((string)$v))) {
      $type = FieldType::Slug;
    }

    return [
      'type' => $type->value,
      'length' => $type->hasLength() ? FieldType::DEFAULT_LENGTH : null,
      'scale' => FieldType::Decimal === $type ? min(FieldType::MAX_SCALE, max(2, $scale)) : null,
      // New values get the version the file already uses
      'uuid_version' => FieldType::Uuid === $type
        ? (1 === count($uuidVersions) && isset(FieldType::UUID_VERSIONS[array_key_first($uuidVersions)]) ? array_key_first($uuidVersions) : FieldType::DEFAULT_UUID_VERSION)
        : null,
      'required' => $filled > 0 && 0 === $empty,
      'unique' => FieldType::Slug === $type || $filled > 1 && $distinct === $filled && 0 === $empty && $type->canBeUnique()
        && (1 === preg_match(self::KEY_NAMES, $name) || FieldType::Email === $type),
      'empty' => $empty,
      'distinct' => $distinct,
    ];
  }

  private static function fits(FieldType $type, string $value): bool
  {
    return match ($type) {
      FieldType::Boolean => ValueConverter::isBooleanWord($value),
      // Strict: "1.234" could be a thousand or a decimal - only plain digits are integers
      FieldType::Integer => 1 === preg_match('/^[+-]?\d{1,18}$/', $value) && !(strlen(ltrim($value, '+-')) > 1 && str_starts_with(ltrim($value, '+-'), '0')),
      // Leading zeros ("01234") are codes, not numbers
      // Only the usual notation - 32 hex digits are more likely a hash
      FieldType::Uuid => 1 === preg_match(self::UUID_PATTERN, $value),
      FieldType::Decimal => 1 === preg_match('/^[+-]?(\d{1,3}([.,\s]\d{3})*|\d+)([.,]\d+)?$/', $value) && 0 === preg_match('/^[+-]?0\d/', $value) && ValueConverter::matches(FieldType::Decimal, $value),
      default => ValueConverter::matches($type, $value),
    };
  }
}
