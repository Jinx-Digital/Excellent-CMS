<?php

declare(strict_types=1);

namespace App\Application\Content;

/**
 * SQL of a sort subquery (no parameters).
 *
 * @internal
 */
final class SortSubquery implements \Stringable
{
  public function __construct(public readonly string $sql)
  {
  }

  public function __toString(): string
  {
    return $this->sql;
  }
}
