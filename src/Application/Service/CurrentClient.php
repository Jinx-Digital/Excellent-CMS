<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\OAuthClient;
use App\Domain\Schema\Access;
use App\Domain\Schema\EntityDefinition;

/**
 * Who reads the content API in this request: nobody (public access) or an OAuth client with the
 * entities its token was issued for. Filled in by ContentAuthMiddleware.
 */
final class CurrentClient
{
  private ?OAuthClient $client = null;
  /** @var list<string> entity ids the token may read */
  private array $entityIds = [];
  private ?string $ip = null;

  /**
   * @param list<string> $entityIds
   */
  public function set(OAuthClient $client, array $entityIds): void
  {
    $this->client = $client;
    $this->entityIds = $entityIds;
  }

  public function setIp(?string $ip): void
  {
    $this->ip = $ip;
  }

  public function getClient(): ?OAuthClient
  {
    return $this->client;
  }

  public function isAuthenticated(): bool
  {
    return null !== $this->client;
  }

  public function canRead(EntityDefinition $entity): bool
  {
    return Access::Public === $entity->access || in_array($entity->id, $this->entityIds, true);
  }

  /**
   * Writing always needs a token whose client may do it - public entities too.
   *
   * @param 'create'|'update'|'delete'|'update_own'|'delete_own' $permission
   */
  public function can(string $permission, EntityDefinition $entity): bool
  {
    return null !== $this->client && in_array($entity->id, $this->entityIds, true) && $this->client->may($permission, $entity->id);
  }

  /**
   * Media of the project (upload, delete) - always needs a token whose client may do it.
   *
   * @param 'upload'|'delete' $permission
   */
  public function canMedia(string $permission): bool
  {
    return null !== $this->client && $this->client->mayMedia($permission);
  }

  /**
   * Key of the rate limiter: the client, otherwise the IP address.
   */
  public function rateLimitKey(): string
  {
    return null !== $this->client ? 'api:client:'.$this->client->id : 'api:ip:'.($this->ip ?? '-');
  }
}
