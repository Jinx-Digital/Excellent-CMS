<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Shared\I18n;

/**
 * Values of the field type "code": code as it was written - {language, file, code}. Nothing is parsed,
 * cleaned or escaped: the website decides how to output it (as text in <pre><code>, or - for HTML
 * snippets it trusts - as it is). Plain text is taken too (the language: "text").
 */
final class CodeValue
{
  public const LANGUAGES = ['html', 'twig', 'css', 'javascript', 'php', 'json', 'markdown', 'sql', 'shell', 'text'];
  public const DEFAULT_LANGUAGE = 'html';
  private const MAX = 200000;

  /**
   * As stored: JSON {language, file, code} - null for no code.
   *
   * @throws InvalidValueException
   */
  public static function toStorage(mixed $value): ?string
  {
    if (is_string($value)) {
      $decoded = json_decode($value, true);
      $value = is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded : ['code' => $value];
    }
    if (is_object($value)) {
      $value = (array)$value;
    }
    if (!is_array($value) || !is_string($value['code'] ?? null)) {
      throw new InvalidValueException(I18n::t('Please send the code as text or as an object with "language", "file" and "code".'));
    }
    if ('' === trim($value['code'])) {
      return null;
    }
    if (strlen($value['code']) > self::MAX) {
      throw new InvalidValueException(I18n::t('The code is too long (at most {size} KB).', ['size' => self::MAX / 1000]));
    }
    $language = (string)($value['language'] ?? '') ?: self::DEFAULT_LANGUAGE;
    if (!in_array($language, self::LANGUAGES, true)) {
      throw new InvalidValueException(I18n::t('"{value}" is not allowed. Allowed: {values}.', ['value' => $language, 'values' => implode(', ', self::LANGUAGES)]));
    }
    $file = mb_substr(trim((string)($value['file'] ?? '')), 0, 120);
    // The code exactly as written - only the line breaks of Windows become \n
    return json_encode(['language' => $language, 'file' => '' !== $file ? $file : null, 'code' => str_replace("\r\n", "\n", $value['code'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * For the API: {language, file, code} - also for values from before (plain text).
   *
   * @return array{language: string, file: ?string, code: string}
   */
  public static function fromStorage(string $stored): array
  {
    $value = json_decode($stored, true);
    if (is_array($value) && is_string($value['code'] ?? null)) {
      return ['language' => in_array($value['language'] ?? null, self::LANGUAGES, true) ? $value['language'] : 'text', 'file' => isset($value['file']) && '' !== $value['file'] ? (string)$value['file'] : null, 'code' => $value['code']];
    }
    return ['language' => 'text', 'file' => null, 'code' => $stored];
  }

  /**
   * Text for the search: file name and code.
   */
  public static function text(mixed $value): string
  {
    $value = is_string($value) ? self::fromStorage($value) : $value;
    return is_array($value) ? trim(($value['file'] ?? '').' '.($value['code'] ?? '')) : '';
  }
}
