<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Access\EntityPermission;
use App\Domain\Entity\User;
use App\Domain\Schema\EntityDefinition;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;

/**
 * The authenticated CMS user of the current request, filled in by AuthMiddleware.
 */
final class CurrentUser
{
  private ?User $user = null;
  private ?string $ip = null;

  public function set(User $user): void
  {
    $this->user = $user;
  }

  public function setIp(?string $ip): void
  {
    $this->ip = $ip;
  }

  public function getIp(): ?string
  {
    return $this->ip;
  }

  public function getUser(): User
  {
    return $this->user ?? throw new UserFacingException(I18n::t('Please sign in.'), 401, 'unauthorized');
  }

  public function getId(): ?string
  {
    return $this->user?->getId();
  }

  public function isAdmin(): bool
  {
    return $this->user?->isAdmin() ?? false;
  }

  public function can(EntityPermission $permission, EntityDefinition $entity): bool
  {
    return $this->user?->can($permission, $entity->id) ?? false;
  }

  public function assertCan(EntityPermission $permission, EntityDefinition $entity): void
  {
    if (!$this->can($permission, $entity)) {
      throw UserFacingException::forbidden(I18n::t('You need the permission "{permission}" for "{entity}".', ['permission' => $permission->label(), 'entity' => $entity->name]));
    }
  }

  /**
   * Update / delete one record: the permission, or its "only own records" variant for a record the
   * user created (OwnRecordRule).
   */
  public function canRecord(EntityPermission $permission, EntityDefinition $entity, array $row): bool
  {
    if ($this->can($permission, $entity)) {
      return true;
    }
    $own = $permission->own();
    return null !== $own && null !== $this->user && $this->can($own, $entity)
      && \App\Application\Access\OwnRecordRule::owns(\App\Application\Access\AccessControl::user($this->user->getId()), $row);
  }

  /**
   * Either permission of update / delete (all records or only own ones) - e.g. to open the form.
   */
  public function canSome(EntityPermission $permission, EntityDefinition $entity): bool
  {
    $own = $permission->own();
    return $this->can($permission, $entity) || (null !== $own && $this->can($own, $entity));
  }

  public function assertAdmin(): void
  {
    if (!$this->isAdmin()) {
      throw UserFacingException::forbidden(I18n::t('Only administrators may do this.'));
    }
  }
}
