<?php

declare(strict_types=1);

namespace App\Domain\Project;

/**
 * How project variables are written in text values: {{url}} or {{project.url}} (the form events
 * use too), spaces inside allowed. Single braces ({url}) are plain text.
 */
final class VariableSyntax
{
  /**
   * Group 1: the name, see name() - Markdown editors write "_" as "\_" ({{company\_name}}), so an
   * escaped underscore counts as one too
   */
  public const PATTERN = '/\{\{\s*(?:project\.)?([a-z](?:[a-z0-9]|\\\\?_)*)\s*\}\}/';

  /** The name from group 1 of PATTERN, without Markdown escapes: "company\_name" -> "company_name" */
  public static function name(string $match): string
  {
    return str_replace('\\', '', $match);
  }
}
