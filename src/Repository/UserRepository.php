<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Access\AccessControl;
use App\Domain\Entity\User;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

final class UserRepository
{
  public function __construct(
    private ConnectionInterface $db,
    private AccessControl $access,
  ) {
  }

  public function get(string $id): ?User
  {
    return $this->one($this->db->createQuery()->from('user')->where(['id' => $id]));
  }

  public function findByEmail(string $email): ?User
  {
    $email = mb_strtolower(trim($email));
    return '' === $email ? null : $this->one($this->db->createQuery()->from('user')->where(['email' => $email]));
  }

  /**
   * List query; rows are turned into users (with permissions) by the result callback.
   */
  public function search(string $search = '', ?string $sort = null): QueryInterface
  {
    $query = $this->db->createQuery()->from('user')->resultCallback(fn(array $rows): array => $this->hydrate($rows));
    if ('' !== trim($search)) {
      $query->andWhere(['or', ['like', 'name', trim($search)], ['like', 'email', trim($search)]]);
    }
    $column = ltrim((string)$sort, '-');
    $query->orderBy([in_array($column, ['name', 'email', 'last_login_at', 'created_at'], true) ? $column : 'name' => str_starts_with((string)$sort, '-') ? SORT_DESC : SORT_ASC]);
    return $query;
  }

  public function emailExists(string $email, ?string $exceptId = null): bool
  {
    $query = $this->db->createQuery()->from('user')->where(['email' => $email]);
    if (null !== $exceptId) {
      $query->andWhere(['<>', 'id', $exceptId]);
    }
    return $query->exists();
  }

  public function countActiveAdmins(?string $exceptId = null): int
  {
    $query = $this->db->createQuery()->from('user')->where(['is_admin' => true, 'is_active' => true]);
    if (null !== $exceptId) {
      $query->andWhere(['<>', 'id', $exceptId]);
    }
    return (int)$query->count();
  }

  /**
   * @param list<string> $ids
   * @return array<string, string> id => name
   */
  public function names(array $ids): array
  {
    return [] === $ids ? [] : array_map('strval', array_column($this->db->createQuery()->select(['id', 'name'])->from('user')->where(['id' => $ids])->all(), 'name', 'id'));
  }

  public function count(): int
  {
    return (int)$this->db->createQuery()->from('user')->count();
  }

  /**
   * Saves the user and replaces the per-entity permissions.
   */
  public function save(User $user): void
  {
    $this->db->transaction(function () use ($user): void {
      $row = $user->toRow();
      $now = date('Y-m-d H:i:s');
      if ($this->db->createQuery()->from('user')->where(['id' => $user->getId()])->exists()) {
        unset($row['id']);
        $this->db->createCommand()->update('user', $row + ['updated_at' => $now], ['id' => $user->getId()])->execute();
      } else {
        $this->db->createCommand()->insert('user', $row + ['created_at' => $now, 'updated_at' => $now])->execute();
      }

      // Permissions and roles: yiisoft/rbac (see AccessControl)
      $subject = AccessControl::user($user->getId());
      $this->access->setDirectEntityPermissions($subject, $user->getPermissions());
      $this->access->setRoles($subject, $user->getRoles());
      $this->db->createCommand()->delete('user_project', ['user_id' => $user->getId()])->execute();
      if ([] !== $user->getProjectIds()) {
        $projects = array_map(static fn(string $projectId): array => ['user_id' => $user->getId(), 'project_id' => $projectId], $user->getProjectIds());
        $this->db->createCommand()->insertBatch('user_project', $projects, ['user_id', 'project_id'])->execute();
      }
    });
  }

  public function updateLogin(User $user, ?string $passwordHash = null): void
  {
    $columns = ['last_login_at' => date('Y-m-d H:i:s')];
    if (null !== $passwordHash) {
      $columns['password_hash'] = $passwordHash;
    }
    $this->db->createCommand()->update('user', $columns, ['id' => $user->getId()])->execute();
  }

  public function delete(string $id): bool
  {
    $this->access->forget(AccessControl::user($id));
    return $this->db->createCommand()->delete('user', ['id' => $id])->execute() > 0;
  }

  private function one(QueryInterface $query): ?User
  {
    $row = $query->one();
    return is_array($row) ? ($this->hydrate([$row])[0] ?? null) : null;
  }

  /**
   * One query for the permissions of all users of a page.
   *
   * @return list<User>
   */
  private function hydrate(array $rows): array
  {
    if ([] === $rows) {
      return [];
    }
    $access = $this->access;
    $access->preload(array_map(static fn(array $row): string => AccessControl::user((string)$row['id']), $rows));

    $projectsByUser = [];
    foreach ($this->db->createQuery()->from('user_project')->where(['user_id' => array_column($rows, 'id')])->all() as $row) {
      $projectsByUser[$row['user_id']][] = (string)$row['project_id'];
    }
    return array_map(static function (array $row) use ($access, $projectsByUser): User {
      $user = User::fromRow($row);
      $subject = AccessControl::user((string)$row['id']);
      $user->setPermissions($access->entityPermissions($subject, true));
      $user->setRoles($access->roles($subject));
      $user->setAccess($access->entityPermissions($subject), $access->has($subject, AccessControl::TAKE_OVER));
      $user->setProjectIds($projectsByUser[$row['id']] ?? []);
      return $user;
    }, array_values($rows));
  }
}
