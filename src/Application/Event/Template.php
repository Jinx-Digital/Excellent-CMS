<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * Placeholders in the steps of an event: {{record.title}}, {{old.price}}, {{record.image.url}},
 * {{event.name}}, {{event.action}}, {{project.url}} or {{url}} (project variables), {{count}}.
 *
 * A value that is only a placeholder keeps its type ("{{record.price}}" -> 12.5, a list stays a
 * list); placeholders inside text are written into it (lists and objects as JSON). Unknown ones
 * become empty.
 */
final class Template
{
  private const PLACEHOLDER = '/\{\{\s*([a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*\}\}/i';

  /**
   * @param array<string, mixed> $context
   */
  public static function render(mixed $value, array $context): mixed
  {
    if (is_array($value)) {
      return array_map(static fn($item) => self::render($item, $context), $value);
    }
    if (!is_string($value)) {
      return $value;
    }
    if (1 === preg_match('/^\s*\{\{\s*([a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*\}\}\s*$/i', $value, $match)) {
      return self::lookup($context, $match[1]);
    }
    return (string)preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($context): string {
      $found = self::lookup($context, $match[1]);
      return match (true) {
        null === $found => '',
        is_bool($found) => $found ? 'true' : 'false',
        is_array($found) || is_object($found) => (string)json_encode($found, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        default => (string)$found,
      };
    }, $value);
  }

  private static function lookup(array $context, string $path): mixed
  {
    // {{url}} is {{project.url}}, as in text values
    if (!str_contains($path, '.') && !array_key_exists($path, $context) && is_array($context['project'] ?? null) && array_key_exists($path, $context['project'])) {
      return $context['project'][$path];
    }
    $value = $context;
    foreach (explode('.', $path) as $key) {
      if (is_object($value)) {
        $value = (array)$value;
      }
      if (!is_array($value) || !array_key_exists($key, $value)) {
        return null;
      }
      $value = $value[$key];
    }
    return $value;
  }
}
