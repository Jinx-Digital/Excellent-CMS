<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Settings of users in the admin app, as JSON per key.
 */
final class PreferenceRepository
{
  public function __construct(
    private ConnectionInterface $db,
  ) {
  }

  public function get(string $userId, string $key): mixed
  {
    $value = $this->db->createQuery()->select('value')->from('user_preference')->where(['user_id' => $userId, 'key' => $key])->scalar();
    return is_string($value) ? json_decode($value, true) : null;
  }

  public function set(string $userId, string $key, mixed $value): void
  {
    if (null === $value) {
      $this->db->createCommand()->delete('user_preference', ['user_id' => $userId, 'key' => $key])->execute();
      return;
    }
    $this->db->createCommand()->upsert('user_preference', [
      'user_id' => $userId,
      'key' => $key,
      'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
      'updated_at' => date('Y-m-d H:i:s'),
    ])->execute();
  }
}
