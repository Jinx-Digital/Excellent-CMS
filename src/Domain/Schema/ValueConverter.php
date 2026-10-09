<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Shared\I18n;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\Uid\Uuid;

/**
 * Converts raw values (CSV/Excel cells, JSON input) into the value stored in the column, and back
 * into the typed value of the API. One place for all formats, so import, admin app and API accept
 * exactly the same input:
 *
 *   Ganzzahl     "1234", "1.234", "-5", 12.0
 *   Dezimalzahl  "12,50", "1.234,56", "1,234.56", "12.5"
 *   Ja/Nein      1/0, true/false, ja/nein, yes/no, x, wahr/falsch, on/off
 *   Datum        2025-10-29, 29.10.2025, 29.10.25, 29/10/2025, 2025/10/29
 *   Datum+Zeit   2025-10-29 18:00(:00), 2025-10-29T18:00:00(+02:00), 29.10.2025 18:00(:00)
 *   Uhrzeit      18:00, 18:00:00, 8:05
 *   UUID         550e8400-e29b-41d4-a716-446655440000, auch in Großbuchstaben, mit {…}, ohne Bindestriche
 *   Lfd. Nummer  ganze Zahl ab 1
 *   Slug         "Über uns!" -> "ueber-uns"
 */
final class ValueConverter
{
  private const TRUE_WORDS = ['1', 'true', 'ja', 'yes', 'y', 'j', 'x', 'wahr', 'on', 'oui', 'si', 'tak'];
  private const FALSE_WORDS = ['0', 'false', 'nein', 'no', 'n', 'falsch', 'off', 'non', 'nie'];

  private const DATE_FORMATS = ['Y-m-d', 'd.m.Y', 'd.m.y', 'd/m/Y', 'Y/m/d', 'j.n.Y', 'j.n.y'];
  private const DATETIME_FORMATS = [
    'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.u\Z',
    'd.m.Y H:i:s', 'd.m.Y H:i', 'j.n.Y H:i', 'j.n.Y G:i', 'd/m/Y H:i:s', 'd/m/Y H:i',
  ];
  private const TIME_FORMATS = ['H:i:s', 'H:i', 'G:i', 'G:i:s'];

  /**
   * @throws InvalidValueException
   * @return string|int|bool|null Value for the database column (null = empty)
   */
  public static function toStorage(FieldDefinition $field, mixed $value): string|int|bool|null
  {
    // Field types of plugins check and convert their values themselves
    if (FieldType::Custom === $field->type) {
      return CustomFieldTypes::toStorage($field, $value);
    }
    $converted = self::convert($field->type, $value, $field->length, $field->scale);
    // Type "regex": the value must match the field's pattern
    if (FieldType::Regex === $field->type && null !== $converted && null !== $field->pattern && 1 !== @preg_match(FieldDefinition::regex($field->pattern), (string)$converted)) {
      throw new InvalidValueException($field->patternMessage ?? I18n::t('The value does not match the pattern {pattern}.', ['pattern' => $field->pattern]));
    }
    // Numbers: within the field's range
    if (null !== $converted && $field->isNumber() && is_numeric($converted)) {
      $number = 0 + $converted;
      if ((null !== $field->minValue && $number < $field->minValue) || (null !== $field->maxValue && $number > $field->maxValue)) {
        throw new InvalidValueException(match (true) {
          null === $field->maxValue => I18n::t('At least {min}.', ['min' => $field->minValue]),
          null === $field->minValue => I18n::t('At most {max}.', ['max' => $field->maxValue]),
          default => I18n::t('Between {min} and {max}.', ['min' => $field->minValue, 'max' => $field->maxValue]),
        });
      }
    }
    // Type "enum": one of the values the admin set
    if (FieldType::Enum === $field->type && null !== $converted && !in_array((string)$converted, $field->optionValues(), true)) {
      throw new InvalidValueException(I18n::t('"{value}" is not allowed. Allowed: {values}.', ['value' => (string)$converted, 'values' => implode(', ', $field->optionValues())]));
    }
    return $converted;
  }

  /**
   * @throws InvalidValueException
   */
  public static function convert(FieldType $type, mixed $value, ?int $length = null, ?int $scale = null): string|int|bool|null
  {
    if (null === $value || (is_string($value) && '' === trim($value))) {
      return null;
    }
    // Types whose values may be objects or lists
    if (FieldType::Json === $type) {
      return self::json($value);
    }
    if (FieldType::DateRange === $type) {
      return self::dateRange($value);
    }
    if (FieldType::Code === $type) {
      return CodeValue::toStorage($value);
    }
    if (is_array($value) || (is_object($value) && !$value instanceof DateTimeInterface)) {
      throw new InvalidValueException(I18n::t('Invalid value.'));
    }

    return match ($type) {
      FieldType::String => self::text($value, $length ?? FieldType::DEFAULT_LENGTH),
      FieldType::Text => self::text($value, null),
      FieldType::Integer, FieldType::Order => self::integer($value),
      FieldType::Decimal => self::decimal($value, $scale ?? FieldType::DEFAULT_SCALE),
      FieldType::Boolean => self::boolean($value),
      FieldType::Date => self::date($value),
      FieldType::DateTime => self::dateTime($value),
      FieldType::Time => self::time($value),
      FieldType::Email => self::email($value),
      FieldType::Url => self::url($value),
      FieldType::Reference => self::text($value, 22),
      FieldType::Uuid => self::uuid($value),
      // Id of an uploaded file; whether it exists is checked by RecordService
      FieldType::Media => self::text($value, 22),
      FieldType::Slug => self::slug($value, $length ?? FieldType::DEFAULT_LENGTH),
      FieldType::Markdown => self::text($value, null),
      FieldType::Regex => self::text($value, $length ?? FieldType::DEFAULT_LENGTH),
      FieldType::AutoIncrement => self::positiveInteger($value),
      FieldType::Enum => self::text($value, FieldType::ENUM_LENGTH),
      FieldType::Color => self::color($value),
      FieldType::Phone => self::phone($value),
      // Custom: converted by its plugin (CustomFieldTypes::toStorage) - with the field
      FieldType::DateRange, FieldType::Json, FieldType::Code, FieldType::Group, FieldType::Custom => throw new InvalidValueException(I18n::t('Invalid value.')),
    };
  }

  /**
   * Typed value for the API: database drivers return most columns as strings.
   */
  public static function fromStorage(FieldType $type, mixed $value): mixed
  {
    if (null === $value) {
      return null;
    }
    return match ($type) {
      FieldType::Integer, FieldType::AutoIncrement, FieldType::Order => (int)$value,
      FieldType::Decimal => (float)$value,
      FieldType::Boolean => (bool)$value,
      FieldType::DateRange => self::splitRange((string)$value),
      // Objects stay objects ({} is not [])
      FieldType::Json => json_decode((string)$value),
      FieldType::Code => CodeValue::fromStorage((string)$value),
      default => (string)$value,
    };
  }

  /**
   * Does the value fit the type? Used by the type detection of the import.
   */
  public static function matches(FieldType $type, string $value): bool
  {
    try {
      self::convert($type, $value, FieldType::MAX_LENGTH, FieldType::MAX_SCALE);
      return true;
    } catch (InvalidValueException) {
      return false;
    }
  }

  public static function isBooleanWord(string $value): bool
  {
    $value = mb_strtolower(trim($value));
    return in_array($value, self::TRUE_WORDS, true) || in_array($value, self::FALSE_WORDS, true);
  }

  /**
   * Digits after the decimal separator ("12,505" -> 3), for the scale of detected decimal fields.
   */
  public static function decimalPlaces(string $value): int
  {
    $normalized = self::normalizeNumber($value);
    if (null === $normalized || !str_contains($normalized, '.')) {
      return 0;
    }
    return strlen(rtrim(substr($normalized, strpos($normalized, '.') + 1), '0'));
  }

  private static function text(mixed $value, ?int $maxLength): string
  {
    if ($value instanceof DateTimeInterface) {
      $value = $value->format('Y-m-d H:i:s');
    } elseif (is_bool($value)) {
      $value = $value ? '1' : '0';
    }
    $text = trim((string)$value);
    if (null !== $maxLength && mb_strlen($text) > $maxLength) {
      throw new InvalidValueException(I18n::t('At most {max} characters allowed (has {length}).', ['max' => $maxLength, 'length' => mb_strlen($text)]));
    }
    return $text;
  }

  private static function integer(mixed $value): string
  {
    if (is_int($value)) {
      return (string)$value;
    }
    if (is_float($value)) {
      if (floor($value) !== $value || abs($value) > 9.0e18) {
        throw new InvalidValueException(I18n::t('Please enter a whole number.'));
      }
      return (string)(int)$value;
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    $text = str_replace([' ', "\u{00A0}", "'"], '', trim((string)$value));
    // Thousands separators: 1.234.567 or 1,234,567
    if (preg_match('/^[+-]?[1-9]\d{0,2}([.,])\d{3}(\1\d{3})*$/', $text)) {
      $text = str_replace(['.', ','], '', $text);
    }
    // "12.0" / "12,00" from spreadsheets
    $text = (string)preg_replace('/^([+-]?\d+)[.,]0+$/', '$1', $text);
    if (!preg_match('/^[+-]?\d{1,18}$/', $text)) {
      throw new InvalidValueException(I18n::t('Please enter a whole number.'));
    }
    return (string)(int)$text;
  }

  private static function slug(mixed $value, int $maxLength): string
  {
    $slug = Slug::make(self::text($value, null), $maxLength);
    if ('' === $slug) {
      throw new InvalidValueException(I18n::t('Please use letters or digits.'));
    }
    return $slug;
  }

  private static function positiveInteger(mixed $value): string
  {
    $integer = self::integer($value);
    if ((int)$integer < 1) {
      throw new InvalidValueException(I18n::t('Please enter a whole number from 1.'));
    }
    return $integer;
  }

  /**
   * Stored in the canonical lowercase form, so equal UUIDs are found however they were written.
   */
  private static function uuid(mixed $value): string
  {
    $text = trim((string)$value, " \t\n\r\0\x0B{}");
    if (1 === preg_match('/^[0-9a-f]{32}$/i', $text)) {
      $text = substr($text, 0, 8).'-'.substr($text, 8, 4).'-'.substr($text, 12, 4).'-'.substr($text, 16, 4).'-'.substr($text, 20);
    }
    if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $text)) {
      throw new InvalidValueException(I18n::t('Please enter a valid UUID (e.g. 0192f1a4-7b3c-7d2e-9f10-123456789abc).'));
    }
    return Uuid::fromString(strtolower($text))->toRfc4122();
  }

  private static function decimal(mixed $value, int $scale): string
  {
    if (is_bool($value)) {
      throw new InvalidValueException(I18n::t('Please enter a number.'));
    }
    $normalized = is_int($value) || is_float($value) ? (string)$value : self::normalizeNumber((string)$value);
    if (null === $normalized || !is_numeric($normalized)) {
      throw new InvalidValueException(I18n::t('Please enter a number.'));
    }
    if (abs((float)$normalized) >= 1e18) {
      throw new InvalidValueException(I18n::t('The number is too large.'));
    }
    return number_format((float)$normalized, $scale, '.', '');
  }

  /**
   * "1.234,56" / "1,234.56" / "12,5" / "12.5" / "1 234,5" -> "1234.56" ...; null if it is no number.
   */
  private static function normalizeNumber(string $value): ?string
  {
    $text = str_replace([' ', "\u{00A0}", "'", '€', '$', '%'], '', trim($value));
    if (!preg_match('/^[+-]?[\d.,]*\d[\d.,]*$/', $text) && !preg_match('/^[+-]?\d+(\.\d+)?[eE][+-]?\d+$/', $text)) {
      return null;
    }
    $lastDot = strrpos($text, '.');
    $lastComma = strrpos($text, ',');
    if (false !== $lastDot && false !== $lastComma) {
      // Both: the last one is the decimal separator
      $decimal = $lastDot > $lastComma ? '.' : ',';
      $thousands = '.' === $decimal ? ',' : '.';
      $text = str_replace([$thousands, $decimal], ['', '.'], $text);
    } elseif (false !== $lastComma) {
      // Only commas: several = thousands (1,234,567), one = decimal comma (12,5)
      $text = substr_count($text, ',') > 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
    } elseif (substr_count($text, '.') > 1) {
      $text = str_replace('.', '', $text);
    }
    return $text;
  }

  private static function boolean(mixed $value): bool
  {
    if (is_bool($value)) {
      return $value;
    }
    if (is_int($value) || is_float($value)) {
      if (0.0 === (float)$value || 1.0 === (float)$value) {
        return 1.0 === (float)$value;
      }
      throw new InvalidValueException(I18n::t('Please enter yes or no (1/0).'));
    }
    $text = mb_strtolower(trim((string)$value));
    if (in_array($text, self::TRUE_WORDS, true)) {
      return true;
    }
    if (in_array($text, self::FALSE_WORDS, true)) {
      return false;
    }
    throw new InvalidValueException(I18n::t('Please enter yes or no (1/0).'));
  }

  private static function date(mixed $value): string
  {
    if ($value instanceof DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    $text = trim((string)$value);
    $date = self::parse($text, self::DATE_FORMATS);
    if (null === $date) {
      // A date with time 00:00 (Excel, exports) is still a date
      $dateTime = self::parse($text, self::DATETIME_FORMATS);
      if (null !== $dateTime && '00:00:00' === $dateTime->format('H:i:s')) {
        $date = $dateTime;
      }
    }
    if (null === $date) {
      throw new InvalidValueException(I18n::t('Please enter a date (e.g. 2025-10-29 or 29.10.2025).'));
    }
    return $date->format('Y-m-d');
  }

  /**
   * "#1E40AF", "1e40af", "#14f" -> "#1e40af"; with transparency "#1e40af80".
   */
  private static function color(mixed $value): string
  {
    $hex = strtolower(ltrim(trim((string)$value), '#'));
    if (1 === preg_match('/^[0-9a-f]{3,4}$/', $hex)) {
      $hex = implode('', array_map(static fn(string $c): string => $c.$c, str_split($hex)));
    }
    if (1 !== preg_match('/^([0-9a-f]{6}|[0-9a-f]{8})$/', $hex)) {
      throw new InvalidValueException(I18n::t('Please enter a color as hex code (e.g. #1e40af).'));
    }
    return '#'.$hex;
  }

  /**
   * "+49 (30) 123 456-7", "0049 30 1234567" -> "+493012345678". Without country code it cannot be
   * stored in one form, so it is refused.
   */
  private static function phone(mixed $value): string
  {
    $number = (string)preg_replace('/[\s\-\/.()]/', '', trim((string)$value));
    if (str_starts_with($number, '00')) {
      $number = '+'.substr($number, 2);
    }
    if (1 !== preg_match('/^\+[1-9]\d{5,14}$/', $number)) {
      throw new InvalidValueException(I18n::t('Please enter a phone number with country code (e.g. +49 30 123456).'));
    }
    return $number;
  }

  /**
   * {from, to}, "2026-10-01/2026-10-05" or "01.10.2026 - 05.10.2026" -> "2026-10-01/2026-10-05".
   * The end may be empty (open end), but not before the start.
   */
  private static function dateRange(mixed $value): string
  {
    if (is_array($value) || is_object($value)) {
      $value = (array)$value;
      [$from, $to] = [$value['from'] ?? null, $value['to'] ?? null];
    } else {
      $parts = preg_split('#\s*(?:/|\s[-–—]\s|\sbis\s)\s*#u', trim((string)$value), 2) ?: [];
      [$from, $to] = [$parts[0] ?? null, $parts[1] ?? null];
    }
    if (null === $from || '' === trim((string)$from)) {
      throw new InvalidValueException(I18n::t('Please enter at least the start of the date range.'));
    }
    $start = self::date($from);
    $end = null !== $to && '' !== trim((string)$to) ? self::date($to) : '';
    if ('' !== $end && $end < $start) {
      throw new InvalidValueException(I18n::t('The end of the date range is before its start.'));
    }
    return $start.'/'.$end;
  }

  /**
   * @return array{from: string, to: ?string}
   */
  private static function splitRange(string $value): array
  {
    [$from, $to] = array_pad(explode('/', $value, 2), 2, '');
    return ['from' => $from, 'to' => '' !== $to ? $to : null];
  }

  /**
   * JSON text (from a form or a file) or a value (from the API) -> JSON text.
   */
  private static function json(mixed $value): string
  {
    if (is_string($value)) {
      try {
        $value = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
      } catch (\JsonException) {
        throw new InvalidValueException(I18n::t('This is no valid JSON.'));
      }
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
  }

  private static function dateTime(mixed $value): string
  {
    if ($value instanceof DateTimeInterface) {
      return $value->format('Y-m-d H:i:s');
    }
    $text = trim((string)$value);
    $dateTime = self::parse($text, self::DATETIME_FORMATS) ?? self::parse($text, self::DATE_FORMATS);
    if (null === $dateTime) {
      throw new InvalidValueException(I18n::t('Please enter a date and time (e.g. 2025-10-29 18:00:00).'));
    }
    // Values with a time zone are stored in UTC, like everything else
    return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  }

  private static function time(mixed $value): string
  {
    if ($value instanceof DateTimeInterface) {
      return $value->format('H:i:s');
    }
    $time = self::parse(trim((string)$value), self::TIME_FORMATS);
    if (null === $time) {
      throw new InvalidValueException(I18n::t('Please enter a time (e.g. 18:00).'));
    }
    return $time->format('H:i:s');
  }

  private static function email(mixed $value): string
  {
    $text = self::text($value, 255);
    if (false === filter_var($text, FILTER_VALIDATE_EMAIL)) {
      throw new InvalidValueException(I18n::t('Please enter a valid e-mail address.'));
    }
    return $text;
  }

  private static function url(mixed $value): string
  {
    $text = self::text($value, FieldType::URL_LENGTH);
    $scheme = strtolower((string)parse_url($text, PHP_URL_SCHEME));
    if (false === filter_var($text, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
      throw new InvalidValueException(I18n::t('Please enter a valid link (https://…).'));
    }
    return $text;
  }

  /**
   * Strict parsing: the value must match the format completely and be a real date (no 31.02.).
   *
   * @param string[] $formats
   */
  private static function parse(string $text, array $formats): ?DateTimeImmutable
  {
    foreach ($formats as $format) {
      $result = DateTimeImmutable::createFromFormat('!'.$format, $text, new DateTimeZone('UTC'));
      if (false === $result) {
        continue;
      }
      $errors = DateTimeImmutable::getLastErrors();
      if (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
        continue;
      }
      $year = (int)$result->format('Y');
      if ($year < 1000 || $year > 9999) {
        continue;
      }
      return $result;
    }
    return null;
  }
}
