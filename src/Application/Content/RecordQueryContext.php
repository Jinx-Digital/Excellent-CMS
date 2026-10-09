<?php

declare(strict_types=1);

namespace App\Application\Content;

use Closure;

/**
 * State of one apply() call: language, access check and the next table alias.
 *
 * @internal
 */
final class RecordQueryContext
{
  private int $aliases = 0;

  public function __construct(
    public readonly ?string $language,
    public readonly ?Closure $canRead,
    /** Fields switched off for filters in the schema are refused (not for event conditions) */
    public readonly bool $schemaLimits = true,
  ) {
  }

  public function nextAlias(): string
  {
    return 'ref'.++$this->aliases;
  }
}
