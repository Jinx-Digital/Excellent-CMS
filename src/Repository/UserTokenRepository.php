<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * One-time tokens of users (password reset, new e-mail address).
 */
final class UserTokenRepository
{
  public function __construct(private ConnectionInterface $db)
  {
  }

  /**
   * A new token replaces the open ones of the same type.
   */
  public function replace(string $userId, string $type, string $tokenHash, int $ttl, ?string $data = null): void
  {
    $this->db->transaction(function () use ($userId, $type, $tokenHash, $ttl, $data): void {
      $this->deleteFor($userId, $type);
      $this->db->createCommand()->insert('user_token', [
        'token_hash' => $tokenHash,
        'user_id' => $userId,
        'type' => $type,
        'data' => $data,
        'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        'created_at' => date('Y-m-d H:i:s'),
      ])->execute();
    });
  }

  /**
   * Valid (not expired) token of this type.
   *
   * @return array{user_id: string, data: ?string}|null
   */
  public function findValid(string $tokenHash, string $type): ?array
  {
    $row = $this->db->createQuery()->from('user_token')
      ->where(['token_hash' => $tokenHash, 'type' => $type])
      ->andWhere(['>', 'expires_at', date('Y-m-d H:i:s')])
      ->one();
    return null === $row ? null : ['user_id' => (string)$row['user_id'], 'data' => null !== $row['data'] ? (string)$row['data'] : null];
  }

  /**
   * Open token of this type (the e-mail address waiting for confirmation).
   */
  public function pendingData(string $userId, string $type): ?string
  {
    $value = $this->db->createQuery()->select('data')->from('user_token')
      ->where(['user_id' => $userId, 'type' => $type])
      ->andWhere(['>', 'expires_at', date('Y-m-d H:i:s')])
      ->scalar();
    return false === $value || null === $value ? null : (string)$value;
  }

  public function deleteFor(string $userId, string $type): void
  {
    $this->db->createCommand()->delete('user_token', ['user_id' => $userId, 'type' => $type])->execute();
  }

  public function deleteExpired(): int
  {
    return $this->db->createCommand()->delete('user_token', ['<', 'expires_at', date('Y-m-d H:i:s')])->execute();
  }
}
