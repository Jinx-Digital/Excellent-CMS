<?php

declare(strict_types=1);

namespace App\Application\Media;

use RuntimeException;

/**
 * Signed addresses of protected files: "/media/<path>?expires=…&signature=…". The API hands them
 * out with the records a reader may see (protected entities, admin app, media library), so the
 * file can be read without a token - e.g. in an <img>. Files of public entities need no signature.
 *
 * The expiry is rounded up to the next window of $ttl seconds: within a window every reader gets
 * the same address (browsers and CDNs can cache it), and it stays valid for $ttl to 2 * $ttl.
 */
final class MediaUrlSigner
{
  public function __construct(
    private string $secret,
    private int $ttl,
  ) {
  }

  public function sign(string $url, string $path): string
  {
    $expires = (intdiv(time(), $this->ttl) + 2) * $this->ttl;
    return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['expires' => $expires, 'signature' => $this->signature($path, $expires)]);
  }

  public function verify(string $path, mixed $expires, mixed $signature): bool
  {
    if (!is_numeric($expires) || !is_string($signature) || (int)$expires < time()) {
      return false;
    }
    return hash_equals($this->signature($path, (int)$expires), $signature);
  }

  private function signature(string $path, int $expires): string
  {
    if (strlen($this->secret) < 32) {
      throw new RuntimeException('JWT_SECRET must be set to at least 32 characters.');
    }
    // Own key, derived from the secret: a signature can never be used as a token and vice versa
    return hash_hmac('sha256', $path."\n".$expires, hash_hmac('sha256', 'media-urls', $this->secret));
  }
}
