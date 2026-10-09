<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Rows of the table `plugin`: the installed plugins (see PluginManager).
 */
final class PluginRepository
{
  public function __construct(
    private ConnectionInterface $db,
  ) {
  }

  /**
   * @return array<string, array> name => row
   */
  public function all(): array
  {
    return $this->db->createQuery()->from('plugin')->indexBy('name')->all();
  }

  public function find(string $name): ?array
  {
    $row = $this->db->createQuery()->from('plugin')->where(['name' => $name])->one();
    return is_array($row) ? $row : null;
  }

  public function insert(array $row): void
  {
    $this->db->createCommand()->insert('plugin', $row + ['installed_at' => date('Y-m-d H:i:s')])->execute();
  }

  public function update(string $name, array $values): void
  {
    $this->db->createCommand()->update('plugin', $values + ['updated_at' => date('Y-m-d H:i:s')], ['name' => $name])->execute();
  }

  public function delete(string $name): void
  {
    $this->db->createCommand()->delete('plugin', ['name' => $name])->execute();
  }

  public function db(): ConnectionInterface
  {
    return $this->db;
  }
}
