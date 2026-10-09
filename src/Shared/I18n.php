<?php

declare(strict_types=1);

namespace App\Shared;

use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\TranslatorInterface;

/**
 * Texts for people (messages, mails, labels): written in English in the code, translated with
 * yiisoft/translator (messages/<locale>/app.php). ICU format: "{name}", "{count, plural, ...}".
 *
 *     I18n::t('The entity "{slug}" does not exist.', ['slug' => $slug])
 *
 * Static, because many messages come from static domain code (value conversion, field types).
 * LocaleMiddleware sets the translator and the language of the request; without one (unit tests,
 * console) the English text is only formatted.
 */
final class I18n
{
  public const LOCALES = ['en', 'de'];
  public const DEFAULT_LOCALE = 'en';

  private static ?TranslatorInterface $translator = null;

  public static function setTranslator(?TranslatorInterface $translator): void
  {
    self::$translator = $translator;
  }

  /**
   * @param array<string, mixed> $parameters
   */
  public static function t(string $message, array $parameters = []): string
  {
    if (null !== self::$translator) {
      return self::$translator->translate($message, $parameters);
    }
    return (new IntlMessageFormatter())->format($message, $parameters, self::DEFAULT_LOCALE);
  }

  /**
   * A value in quotes of the current language: „Wert“ / "value".
   */
  public static function quote(string $text): string
  {
    return 'de' === self::locale() ? '„'.$text.'“' : '"'.$text.'"';
  }

  public static function locale(): string
  {
    return null !== self::$translator ? self::$translator->getLocale() : self::DEFAULT_LOCALE;
  }

  /**
   * Best supported language of an Accept-Language header ("de-DE,de;q=0.9,en;q=0.8" -> de).
   */
  public static function fromAcceptLanguage(string $header): string
  {
    $best = null;
    $bestQuality = -1.0;
    foreach (explode(',', $header) as $part) {
      $pieces = explode(';', trim($part));
      $code = strtolower(substr(trim($pieces[0]), 0, 2));
      $quality = 1.0;
      foreach (array_slice($pieces, 1) as $piece) {
        if (str_starts_with(trim($piece), 'q=')) {
          $quality = (float)substr(trim($piece), 2);
        }
      }
      if (in_array($code, self::LOCALES, true) && $quality > $bestQuality) {
        [$best, $bestQuality] = [$code, $quality];
      }
    }
    return $best ?? self::DEFAULT_LOCALE;
  }
}
