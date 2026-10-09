<?php

declare(strict_types=1);

namespace App\Application\Service;

use Dotenv\Dotenv;

/**
 * References to .env variables in settings, so values like tokens are never stored:
 *
 *   "$SHOP_TOKEN"                        a whole value (secret, token, password)       -> resolve()
 *   "https://x.de/hook?token=$SHOP_TOKEN" inside a text, up to the first character
 *                                        that is no letter, digit or _               -> interpolate()
 *   "$$abc"                              the value "$abc" ("$$" is a "$")
 *
 * They are resolved whenever the setting is used. The admin app gets the names of the variables
 * (never their values) to suggest them when "$" is typed (GET /admin/env-vars). Used by event
 * steps, meant for every place that needs secrets.
 */
final class EnvVariables
{
  private const REFERENCE = '/^\$([A-Za-z_][A-Za-z0-9_]*)$/';
  private const PLACEHOLDER = '/\$\$|\$([A-Za-z_][A-Za-z0-9_]*)/';

  public function __construct(
    /** The .env file whose names are suggested (real environment variables count too) */
    private ?string $envFile = null,
  ) {
  }

  /**
   * Names of all variables, sorted - with whether they have a value.
   *
   * @return list<array{name: string, set: bool}>
   */
  public function names(): array
  {
    $names = array_keys($_ENV);
    if (null !== $this->envFile && is_file($this->envFile)) {
      $names = array_merge($names, array_keys(Dotenv::parse((string)file_get_contents($this->envFile))));
    }
    $names = array_values(array_unique(array_filter($names, static fn($name): bool => is_string($name) && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name))));
    sort($names);
    return array_map(fn(string $name): array => ['name' => $name, 'set' => '' !== $this->lookup($name)], $names);
  }

  /**
   * "$NAME" -> NAME, null for values.
   */
  public static function reference(?string $value): ?string
  {
    return null !== $value && 1 === preg_match(self::REFERENCE, trim($value), $m) ? $m[1] : null;
  }

  /**
   * Problem with a whole value as entered, null if it is fine.
   */
  public function check(?string $value): ?string
  {
    $value = trim((string)$value);
    if ('' === $value || !str_starts_with($value, '$') || str_starts_with($value, '$$')) {
      return null;
    }
    $name = self::reference($value);
    if (null === $name) {
      return sprintf('"%s" is no .env variable ($NAME, or $$ for a value starting with $).', $value);
    }
    return '' === $this->lookup($name) ? sprintf('%s is not set in the .env.', $name) : null;
  }

  /**
   * A whole value to use: "$NAME" resolved, "$$…" unescaped.
   */
  public function resolve(?string $value): string
  {
    $value = trim((string)$value);
    if (str_starts_with($value, '$$')) {
      return substr($value, 1);
    }
    $name = self::reference($value);
    return null !== $name ? $this->lookup($name) : $value;
  }

  /**
   * Names of the $NAME variables inside a text.
   *
   * @return list<string>
   */
  public static function placeholders(string $text): array
  {
    preg_match_all(self::PLACEHOLDER, $text, $matches);
    return array_values(array_unique(array_filter($matches[1], static fn(string $name): bool => '' !== $name)));
  }

  /**
   * Problems with the $NAME variables of a text.
   *
   * @return list<string>
   */
  public function checkPlaceholders(string $text): array
  {
    return array_values(array_filter(array_map(fn(string $name): ?string => $this->check('$'.$name), self::placeholders($text))));
  }

  /**
   * Replaces $NAME inside a text by the value - URL-encoded for URLs ($encode); "$$" becomes "$".
   */
  public function interpolate(string $text, bool $encode = false): string
  {
    return (string)preg_replace_callback(self::PLACEHOLDER, function (array $m) use ($encode): string {
      if ('$$' === $m[0]) {
        return '$';
      }
      $value = $this->lookup($m[1]);
      return $encode ? rawurlencode($value) : $value;
    }, $text);
  }

  private function lookup(string $name): string
  {
    $value = $_ENV[$name] ?? getenv($name);
    return is_string($value) ? $value : '';
  }
}
