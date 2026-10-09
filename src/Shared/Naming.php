<?php

declare(strict_types=1);

namespace App\Shared;

/**
 * Turns column headers and file names into technical names (snake_case, ASCII) and back into
 * readable labels.
 */
final class Naming
{
  public const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

  private const TRANSLITERATION = [
    'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ó' => 'o', 'ò' => 'o',
    'ô' => 'o', 'í' => 'i', 'ì' => 'i', 'ú' => 'u', 'ù' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ł' => 'l',
    'ś' => 's', 'ć' => 'c', 'ź' => 'z', 'ż' => 'z', 'ń' => 'n', 'ę' => 'e', 'ą' => 'a',
  ];

  /**
   * "Tournament Start-Date" / "TournamentStartDate" / "ISO-2" -> tournament_start_date / iso_2
   */
  public static function snake(string $value, int $maxLength = 64): string
  {
    $value = strtr(trim($value), self::TRANSLITERATION);
    // camelCase and PascalCase: "TournamentStartDate" -> "Tournament_Start_Date", "LogoId" -> "Logo_Id"
    $value = (string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $value);
    $value = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', $value));
    $value = trim($value, '_');
    if ('' !== $value && ctype_digit($value[0])) {
      $value = 'f_'.$value;
    }
    return rtrim(substr($value, 0, $maxLength), '_');
  }

  /**
   * "tournament_start_date" -> "Tournament start date"
   */
  public static function label(string $name): string
  {
    $label = trim(str_replace('_', ' ', $name));
    return '' === $label ? $name : mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
  }

  public static function isValid(string $name, int $maxLength = 64): bool
  {
    return strlen($name) <= $maxLength && 1 === preg_match(self::NAME_PATTERN, $name);
  }
}
