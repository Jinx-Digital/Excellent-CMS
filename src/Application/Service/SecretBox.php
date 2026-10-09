<?php

declare(strict_types=1);

namespace App\Application\Service;

use RuntimeException;

/**
 * Symmetric encryption (libsodium secretbox) for secrets that have to be stored in the
 * database, e.g. the credentials of storages entered in the admin app (see StorageService). The key comes from
 * APP_ENCRYPTION_KEY (base64 of 32 random bytes):
 *   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
 */
final class SecretBox
{
  private const PREFIX = 'v1:';

  private ?string $key;

  public function __construct(?string $key)
  {
    $decoded = null !== $key && '' !== $key ? base64_decode($key, true) : false;
    $this->key = false !== $decoded && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen($decoded) ? $decoded : null;
  }

  public function isAvailable(): bool
  {
    return null !== $this->key;
  }

  public function encrypt(string $plain): string
  {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
  }

  public function decrypt(string $encrypted): string
  {
    $raw = str_starts_with($encrypted, self::PREFIX) ? base64_decode(substr($encrypted, strlen(self::PREFIX)), true) : false;
    if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
      throw new RuntimeException('Invalid encrypted value.');
    }
    $plain = sodium_crypto_secretbox_open(
      substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
      substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
      $this->key(),
    );
    if (false === $plain) {
      throw new RuntimeException('Encrypted value can not be decrypted - was APP_ENCRYPTION_KEY changed?');
    }
    return $plain;
  }

  private function key(): string
  {
    return $this->key ?? throw new RuntimeException('APP_ENCRYPTION_KEY is not configured (base64 of 32 random bytes).');
  }
}
