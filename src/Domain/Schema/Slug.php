<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use Yiisoft\Strings\Inflector;

/**
 * URL names: "Über uns & Team!" -> "ueber-uns-team". Taken ones get a number: "home", "home-2", ...
 */
final class Slug
{
  /** German umlauts as words expect them ("Über" -> "ueber"), then any script to ASCII */
  private const TRANSLITERATOR = 'de-ASCII; '.Inflector::TRANSLITERATE_LOOSE;

  private static ?Inflector $inflector = null;

  public static function make(string $text, int $maxLength = FieldType::DEFAULT_LENGTH): string
  {
    self::$inflector ??= (new Inflector())->withTransliterator(self::TRANSLITERATOR);
    return rtrim(substr(self::$inflector->toSlug($text), 0, $maxLength), '-');
  }
  public static function isValid(string $value): bool
  {
    return 1 === preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value);
  }

  /**
   * $slug if it is free, otherwise the first free "$slug-2", "$slug-3" ...
   *
   * @param array<string, true>|callable(string): bool $taken values already in use
   */
  public static function unique(string $slug, array|callable $taken, int $maxLength = FieldType::DEFAULT_LENGTH): string
  {
    $isTaken = is_array($taken) ? static fn(string $value): bool => isset($taken[$value]) : $taken;
    if (!$isTaken($slug)) {
      return $slug;
    }
    // "home-2" taken as a wish: count on from "home"
    $base = (string)preg_replace('/-\d+$/', '', $slug);
    for ($number = 2; ; $number++) {
      $suffix = '-'.$number;
      $candidate = rtrim(substr($base, 0, $maxLength - strlen($suffix)), '-').$suffix;
      if (!$isTaken($candidate)) {
        return $candidate;
      }
    }
  }
}
