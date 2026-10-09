<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Rows of the table `storage`: the storages of the admin app (see StorageService).
 */
final class StorageRepository
{
  public function __construct(
    private ConnectionInterface $db,
  ) {
  }

  /**
   * @return list<array>
   */
  public function all(): array
  {
    return $this->db->createQuery()->from('storage')->orderBy(['name' => SORT_ASC])->all();
  }

  public function find(string $id): ?array
  {
    $row = $this->db->createQuery()->from('storage')->where(['id' => $id])->one();
    return is_array($row) ? $row : null;
  }

  public function nameExists(string $name): bool
  {
    return $this->db->createQuery()->from('storage')->where(['name' => $name])->exists();
  }

  public function insert(array $row): void
  {
    $this->db->createCommand()->insert('storage', $row + ['created_at' => date('Y-m-d H:i:s')])->execute();
  }

  public function update(string $id, array $values): void
  {
    $this->db->createCommand()->update('storage', $values + ['updated_at' => date('Y-m-d H:i:s')], ['id' => $id])->execute();
  }

  public function delete(string $id): void
  {
    $this->db->createCommand()->delete('storage', ['id' => $id])->execute();
  }

  /**
   * How many files and projects use a storage.
   *
   * @return array{media: int, projects: int}
   */
  public function usage(string $name): array
  {
    return [
      'media' => (int)$this->db->createQuery()->from('media')->where(['disk' => $name])->count(),
      'projects' => (int)$this->db->createQuery()->from('project')->where(['media_storage' => $name])->count(),
    ];
  }
}
