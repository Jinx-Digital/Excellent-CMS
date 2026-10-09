<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Service\CurrentUser;
use App\Domain\Schema\EntityDefinition;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

/**
 * Locks of records being edited in the admin app (table `record_lock`): whoever opens a record to
 * edit it locks it, and renews the lock while the page is open. Other users can only read it until
 * the lock is released or older than TTL (page closed without releasing). Admins and editors may
 * take it over. Writes of API clients (content API) are not affected.
 */
final class RecordLocks
{
  /** Seconds without renewal after which a lock is free again (the admin app renews every 30 s) */
  public const TTL = 120;

  public function __construct(
    private ConnectionInterface $db,
    private CurrentUser $currentUser,
  ) {
  }

  /**
   * The active lock of a record, null if it is free.
   *
   * @return array{mine: bool, user: array{id: string, name: string}, locked_at: string, seen_at: string}|null
   */
  public function get(EntityDefinition $entity, string $recordId): ?array
  {
    return $this->active($entity, [$recordId])[$recordId] ?? null;
  }

  /**
   * Locks the record for the current user (or renews their lock). Locked by someone else: their lock
   * is returned and nothing changes.
   *
   * @return array{mine: bool, user: array{id: string, name: string}, locked_at: string, seen_at: string}
   */
  public function acquire(EntityDefinition $entity, string $recordId): array
  {
    $userId = (string)$this->currentUser->getId();
    $now = date('Y-m-d H:i:s');
    $where = ['entity_id' => $entity->id, 'record_id' => $recordId];
    // Locks nobody renewed any more are free
    $this->db->createCommand()->delete('record_lock', ['and', $where, ['<', 'seen_at', self::expiry()]])->execute();
    try {
      $this->db->createCommand()->insert('record_lock', $where + ['user_id' => $userId, 'locked_at' => $now, 'seen_at' => $now])->execute();
    } catch (IntegrityException) {
      // Locked already - renewed if it is mine
      $this->db->createCommand()->update('record_lock', ['seen_at' => $now], $where + ['user_id' => $userId])->execute();
    }
    return $this->get($entity, $recordId) ?? throw new UserFacingException(I18n::t('The record could not be locked. Please try again.'));
  }

  /**
   * Admins and editors take over the lock of another user - who can then only read the record.
   */
  public function takeOver(EntityDefinition $entity, string $recordId): array
  {
    if (!$this->currentUser->getUser()->canTakeOverLocks()) {
      throw UserFacingException::forbidden(I18n::t('Only administrators and editors can take over records.'));
    }
    $now = date('Y-m-d H:i:s');
    $where = ['entity_id' => $entity->id, 'record_id' => $recordId];
    $this->db->createCommand()->delete('record_lock', $where)->execute();
    $this->db->createCommand()->insert('record_lock', $where + ['user_id' => (string)$this->currentUser->getId(), 'locked_at' => $now, 'seen_at' => $now])->execute();
    return $this->acquire($entity, $recordId);
  }

  /**
   * Releases the current user's lock (leaving the page).
   */
  public function release(EntityDefinition $entity, string $recordId): void
  {
    $this->db->createCommand()->delete('record_lock', ['entity_id' => $entity->id, 'record_id' => $recordId, 'user_id' => (string)$this->currentUser->getId()])->execute();
  }

  /**
   * Admin app writes: refused (423) while another user has the record locked.
   */
  public function assertNotLockedByOthers(EntityDefinition $entity, string $recordId): void
  {
    $this->assertNoneLockedByOthers($entity, [$recordId]);
  }

  /**
   * @param list<string> $recordIds
   */
  public function assertNoneLockedByOthers(EntityDefinition $entity, array $recordIds): void
  {
    // API clients are not affected
    if (null === $this->currentUser->getId()) {
      return;
    }
    $locks = array_filter($this->active($entity, $recordIds), static fn(array $lock): bool => !$lock['mine']);
    if ([] === $locks) {
      return;
    }
    $lock = reset($locks);
    throw new UserFacingException(
      1 === count($recordIds)
        ? I18n::t('{name} is editing this record right now.', ['name' => $lock['user']['name']])
        : I18n::t('{count, plural, one{# record is} other{# records are}} being edited by others right now.', ['count' => count($locks)]),
      423,
      'record_locked',
    );
  }

  /**
   * Active locks of the records, by record id.
   *
   * @param list<string> $recordIds
   * @return array<string, array{mine: bool, user: array{id: string, name: string}, locked_at: string, seen_at: string}>
   */
  public function active(EntityDefinition $entity, array $recordIds): array
  {
    if ([] === $recordIds) {
      return [];
    }
    $rows = $this->db->createQuery()
      ->select(['l.record_id', 'l.user_id', 'l.locked_at', 'l.seen_at', 'u.name'])
      ->from(['l' => 'record_lock'])
      ->innerJoin(['u' => 'user'], 'u.id = l.user_id')
      ->where(['l.entity_id' => $entity->id, 'l.record_id' => array_values($recordIds)])
      ->andWhere(['>=', 'l.seen_at', self::expiry()])
      ->all();
    $me = $this->currentUser->getId();
    $locks = [];
    foreach ($rows as $row) {
      $locks[(string)$row['record_id']] = [
        'mine' => (string)$row['user_id'] === $me,
        'user' => ['id' => (string)$row['user_id'], 'name' => (string)$row['name']],
        'locked_at' => (string)$row['locked_at'],
        'seen_at' => (string)$row['seen_at'],
      ];
    }
    return $locks;
  }

  /**
   * Records of a list: "_lock" with the user editing it, null if free.
   *
   * @param list<array<string, mixed>> $records
   * @return list<array<string, mixed>>
   */
  public function withLocks(EntityDefinition $entity, array $records): array
  {
    $locks = $this->active($entity, array_map(static fn(array $record): string => (string)$record['id'], $records));
    return array_map(static fn(array $record): array => $record + ['_lock' => $locks[(string)$record['id']] ?? null], $records);
  }

  /**
   * Removes locks nobody renews any more (`./yii cleanup`).
   */
  public function removeExpired(): int
  {
    return $this->db->createCommand()->delete('record_lock', ['<', 'seen_at', self::expiry()])->execute();
  }

  private static function expiry(): string
  {
    return date('Y-m-d H:i:s', time() - self::TTL);
  }
}
