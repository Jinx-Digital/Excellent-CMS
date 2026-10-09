<?php

declare(strict_types=1);

namespace App\Application\Service;

use RuntimeException;

/**
 * Error of the token endpoint in the format of RFC 6749 section 5.2
 * ({"error": "invalid_client", "error_description": "..."}), so standard OAuth libraries
 * understand it.
 */
final class OAuthException extends RuntimeException
{
  public function __construct(
    public readonly string $error,
    string $description,
    public readonly int $status = 400,
  ) {
    parent::__construct($description);
  }
}
