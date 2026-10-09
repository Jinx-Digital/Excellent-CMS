<?php

declare(strict_types=1);

namespace App\Application\Service;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Fixed-window counter in the database (table `rate_limit`) - works without Redis and with
 * several PHP processes. Used for the content API (optional, see SettingsService) and to slow
 * down password guessing (always on).
 */
final class RateLimiter
{
  public function __construct(
    private ConnectionInterface $db,
  ) {
  }

  /**
   * Counts one hit for $key.
   *
   * @return array{allowed: bool, limit: int, remaining: int, reset: int} reset = unix time the window ends
   */
  public function hit(string $key, int $limit, int $window): array
  {
    $window = max(1, $window);
    $now = time();
    $windowStart = $now - ($now % $window);
    $key = substr($key, 0, 120);

    // One statement, safe with parallel requests: a new window resets the counter
    $this->db->createCommand(
      'INSERT INTO {{rate_limit}} ([[key]], [[window_start]], [[hits]]) VALUES (:key, :start, 1)
       ON DUPLICATE KEY UPDATE [[hits]] = IF([[window_start]] = VALUES([[window_start]]), [[hits]] + 1, 1), [[window_start]] = VALUES([[window_start]])',
      [':key' => $key, ':start' => $windowStart]
    )->execute();

    $hits = (int)$this->db->createQuery()->select('hits')->from('rate_limit')->where(['key' => $key])->scalar();

    return [
      'allowed' => $hits <= $limit,
      'limit' => $limit,
      'remaining' => max(0, $limit - $hits),
      'reset' => $windowStart + $window,
    ];
  }

  /**
   * Hits of the current window without counting one (e.g. failed logins).
   */
  public function current(string $key, int $window): int
  {
    $now = time();
    $windowStart = $now - ($now % max(1, $window));
    return (int)$this->db->createQuery()->select('hits')->from('rate_limit')
      ->where(['key' => substr($key, 0, 120), 'window_start' => $windowStart])->scalar();
  }

  public function clear(string $key): void
  {
    $this->db->createCommand()->delete('rate_limit', ['key' => substr($key, 0, 120)])->execute();
  }

  public function deleteOlderThan(int $seconds): int
  {
    return $this->db->createCommand()->delete('rate_limit', ['<', 'window_start', time() - $seconds])->execute();
  }
}
