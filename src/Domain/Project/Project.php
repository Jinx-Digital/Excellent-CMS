<?php

declare(strict_types=1);

namespace App\Domain\Project;

/**
 * A project: own entities (tables <tablePrefix><slug>), API clients and media, own content API
 * under /api/v1/<slug>/content, and its languages (the first one is the default language).
 */
final class Project
{
  /**
   * @param list<string> $languages language codes, default language first; empty = one language
   */
  public function __construct(
    public readonly string $id,
    public string $slug,
    public string $name,
    public string $tablePrefix,
    public ?string $description = null,
    public array $languages = [],
    public int $sortOrder = 0,
    /** @var list<array{name: string, label: string, translatable: bool, value: ?string, translations: array<string, ?string>}> */
    public array $variables = [],
    /** The area "Global": its entities are shared by all projects */
    public bool $isGlobal = false,
    /** Name of the storage new uploads go to (see MediaStorages), null = the default one */
    public ?string $mediaStorage = null,
  ) {
  }

  public static function fromRow(array $row): self
  {
    $languages = json_decode((string)($row['languages'] ?? ''), true);
    return new self(
      id: (string)$row['id'],
      slug: (string)$row['slug'],
      name: (string)$row['name'],
      tablePrefix: (string)$row['table_prefix'],
      description: null !== ($row['description'] ?? null) ? (string)$row['description'] : null,
      languages: is_array($languages) ? array_values(array_map('strval', $languages)) : [],
      sortOrder: (int)$row['sort_order'],
      // (variables had a label once - no longer)
      variables: is_array($variables = json_decode((string)($row['variables'] ?? ''), true)) ? array_values(array_map(static fn(array $variable): array => array_diff_key($variable, ['label' => true]), array_filter($variables, 'is_array'))) : [],
      isGlobal: (bool)($row['is_global'] ?? false),
      mediaStorage: null !== ($row['media_storage'] ?? null) ? (string)$row['media_storage'] : null,
    );
  }

  public function toRow(): array
  {
    return [
      'id' => $this->id,
      'slug' => $this->slug,
      'name' => $this->name,
      'description' => $this->description,
      'table_prefix' => $this->tablePrefix,
      'languages' => [] !== $this->languages ? json_encode(array_values($this->languages)) : null,
      'sort_order' => $this->sortOrder,
      'variables' => [] !== $this->variables ? json_encode($this->variables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
      'media_storage' => $this->mediaStorage,
    ];
  }

  public function defaultLanguage(): ?string
  {
    return $this->languages[0] ?? null;
  }

  /**
   * Languages besides the default one - translatable fields have a column for each.
   *
   * @return list<string>
   */
  public function otherLanguages(): array
  {
    return array_values(array_slice($this->languages, 1));
  }

  /**
   * Value of a variable in a language: its translation, otherwise the default value.
   */
  public function variable(string $name, ?string $language = null): ?string
  {
    foreach ($this->variables as $variable) {
      if ($variable['name'] === $name) {
        $translated = $variable['translatable'] && null !== $language && $language !== $this->defaultLanguage() ? ($variable['translations'][$language] ?? null) : null;
        return null !== $translated && '' !== $translated ? $translated : $variable['value'];
      }
    }
    return null;
  }

  /**
   * "{{url}}/impressum" -> "https://example.com/impressum" ({{project.url}} the same, see
   * VariableSyntax). Unknown names stay as they are.
   */
  public function replaceVariables(string $text, ?string $language = null): string
  {
    if ([] === $this->variables || !str_contains($text, '{{')) {
      return $text;
    }
    return (string)preg_replace_callback(VariableSyntax::PATTERN, fn(array $match): string => $this->variable(VariableSyntax::name($match[1]), $language) ?? $match[0], $text);
  }

  public function hasVariables(string $text): bool
  {
    return str_contains($text, '{{') && false !== preg_match_all(VariableSyntax::PATTERN, $text, $matches) && [] !== array_intersect(array_map(VariableSyntax::name(...), $matches[1]), array_column($this->variables, 'name'));
  }

  public function toArray(): array
  {
    return [
      'id' => $this->id,
      'slug' => $this->slug,
      'name' => $this->name,
      'description' => $this->description,
      'table_prefix' => $this->tablePrefix,
      'languages' => $this->languages,
      'default_language' => $this->defaultLanguage(),
      'variables' => $this->variables,
      'is_global' => $this->isGlobal,
      'media_storage' => $this->mediaStorage,
    ];
  }
}
