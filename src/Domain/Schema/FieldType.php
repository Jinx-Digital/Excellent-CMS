<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Shared\I18n;
use Symfony\Component\Uid\Uuid;
use Yiisoft\Db\Schema\Column\ColumnBuilder as C;
use Yiisoft\Db\Schema\Column\ColumnInterface;

/**
 * Data types a field can have. Every type maps to a real column type, so the content tables can
 * be filtered, sorted and indexed like hand-written ones.
 */
enum FieldType: string
{
  case String = 'string';
  case Text = 'text';
  case Integer = 'integer';
  case Decimal = 'decimal';
  case Boolean = 'boolean';
  case Date = 'date';
  case DateTime = 'datetime';
  case Time = 'time';
  case Email = 'email';
  case Url = 'url';
  /** Points to a record of another (or the same) entity */
  case Reference = 'reference';
  /** RFC 4122 UUID, generated for new records when empty */
  case Uuid = 'uuid';
  /** Counter of the database (AUTO_INCREMENT): 1, 2, 3 ... - always unique, at most one per entity */
  case AutoIncrement = 'autoincrement';
  /** Uploaded file (row of the table `media`), stored in the configured media storage */
  case Media = 'media';
  /** URL name ("ueber-uns"): always unique, taken values get "-2" ..., can be made from another text field */
  case Slug = 'slug';
  /** Formatted text, stored as Markdown */
  case Markdown = 'markdown';
  /** The fields of a field group as one object (JSON) - repeatable: a list of objects */
  case Group = 'group';
  /** Short text that has to match a regular expression set by the admin */
  case Regex = 'regex';
  /** Position of the record: lists are sorted by it (drag & drop), new records go to the end - at most one per entity */
  case Order = 'order';
  /** One of the values the admin set (options: value + label) - the value is stored */
  case Enum = 'enum';
  /** Color as #rrggbb (or #rrggbbaa with transparency) */
  case Color = 'color';
  /** Phone number in international form: +4930123456 */
  case Phone = 'phone';
  /** Two dates, stored as "2026-10-01/2026-10-05" (sortable by the start), delivered as {from, to} */
  case DateRange = 'daterange';
  /** Any JSON value (object, list, number ...) - delivered as it is */
  case Json = 'json';
  /** Code as written, in a code editor: {language, file, code} (see CodeValue) */
  case Code = 'code';
  /** A field type of a plugin (custom_type "<plugin>.<key>", see CustomFieldTypes) - stored as text */
  case Custom = 'custom';

  /** Longest value of an enum option */
  public const ENUM_LENGTH = 100;

  public const DEFAULT_LENGTH = 255;
  public const MAX_LENGTH = 1000;
  public const DEFAULT_SCALE = 2;
  public const MAX_SCALE = 8;
  public const URL_LENGTH = 500;
  public const DEFAULT_UUID_VERSION = 7;
  /** Version => label (English, translated by uuidVersionLabel()) */
  public const UUID_VERSIONS = [
    7 => 'Version 7 - time-based, sortable (recommended)',
    4 => 'Version 4 - random',
    6 => 'Version 6 - time-based, sortable (v1 compatible)',
    1 => 'Version 1 - time-based (classic)',
  ];

  public static function uuidVersionLabel(int $version): string
  {
    return match ($version) {
      7 => I18n::t('Version 7 - time-based, sortable (recommended)'),
      4 => I18n::t('Version 4 - random'),
      6 => I18n::t('Version 6 - time-based, sortable (v1 compatible)'),
      1 => I18n::t('Version 1 - time-based (classic)'),
      default => 'Version '.$version,
    };
  }

  public function label(): string
  {
    return match ($this) {
      self::String => I18n::t('Short text'),
      self::Text => I18n::t('Long text'),
      self::Integer => I18n::t('Whole number'),
      self::Decimal => I18n::t('Decimal number'),
      self::Boolean => I18n::t('Yes/No'),
      self::Date => I18n::t('Date'),
      self::DateTime => I18n::t('Date and time'),
      self::Time => I18n::t('Time'),
      self::Email => I18n::t('E-mail address'),
      self::Url => I18n::t('Link (URL)'),
      self::Reference => I18n::t('Reference to an entity'),
      self::Uuid => I18n::t('UUID'),
      self::AutoIncrement => I18n::t('Counter (auto increment)'),
      self::Media => I18n::t('Media (upload)'),
      self::Slug => I18n::t('Slug (URL name)'),
      self::Markdown => I18n::t('Markdown (formatted text)'),
      self::Custom => I18n::t('Field type of a plugin'),
      self::Group => I18n::t('Field group'),
      self::Regex => I18n::t('Text with a pattern (RegEx)'),
      self::Order => I18n::t('Order (drag & drop)'),
      self::Enum => I18n::t('Selection (enum)'),
      self::Color => I18n::t('Color'),
      self::Phone => I18n::t('Phone number'),
      self::DateRange => I18n::t('Date range'),
      self::Json => I18n::t('JSON'),
      self::Code => I18n::t('Code'),
    };
  }

  /**
   * Values are always nullable in the database: "required" is checked by the application, so a
   * required field can be added to a table that already has records.
   */
  public function column(?int $length = null, ?int $scale = null): ColumnInterface
  {
    $column = match ($this) {
      self::String => C::string($length ?? self::DEFAULT_LENGTH),
      self::Text => C::text(),
      self::Integer => C::bigint(),
      // 18 digits before the separator are enough for prices, amounts and measurements
      self::Decimal => C::decimal(18 + ($scale ?? self::DEFAULT_SCALE), $scale ?? self::DEFAULT_SCALE),
      self::Boolean => C::boolean(),
      self::Date => C::date(),
      self::DateTime => C::datetime(),
      self::Time => C::time(),
      self::Email => C::string(255),
      self::Url => C::string(self::URL_LENGTH),
      self::Reference => C::string(22),
      self::Uuid => C::char(36),
      // Becomes NOT NULL AUTO_INCREMENT with its unique index (SchemaService), after existing rows are numbered
      self::AutoIncrement => C::bigint()->unsigned(),
      self::Media => C::string(22),
      self::Slug => C::string($length ?? self::DEFAULT_LENGTH),
      // MEDIUMTEXT: up to 16 MB
      self::Markdown, self::Custom => C::text(16777215),
      self::Group => C::text(16777215),
      self::Regex => C::string($length ?? self::DEFAULT_LENGTH),
      self::Order => C::bigint(),
      self::Enum => C::string(self::ENUM_LENGTH),
      self::Color => C::string(9),
      self::Phone => C::string(16),
      self::DateRange => C::string(21),
      // MEDIUMTEXT: up to 16 MB
      self::Json, self::Code => C::text(16777215),
    };
    return $column->null();
  }

  /**
   * Text indexes need a bounded length - a TEXT column cannot be unique.
   */
  public function canBeUnique(): bool
  {
    return !in_array($this, [self::Text, self::Boolean, self::Media, self::Markdown, self::Custom, self::Group, self::Order, self::Json, self::Code], true);
  }

  /**
   * Values point to another table (foreign key fk_<field id>).
   */
  public function hasForeignKey(): bool
  {
    return self::Reference === $this || self::Media === $this;
  }

  public function hasMediaAccept(): bool
  {
    return self::Media === $this;
  }

  public function isTextual(): bool
  {
    return in_array($this, [self::String, self::Text, self::Email, self::Url, self::Uuid, self::Slug, self::Markdown, self::Regex, self::Enum, self::Phone, self::Json], true);
  }

  public function isInteger(): bool
  {
    return self::Integer === $this || self::AutoIncrement === $this || self::Order === $this;
  }

  /**
   * Values the database assigns itself: always unique, never required.
   */
  public function isGenerated(): bool
  {
    return self::AutoIncrement === $this;
  }

  public function hasUuidVersion(): bool
  {
    return self::Uuid === $this;
  }

  public static function newUuid(int $version): string
  {
    $uuid = match ($version) {
      1 => Uuid::v1(),
      4 => Uuid::v4(),
      6 => Uuid::v6(),
      default => Uuid::v7(),
    };
    return $uuid->toRfc4122();
  }

  public function hasLength(): bool
  {
    return self::String === $this || self::Slug === $this || self::Regex === $this;
  }

  /**
   * Types that can hold a list of values. Not: counters, UUIDs and slugs (unique by nature) and yes/no.
   */
  public function canRepeat(): bool
  {
    return !in_array($this, [self::AutoIncrement, self::Uuid, self::Slug, self::Boolean, self::Order, self::DateRange, self::Json, self::Code, self::Custom], true);
  }

  /**
   * Types that can be used inside a field group: not the ones that need a column of their own
   * (counters, UUIDs, slugs - unique per table).
   */
  public function canBeInGroup(): bool
  {
    return !in_array($this, [self::AutoIncrement, self::Uuid, self::Slug, self::Order], true);
  }

  /**
   * Types that can be translatable (one value per language of the project).
   */
  public function canBeTranslated(): bool
  {
    return in_array($this, [self::String, self::Text, self::Markdown, self::Custom, self::Url, self::Slug, self::Group, self::Regex], true);
  }

  /**
   * Text fields a slug can be made from.
   */
  public function canBeSlugSource(): bool
  {
    return in_array($this, [self::String, self::Text, self::Email, self::Markdown, self::Regex], true);
  }

  public function hasScale(): bool
  {
    return self::Decimal === $this;
  }

  /**
   * @return list<array{value: string, label: string}>
   */
  public static function options(): array
  {
    return array_map(static fn(self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
  }
}
