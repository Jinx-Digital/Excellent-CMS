<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * An event step of a plugin (see PluginRegistry::eventStep()).
 */
final class PluginStep
{
  /**
   * @param list<array<string, mixed>> $fields
   */
  public function __construct(
    public readonly string $plugin,
    public readonly string $type,
    public readonly string $label,
    public readonly string $description,
    public readonly string $icon,
    public readonly array $fields,
    public readonly \Closure $handler,
    public readonly ?\Closure $preview = null,
  ) {
  }

  /**
   * Problems of the step as entered (required fields, options).
   *
   * @return list<string>
   */
  public function errors(array $step): array
  {
    $errors = [];
    foreach ($this->fields as $field) {
      $key = (string)$field['key'];
      $value = $step[$key] ?? null;
      $empty = null === $value || '' === $value || [] === $value;
      if (($field['required'] ?? false) && $empty && 'bool' !== ($field['kind'] ?? 'text')) {
        $errors[] = sprintf('"%s" is missing.', $field['label'] ?? $key);
      }
      if (!$empty && 'select' === ($field['kind'] ?? '') && !in_array((string)$value, array_map(static fn(array $o): string => (string)$o['value'], (array)($field['options'] ?? [])), true)) {
        $errors[] = sprintf('"%s": "%s" is no option.', $field['label'] ?? $key, (string)$value);
      }
    }
    return $errors;
  }

  public function toArray(): array
  {
    return ['type' => $this->type, 'plugin' => $this->plugin, 'label' => $this->label, 'description' => $this->description, 'icon' => $this->icon, 'fields' => $this->fields];
  }
}
