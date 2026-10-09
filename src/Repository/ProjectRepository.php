<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Project\Project;
use Yiisoft\Db\Connection\ConnectionInterface;

final class ProjectRepository
{
  public function __construct(
    private ConnectionInterface $db,
  ) {
  }

  /**
   * @return list<Project>
   */
  public function all(): array
  {
    return array_map(Project::fromRow(...), $this->db->createQuery()->from('project')->orderBy(['sort_order' => SORT_ASC, 'name' => SORT_ASC])->all());
  }

  /**
   * The area "Global" (entities shared by all projects).
   */
  public function global(): ?Project
  {
    $row = $this->db->createQuery()->from('project')->where(['is_global' => true])->one();
    return is_array($row) ? Project::fromRow($row) : null;
  }

  public function find(string $idOrSlug): ?Project
  {
    $row = $this->db->createQuery()->from('project')->where(['or', ['id' => $idOrSlug], ['slug' => $idOrSlug]])->one();
    return is_array($row) ? Project::fromRow($row) : null;
  }

  public function slugExists(string $slug, ?string $exceptId = null): bool
  {
    return $this->exists(['slug' => $slug], $exceptId);
  }

  public function prefixExists(string $prefix, ?string $exceptId = null): bool
  {
    return $this->exists(['table_prefix' => $prefix], $exceptId);
  }

  public function save(Project $project, bool $isNew): void
  {
    $now = date('Y-m-d H:i:s');
    $row = $project->toRow();
    if ($isNew) {
      $this->db->createCommand()->insert('project', $row + ['created_at' => $now, 'updated_at' => $now])->execute();
    } else {
      unset($row['id']);
      $this->db->createCommand()->update('project', $row + ['updated_at' => $now], ['id' => $project->id])->execute();
    }
  }

  public function delete(string $id): void
  {
    $this->db->createCommand()->delete('project', ['id' => $id])->execute();
  }

  public function count(): int
  {
    return (int)$this->db->createQuery()->from('project')->count();
  }

  public function countEntities(string $projectId): int
  {
    return (int)$this->db->createQuery()->from('entity')->where(['project_id' => $projectId])->count();
  }

  public function nextSortOrder(): int
  {
    return (int)$this->db->createQuery()->from('project')->max('sort_order') + 1;
  }

  /**
   * @param array<string, mixed> $where
   */
  private function exists(array $where, ?string $exceptId): bool
  {
    $query = $this->db->createQuery()->from('project')->where($where);
    if (null !== $exceptId) {
      $query->andWhere(['<>', 'id', $exceptId]);
    }
    return $query->exists();
  }
}
