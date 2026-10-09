<?php

declare(strict_types=1);

namespace App\Shared;

use Symfony\Component\Uid\Uuid;

/**
 * Ids of all rows (system and content): UUID v7 in base58 - time-ordered, 22 characters, and not
 * guessable like auto increment numbers in a public API.
 */
final class Id
{
  public static function new(): string
  {
    return Uuid::v7()->toBase58();
  }

  public static function isValid(string $id): bool
  {
    return 1 === preg_match('/^[1-9A-HJ-NP-Za-km-z]{21,22}$/', $id);
  }
}
