<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Domain\Access\EntityPermission;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;

/**
 * Roles and permissions with yiisoft/rbac (tables yii_rbac_*). Subjects are users ("user:<id>") and
 * API clients ("client:<id>"); both get roles and permissions of their own ("direct").
 *
 *   entity.<entity id>.<read|create|update|delete|import>   records of an entity
 *   media.upload, media.delete                              media of the project (API clients)
 *   records.take_over                                       take over records others are editing
 *   role.<slug>                                             a role: contains permissions and other roles
 *
 * Administrators are no role: they may always do everything (User::isAdmin). Fields can be limited
 * to roles in the schema (FieldDefinition::readRoles / writeRoles), see FieldAccess.
 *
 * Reading goes to the tables directly and is cached for the request; changes go through the RBAC
 * manager (it refuses circles of roles).
 */
final class AccessControl
{
  public const ROLE_PREFIX = 'role.';
  public const TAKE_OVER = 'records.take_over';
  public const MEDIA_UPLOAD = 'media.upload';
  public const MEDIA_DELETE = 'media.delete';
  private const ITEMS = 'yii_rbac_item';
  private const CHILDREN = 'yii_rbac_item_child';
  private const ASSIGNMENTS = 'yii_rbac_assignment';

  /** @var array<string, list<string>>|null parent => children */
  private ?array $children = null;
  /** @var array<string, list<string>> subject => assigned item names */
  private array $assigned = [];
  /** @var array<string, array<string, true>> subject => everything reachable */
  private array $effective = [];

  public function __construct(
    private ConnectionInterface $db,
    private ManagerInterface $manager,
  ) {
  }

  public static function user(string $id): string
  {
    return 'user:'.$id;
  }

  public static function client(string $id): string
  {
    return 'client:'.$id;
  }

  public static function entityPermission(string $entityId, EntityPermission|string $action): string
  {
    return 'entity.'.$entityId.'.'.($action instanceof EntityPermission ? $action->value : $action);
  }

  public static function roleItem(string $slug): string
  {
    return self::ROLE_PREFIX.$slug;
  }

  // ---------------------------------------------------------------------------------------------
  // Reading

  /**
   * Loads the assignments of many subjects with one query (lists of users and clients).
   *
   * @param list<string> $subjects
   */
  public function preload(array $subjects): void
  {
    $missing = array_values(array_diff($subjects, array_keys($this->assigned)));
    if ([] === $missing) {
      return;
    }
    foreach ($missing as $subject) {
      $this->assigned[$subject] = [];
    }
    foreach ($this->db->createQuery()->from(self::ASSIGNMENTS)->select(['user_id', 'item_name'])->where(['user_id' => $missing])->all() as $row) {
      $this->assigned[(string)$row['user_id']][] = (string)$row['item_name'];
    }
  }

  /**
   * Item names given to the subject itself (permissions and roles).
   *
   * @return list<string>
   */
  public function directItems(string $subject): array
  {
    return $this->assigned($subject);
  }

  /**
   * Has the subject the permission (or role item) - directly or through its roles?
   */
  public function has(string $subject, string $item): bool
  {
    return isset($this->effective($subject)[$item]);
  }

  /**
   * Entity permissions: entity id => permission => true. $direct: only those given to the subject
   * itself, otherwise those of its roles too.
   *
   * @return array<string, array<string, bool>>
   */
  public function entityPermissions(string $subject, bool $direct = false): array
  {
    $items = $direct ? array_fill_keys($this->assigned($subject), true) : $this->effective($subject);
    return self::entityMap(array_keys($items));
  }

  /**
   * Slugs of the roles: $direct the ones given to the subject, otherwise the inherited ones too.
   *
   * @return list<string>
   */
  public function roles(string $subject, bool $direct = true): array
  {
    $items = $direct ? $this->assigned($subject) : array_keys($this->effective($subject));
    return array_values(array_map(static fn(string $item): string => substr($item, strlen(self::ROLE_PREFIX)), array_filter($items, static fn(string $item): bool => str_starts_with($item, self::ROLE_PREFIX))));
  }

  // ---------------------------------------------------------------------------------------------
  // Changing what a subject has

  /**
   * Replaces the entity permissions given to the subject itself - only for the given entities (the
   * admin app edits one project; others stay), all if null.
   *
   * @param array<string, array<string, bool>> $permissions entity id => permission => allowed
   * @param list<string>|null $entityIds
   */
  public function setDirectEntityPermissions(string $subject, array $permissions, ?array $entityIds = null): void
  {
    $this->transaction(function () use ($subject, $permissions, $entityIds): void {
      foreach ($this->assigned($subject) as $item) {
        $entity = self::entityOf($item);
        if (null !== $entity && (null === $entityIds || in_array($entity, $entityIds, true))) {
          $this->manager->revoke($item, $subject);
        }
      }
      foreach ($permissions as $entityId => $values) {
        foreach (EntityPermission::cases() as $permission) {
          if ($values[$permission->value] ?? false) {
            $name = self::entityPermission((string)$entityId, $permission);
            $this->ensurePermission($name);
            $this->manager->assign($name, $subject);
          }
        }
      }
    });
  }

  /**
   * One permission given to the subject itself (media.upload …), on or off.
   */
  public function setDirect(string $subject, string $permission, bool $allowed): void
  {
    if ($allowed) {
      $this->ensurePermission($permission);
      $this->manager->assign($permission, $subject);
    } else {
      $this->manager->revoke($permission, $subject);
    }
    $this->reset();
  }

  /**
   * @param list<string> $slugs
   * @throws ValidationException unknown roles
   */
  public function setRoles(string $subject, array $slugs, string $errorKey = 'roles'): void
  {
    $slugs = array_values(array_unique(array_map('strval', $slugs)));
    $unknown = array_diff($slugs, array_column($this->allRoles(), 'slug'));
    if ([] !== $unknown) {
      throw ValidationException::field($errorKey, I18n::t('This role does not exist: {role}', ['role' => implode(', ', $unknown)]));
    }
    $this->transaction(function () use ($subject, $slugs): void {
      foreach ($this->roles($subject) as $slug) {
        if (!in_array($slug, $slugs, true)) {
          $this->manager->revoke(self::roleItem($slug), $subject);
        }
      }
      foreach ($slugs as $slug) {
        $this->manager->assign(self::roleItem($slug), $subject);
      }
    });
  }

  /**
   * Everything of a subject that is deleted.
   */
  public function forget(string $subject): void
  {
    $this->manager->revokeAll($subject);
    $this->reset();
  }

  /**
   * An entity that is deleted: its permissions are gone from roles and subjects.
   */
  public function removeEntity(string $entityId): void
  {
    foreach (EntityPermission::cases() as $permission) {
      $this->manager->removePermission(self::entityPermission($entityId, $permission));
    }
    $this->reset();
  }

  // ---------------------------------------------------------------------------------------------
  // Roles

  /**
   * @return list<array{slug: string, name: string, roles: list<string>, permissions: array<string, array<string, bool>>, media_upload: bool, media_delete: bool, take_over: bool, users: int, clients: int}>
   */
  public function allRoles(): array
  {
    $rows = array_values(array_filter(
      $this->db->createQuery()->from(self::ITEMS)->where(['type' => 'role'])->orderBy(['description' => SORT_ASC])->all(),
      static fn(array $row): bool => str_starts_with((string)$row['name'], self::ROLE_PREFIX),
    ));
    $counts = [];
    $names = array_column($rows, 'name');
    foreach ([] === $names ? [] : $this->db->createQuery()->from(self::ASSIGNMENTS)->select(['item_name', 'user_id'])->where(['item_name' => $names])->all() as $row) {
      $kind = str_starts_with((string)$row['user_id'], 'client:') ? 'clients' : 'users';
      $counts[(string)$row['item_name']][$kind] = ($counts[(string)$row['item_name']][$kind] ?? 0) + 1;
    }
    $children = $this->children();
    return array_map(function (array $row) use ($children, $counts): array {
      $name = (string)$row['name'];
      $own = $children[$name] ?? [];
      return [
        'slug' => substr($name, strlen(self::ROLE_PREFIX)),
        'name' => '' !== (string)$row['description'] ? (string)$row['description'] : substr($name, strlen(self::ROLE_PREFIX)),
        'roles' => array_values(array_map(static fn(string $child): string => substr($child, strlen(self::ROLE_PREFIX)), array_filter($own, static fn(string $child): bool => str_starts_with($child, self::ROLE_PREFIX)))),
        'permissions' => (object)self::entityMap($own),
        'media_upload' => in_array(self::MEDIA_UPLOAD, $own, true),
        'media_delete' => in_array(self::MEDIA_DELETE, $own, true),
        'take_over' => in_array(self::TAKE_OVER, $own, true),
        'users' => $counts[$name]['users'] ?? 0,
        'clients' => $counts[$name]['clients'] ?? 0,
      ];
    }, $rows);
  }

  /**
   * Creates ($slug null) or changes a role: {slug, name, roles, permissions, media_upload,
   * media_delete, take_over}. Entity permissions only change for the given entities ($entityIds,
   * the current project) - the others stay.
   *
   * @param list<string>|null $entityIds
   */
  public function saveRole(?string $slug, array $data, ?array $entityIds = null): array
  {
    $errors = [];
    $name = trim((string)($data['name'] ?? ''));
    if ('' === $name || mb_strlen($name) > 100) {
      $errors['name'][] = I18n::t('Please enter a name (at most 100 characters).');
    }
    $isNew = null === $slug;
    $slug ??= trim((string)($data['slug'] ?? ''));
    $existing = array_column($this->allRoles(), null, 'slug');
    if ($isNew) {
      if (1 !== preg_match('/^[a-z][a-z0-9_]{0,59}$/', $slug)) {
        $errors['slug'][] = I18n::t('Lower case letters, digits and _, starting with a letter (at most 60).');
      } elseif (isset($existing[$slug])) {
        $errors['slug'][] = I18n::t('This role exists already.');
      }
    } elseif (!isset($existing[$slug])) {
      throw UserFacingException::notFound(I18n::t('This role does not exist: {role}', ['role' => $slug]));
    }
    $roles = array_values(array_unique(array_map('strval', (array)($data['roles'] ?? $existing[$slug]['roles'] ?? []))));
    foreach ($roles as $child) {
      if ($child === $slug || !isset($existing[$child])) {
        $errors['roles'][] = $child === $slug ? I18n::t('A role cannot contain itself.') : I18n::t('This role does not exist: {role}', ['role' => $child]);
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }

    $item = self::roleItem($slug);
    try {
      $this->transaction(function () use ($isNew, $item, $name, $roles, $data, $entityIds): void {
        $role = (new Role($item))->withDescription($name);
        $isNew ? $this->manager->addRole($role) : $this->manager->updateRole($item, $role);

        $current = $this->children()[$item] ?? [];
        // Contained roles
        foreach ($current as $child) {
          if (str_starts_with($child, self::ROLE_PREFIX) && !in_array(substr($child, strlen(self::ROLE_PREFIX)), $roles, true)) {
            $this->manager->removeChild($item, $child);
          }
        }
        foreach ($roles as $child) {
          if (!in_array(self::roleItem($child), $current, true)) {
            $this->manager->addChild($item, self::roleItem($child));
          }
        }
        // Entity permissions of the given entities
        if (array_key_exists('permissions', $data)) {
          foreach ($current as $child) {
            $entity = self::entityOf($child);
            if (null !== $entity && (null === $entityIds || in_array($entity, $entityIds, true))) {
              $this->manager->removeChild($item, $child);
            }
          }
          foreach ((array)$data['permissions'] as $entityId => $values) {
            foreach (EntityPermission::cases() as $permission) {
              if (filter_var(((array)$values)[$permission->value] ?? false, FILTER_VALIDATE_BOOL)) {
                $child = self::entityPermission((string)$entityId, $permission);
                $this->ensurePermission($child);
                if (!$this->manager->hasChild($item, $child)) {
                  $this->manager->addChild($item, $child);
                }
              }
            }
          }
        }
        // Other permissions
        foreach (['media_upload' => self::MEDIA_UPLOAD, 'media_delete' => self::MEDIA_DELETE, 'take_over' => self::TAKE_OVER] as $key => $permission) {
          if (!array_key_exists($key, $data)) {
            continue;
          }
          $on = (bool)filter_var($data[$key], FILTER_VALIDATE_BOOL);
          $has = $this->manager->hasChild($item, $permission);
          if ($on && !$has) {
            $this->ensurePermission($permission);
            $this->manager->addChild($item, $permission);
          } elseif (!$on && $has) {
            $this->manager->removeChild($item, $permission);
          }
        }
      });
    } catch (ValidationException|UserFacingException $e) {
      throw $e;
    } catch (Throwable $e) {
      // e.g. a circle: A contains B contains A
      throw ValidationException::field('roles', I18n::t('These roles would contain each other.'));
    }
    return array_column($this->allRoles(), null, 'slug')[$slug];
  }

  public function deleteRole(string $slug): void
  {
    if (!in_array($slug, array_column($this->allRoles(), 'slug'), true)) {
      throw UserFacingException::notFound(I18n::t('This role does not exist: {role}', ['role' => $slug]));
    }
    $this->manager->removeRole(self::roleItem($slug));
    $this->reset();
  }

  public function roleExists(string $slug): bool
  {
    return $this->db->createQuery()->from(self::ITEMS)->where(['name' => self::roleItem($slug), 'type' => 'role'])->exists();
  }

  // ---------------------------------------------------------------------------------------------

  /**
   * @return list<string>
   */
  private function assigned(string $subject): array
  {
    $this->preload([$subject]);
    return $this->assigned[$subject];
  }

  /**
   * @return array<string, true>
   */
  private function effective(string $subject): array
  {
    if (isset($this->effective[$subject])) {
      return $this->effective[$subject];
    }
    $children = $this->children();
    $result = [];
    $queue = $this->assigned($subject);
    while ([] !== $queue) {
      $item = array_shift($queue);
      if (isset($result[$item])) {
        continue;
      }
      $result[$item] = true;
      array_push($queue, ...($children[$item] ?? []));
    }
    return $this->effective[$subject] = $result;
  }

  /**
   * @return array<string, list<string>>
   */
  private function children(): array
  {
    if (null === $this->children) {
      $this->children = [];
      foreach ($this->db->createQuery()->from(self::CHILDREN)->all() as $row) {
        $this->children[(string)$row['parent']][] = (string)$row['child'];
      }
    }
    return $this->children;
  }

  /**
   * @param list<string> $items
   * @return array<string, array<string, bool>>
   */
  private static function entityMap(array $items): array
  {
    $map = [];
    foreach ($items as $item) {
      if (1 === preg_match('/^entity\.([^.]+)\.(\w+)$/', $item, $m) && null !== EntityPermission::tryFrom($m[2])) {
        $map[$m[1]][$m[2]] = true;
      }
    }
    return $map;
  }

  private static function entityOf(string $item): ?string
  {
    return 1 === preg_match('/^entity\.([^.]+)\.\w+$/', $item, $m) ? $m[1] : null;
  }

  private function ensurePermission(string $name): void
  {
    if (null === $this->manager->getPermission($name)) {
      $permission = new Permission($name);
      // Only own records: checked with the record's created_by
      if (str_ends_with($name, '_own')) {
        $permission = $permission->withRuleName(OwnRecordRule::class);
      }
      $this->manager->addPermission($permission);
    }
  }

  private function transaction(callable $callback): void
  {
    try {
      $this->db->transaction(static fn() => $callback());
    } finally {
      $this->reset();
    }
  }

  private function reset(): void
  {
    $this->children = null;
    $this->assigned = [];
    $this->effective = [];
  }
}
