<?php

declare(strict_types=1);

namespace App\Application\Project;

use App\Application\Service\CurrentProject;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\InvalidValueException;
use App\Domain\Schema\ValueConverter;

/**
 * Project variables in text values: "{{url}}/impressum" is stored as typed and delivered by the
 * content API with the project's value - in the requested language if the variable is translatable.
 *
 * A value with placeholders is checked as it will be delivered (so "{{url}}/impressum" is a valid
 * link) but stored with its placeholders.
 */
final class ProjectVariables
{
  private const TYPES = [FieldType::String, FieldType::Text, FieldType::Markdown, FieldType::Url, FieldType::Email, FieldType::Regex];

  public function __construct(
    private CurrentProject $currentProject,
  ) {
  }

  public static function supports(FieldDefinition $field): bool
  {
    return in_array($field->type, self::TYPES, true);
  }

  /**
   * ValueConverter::toStorage() that understands placeholders.
   *
   * @throws InvalidValueException
   */
  public function toStorage(FieldDefinition $field, mixed $value, ?string $language = null): string|int|bool|null
  {
    $project = $this->currentProject->find();
    if (null === $project || !self::supports($field) || !is_string($value) || !$project->hasVariables($value)) {
      return ValueConverter::toStorage($field, $value);
    }
    // Checked as delivered, stored as typed (the length counts what is stored)
    ValueConverter::toStorage($field, $project->replaceVariables($value, $language));
    return ValueConverter::convert(in_array($field->type, [FieldType::Text, FieldType::Markdown], true) ? $field->type : FieldType::String, $value, $field->length ?? (FieldType::Url === $field->type ? FieldType::URL_LENGTH : FieldType::DEFAULT_LENGTH));
  }

  /**
   * A presented value with the project's variables filled in.
   */
  public function resolve(FieldDefinition $field, mixed $value, ?string $language = null): mixed
  {
    $project = $this->currentProject->find();
    return null !== $project && self::supports($field) && is_string($value) ? $project->replaceVariables($value, $language) : $value;
  }

  /**
   * Content API: all variables in a language ("all" = every language).
   *
   * @return array<string, mixed>
   */
  public function values(?string $language): array
  {
    $project = $this->currentProject->get();
    $result = [];
    foreach ($project->variables as $variable) {
      $result[$variable['name']] = 'all' === $language
        ? [(string)$project->defaultLanguage() => $variable['value']] + ($variable['translatable'] ? array_map(static fn($v) => $v, array_intersect_key($variable['translations'], array_flip($project->otherLanguages()))) : [])
        : $project->variable($variable['name'], $language);
    }
    return $result;
  }
}
