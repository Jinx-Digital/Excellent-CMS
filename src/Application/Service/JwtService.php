<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\User;
use RuntimeException;

/**
 * Minimal HS256 JWT (as in Fixoo) for CMS users. The token only identifies the user and the
 * user's token_version - status and permissions are always read from the database, so revoking
 * access takes effect immediately. OAuth clients get opaque tokens instead (OAuthService).
 */
class JwtService
{
  public function __construct(
    private string $secret,
    private int $ttl = 604800,
  ) {
  }

  public function generateForUser(User $user, ?int $ttl = null): string
  {
    return $this->encode([
      'sub' => $user->getId(),
      'ver' => $user->getTokenVersion(),
      'iat' => time(),
      'exp' => time() + ($ttl ?? $this->ttl),
    ]);
  }

  public function encode(array $payloadData): string
  {
    $header = $this->base64UrlEncode((string)json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = $this->base64UrlEncode((string)json_encode($payloadData));
    $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $this->getSecret(), true));

    return $header.'.'.$payload.'.'.$signature;
  }

  /**
   * Returns the payload of a correctly signed, not yet expired token - null otherwise.
   */
  public function decode(string $jwt): ?array
  {
    $parts = explode('.', $jwt);
    if (3 !== count($parts)) {
      return null;
    }

    [$header, $payload, $signature] = $parts;

    $validSignature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $this->getSecret(), true));
    if (!hash_equals($validSignature, $signature)) {
      return null;
    }

    $decodedJson = base64_decode(strtr($payload, '-_', '+/'), true);
    if (false === $decodedJson) {
      return null;
    }

    $data = json_decode($decodedJson, true);
    if (!is_array($data) || !isset($data['exp']) || (int)$data['exp'] < time()) {
      return null;
    }

    return $data;
  }

  private function base64UrlEncode(string $value): string
  {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private function getSecret(): string
  {
    if (strlen($this->secret) < 32) {
      throw new RuntimeException('JWT_SECRET must be set to at least 32 characters.');
    }
    return $this->secret;
  }
}
