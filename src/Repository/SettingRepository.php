<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Key/value settings, values stored as JSON.
 */
final class SettingRepository
{
  public function __construct(
    private ConnectionInterface $db,
  ) {
  }

  public function get(string $key, mixed $default = null): mixed
  {
    $value = $this->db->createQuery()->select('value')->from('setting')->where(['key' => $key])->scalar();
    if (false === $value || null === $value) {
      return $default;
    }
    return json_decode((string)$value, true) ?? $default;
  }

  public function set(string $key, mixed $value): void
  {
    $this->db->createCommand()->upsert('setting', [
      'key' => $key,
      'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
      'updated_at' => date('Y-m-d H:i:s'),
    ])->execute();
  }
}
