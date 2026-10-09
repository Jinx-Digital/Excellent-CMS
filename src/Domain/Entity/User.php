<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Access\EntityPermission;

/**
 * A person working in the CMS (admin app). Readers of the content API are OAuth clients, not users.
 */
class User
{
  /** @var array<string, array<string, bool>> entity id => permission => allowed - given to the user itself */
  private array $permissions = [];
  /** @var array<string, array<string, bool>>|null the same with those of the roles (null: only its own) */
  private ?array $effective = null;
  /** @var list<string> slugs of the roles given to the user */
  private array $roles = [];
  /** May take over records others are editing (permission records.take_over, e.g. role "editor") */
  private bool $takeOver = false;
  /** @var list<string> ids of the projects the user works in (admins: all) */
  private array $projectIds = [];

  public function __construct(
    private string $id,
    private string $name,
    private string $email,
    private string $passwordHash,
    private bool $isAdmin = false,
    private bool $isActive = true,
    private int $tokenVersion = 1,
    private ?string $lastLoginAt = null,
    private ?string $createdAt = null,
  ) {
  }

  public static function fromRow(array $row): self
  {
    return new self(
      id: (string)$row['id'],
      name: (string)$row['name'],
      email: (string)$row['email'],
      passwordHash: (string)$row['password_hash'],
      isAdmin: (bool)$row['is_admin'],
      isActive: (bool)$row['is_active'],
      tokenVersion: (int)$row['token_version'],
      lastLoginAt: null !== $row['last_login_at'] ? (string)$row['last_login_at'] : null,
      createdAt: null !== $row['created_at'] ? (string)$row['created_at'] : null,
    );
  }

  public function toRow(): array
  {
    return [
      'id' => $this->id,
      'name' => $this->name,
      'email' => $this->email,
      'password_hash' => $this->passwordHash,
      'is_admin' => $this->isAdmin,
      'is_active' => $this->isActive,
      'token_version' => $this->tokenVersion,
      'last_login_at' => $this->lastLoginAt,
    ];
  }

  public function getId(): string
  {
    return $this->id;
  }

  public function getName(): string
  {
    return $this->name;
  }

  public function setName(string $name): void
  {
    $this->name = $name;
  }

  public function getEmail(): string
  {
    return $this->email;
  }

  public function setEmail(string $email): void
  {
    $this->email = $email;
  }

  /**
   * Changing the password logs the user out everywhere (old tokens carry the old version).
   */
  public function setPassword(string $password): void
  {
    $this->passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $this->tokenVersion++;
  }

  public function verifyPassword(string $password): bool
  {
    return password_verify($password, $this->passwordHash);
  }

  public function needsRehash(): bool
  {
    return password_needs_rehash($this->passwordHash, PASSWORD_DEFAULT);
  }

  public function isAdmin(): bool
  {
    return $this->isAdmin;
  }

  public function setAdmin(bool $isAdmin): void
  {
    $this->isAdmin = $isAdmin;
  }

  /**
   * What the roles add (AccessControl, loaded with the user)
   *
   * @param array<string, array<string, bool>> $effective
   */
  public function setAccess(array $effective, bool $takeOver): void
  {
    $this->effective = $effective;
    $this->takeOver = $takeOver;
  }

  /**
   * @param list<string> $roles
   */
  public function setRoles(array $roles): void
  {
    $this->roles = array_values(array_unique($roles));
  }

  /**
   * @return list<string>
   */
  public function getRoles(): array
  {
    return $this->roles;
  }

  /**
   * May take over a record another user is editing
   */
  public function canTakeOverLocks(): bool
  {
    return $this->isAdmin || $this->takeOver;
  }

  public function isActive(): bool
  {
    return $this->isActive;
  }

  public function setActive(bool $isActive): void
  {
    if (!$isActive && $this->isActive) {
      $this->tokenVersion++;
    }
    $this->isActive = $isActive;
  }

  public function getTokenVersion(): int
  {
    return $this->tokenVersion;
  }

  public function markLoggedIn(): void
  {
    $this->lastLoginAt = date('Y-m-d H:i:s');
  }

  /**
   * @param array<string, array<string, bool>> $permissions
   */
  public function setPermissions(array $permissions): void
  {
    $this->permissions = $permissions;
  }

  /**
   * @return array<string, array<string, bool>>
   */
  public function getPermissions(): array
  {
    return $this->permissions;
  }

  /**
   * @param list<string> $projectIds
   */
  public function setProjectIds(array $projectIds): void
  {
    $this->projectIds = array_values(array_unique($projectIds));
  }

  /**
   * @return list<string>
   */
  public function getProjectIds(): array
  {
    return $this->projectIds;
  }

  public function inProject(string $projectId): bool
  {
    return $this->isAdmin || in_array($projectId, $this->projectIds, true);
  }

  public function can(EntityPermission $permission, string $entityId): bool
  {
    return $this->isAdmin || (($this->effective ?? $this->permissions)[$entityId][$permission->value] ?? false);
  }

  /**
   * @return array<string, bool>
   */
  public function permissionsFor(string $entityId): array
  {
    return $this->isAdmin ? EntityPermission::all(true) : (($this->effective ?? $this->permissions)[$entityId] ?? []) + EntityPermission::all(false);
  }

  /**
   * Never exposes the password hash or token version.
   */
  public function toArray(): array
  {
    return [
      'id' => $this->id,
      'name' => $this->name,
      'email' => $this->email,
      'is_admin' => $this->isAdmin,
      'is_active' => $this->isActive,
      // Its own permissions; effective_permissions: with those of the roles
      'permissions' => (object)$this->permissions,
      'roles' => $this->roles,
      'effective_permissions' => (object)($this->effective ?? $this->permissions),
      'take_over' => $this->canTakeOverLocks(),
      'projects' => $this->projectIds,
      'last_login_at' => $this->lastLoginAt,
      'created_at' => $this->createdAt,
    ];
  }
}
