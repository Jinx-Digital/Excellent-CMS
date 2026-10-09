<?php

declare(strict_types=1);

namespace App\Domain\Schema;

/**
 * A field type of a plugin (see PluginRegistry::fieldType()). Stored as type "custom" with its id
 * ("<plugin>.<key>", e.g. "geo.point") in the column custom_type; values are text (MEDIUMTEXT) -
 * what the plugin makes of them. Every hook is optional:
 *
 *   toStorage(mixed $value, FieldDefinition $field): ?string   checks and converts what was sent
 *                                                               (throws \InvalidArgumentException)
 *   fromStorage(?string $stored): mixed                        the value of the API (default: JSON or text)
 *   present(mixed $value, array $files): mixed                 for the API, e.g. fresh image addresses
 *   text(mixed $value): string                                 for the search
 *   mediaIds(mixed $value): list<string>                       files it uses (kept by the cleanup)
 */
final class CustomFieldType
{
  /**
   * @param array{tag: string, script: string}|null $component web component of the admin app
   */
  public function __construct(
    public readonly string $type,
    public readonly string $plugin,
    public readonly string $label,
    public readonly string $description = '',
    public readonly string $icon = 'i-lucide-puzzle',
    public readonly ?array $component = null,
    public readonly ?\Closure $toStorage = null,
    public readonly ?\Closure $fromStorage = null,
    public readonly ?\Closure $present = null,
    public readonly ?\Closure $text = null,
    public readonly ?\Closure $mediaIds = null,
    public readonly ?\Closure $config = null,
  ) {
  }
}
