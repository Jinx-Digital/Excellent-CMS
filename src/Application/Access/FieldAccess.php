<?php

declare(strict_types=1);

namespace App\Application\Access;

use App\Application\Service\CurrentActor;
use App\Application\Service\CurrentClient;
use App\Application\Service\CurrentUser;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;

/**
 * Fields limited to roles in the schema (FieldDefinition::readRoles / writeRoles):
 *
 *   administrators       everything
 *   users, API clients   with one of the roles (directly or inherited)
 *   content API without token: never - limited fields are not public
 *   the CMS itself       everything: event steps, schedules, and what unrestricted() runs (event data)
 *
 * Fields that may not be read are left out of records (RecordPresenter), searches, filters and
 * sort orders (RecordQuery) and revisions; changing fields that may not be changed is refused.
 */
final class FieldAccess
{
  private int $unrestricted = 0;

  public function __construct(
    private CurrentUser $currentUser,
    private CurrentClient $currentClient,
    private CurrentActor $actor,
    private AccessControl $access,
  ) {
  }

  public function canRead(FieldDefinition $field): bool
  {
    return null === $field->readRoles || $this->allowed($field->readRoles);
  }

  public function canWrite(FieldDefinition $field): bool
  {
    return $this->canRead($field) && (null === $field->writeRoles || $this->allowed($field->writeRoles));
  }

  /**
   * Fields of the entity that may be read - all of them when none is limited.
   *
   * @return list<FieldDefinition>
   */
  public function readableFields(EntityDefinition $entity): array
  {
    return array_values(array_filter($entity->fields, $this->canRead(...)));
  }

  /**
   * Runs $callback without limits (e.g. the data an event collects about a change).
   *
   * @template T
   * @param callable(): T $callback
   * @return T
   */
  public function unrestricted(callable $callback): mixed
  {
    $this->unrestricted++;
    try {
      return $callback();
    } finally {
      $this->unrestricted--;
    }
  }

  /**
   * @param list<string> $roles
   */
  private function allowed(array $roles): bool
  {
    if ($this->unrestricted > 0 || $this->actor->isOverridden()) {
      return true;
    }
    if (null !== $this->currentUser->getId()) {
      if ($this->currentUser->isAdmin()) {
        return true;
      }
      $subject = AccessControl::user((string)$this->currentUser->getId());
    } elseif (null !== ($client = $this->currentClient->getClient())) {
      $subject = AccessControl::client($client->id);
    } else {
      return false;
    }
    return [] !== array_intersect($roles, $this->access->roles($subject, false));
  }
}
