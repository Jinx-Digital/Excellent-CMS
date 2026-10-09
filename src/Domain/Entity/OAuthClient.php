<?php

declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * A reader of the content API (website, app, other server) using the OAuth 2.0 client
 * credentials grant. It may read the public entities and the protected ones it was given.
 */
final class OAuthClient
{
  /**
   * @param list<string> $entityIds Protected entities this client may read
   */
  public function __construct(
    public readonly string $id,
    public string $name,
    public readonly string $clientId,
    public string $secretHash,
    public bool $isActive = true,
    public ?int $rateLimit = null,
    public array $entityIds = [],
    public ?string $lastUsedAt = null,
    public ?string $createdAt = null,
    public string $projectId = '',
    /** @var array<string, array{create: bool, update: bool, delete: bool}> entity id => write permissions */
    public array $writes = [],
    /** Upload media files into the project (POST /media) */
    public bool $mediaUpload = false,
    /** Delete unused media files of the project (DELETE /media/{id}) */
    public bool $mediaDelete = false,
    /** @var list<string> slugs of its roles */
    public array $roles = [],
    /** @var array<string, array<string, bool>>|null entity id => permission, with those of the roles (null: only its own) */
    public ?array $effective = null,
    /** @var array{upload: bool, delete: bool}|null media permissions with those of the roles */
    public ?array $effectiveMedia = null,
  ) {
  }

  /**
   * @param list<string> $entityIds
   */
  public static function fromRow(array $row, array $entityIds, array $writes = []): self
  {
    return new self(
      id: (string)$row['id'],
      name: (string)$row['name'],
      clientId: (string)$row['client_id'],
      secretHash: (string)$row['secret_hash'],
      isActive: (bool)$row['is_active'],
      rateLimit: null !== $row['rate_limit'] ? (int)$row['rate_limit'] : null,
      entityIds: $entityIds,
      lastUsedAt: null !== $row['last_used_at'] ? (string)$row['last_used_at'] : null,
      createdAt: null !== $row['created_at'] ? (string)$row['created_at'] : null,
      projectId: (string)($row['project_id'] ?? ''),
      writes: $writes,
    );
  }

  public function toRow(): array
  {
    return [
      'id' => $this->id,
      'name' => $this->name,
      'client_id' => $this->clientId,
      'secret_hash' => $this->secretHash,
      'is_active' => $this->isActive,
      'rate_limit' => $this->rateLimit,
      'project_id' => $this->projectId,
    ];
  }

  public function verifySecret(string $secret): bool
  {
    return password_verify($secret, $this->secretHash);
  }

  public function mayRead(string $entityId): bool
  {
    return null !== $this->effective ? ($this->effective[$entityId]['read'] ?? false) : in_array($entityId, $this->entityIds, true);
  }

  /**
   * Entities it may read - its own and those of its roles.
   *
   * @return list<string>
   */
  public function readableEntityIds(): array
  {
    if (null === $this->effective) {
      return $this->entityIds;
    }
    return array_values(array_map('strval', array_keys(array_filter($this->effective, static fn(array $permissions): bool => $permissions['read'] ?? false))));
  }

  /**
   * @param 'create'|'update'|'delete' $permission
   */
  public function may(string $permission, string $entityId): bool
  {
    return $this->mayRead($entityId) && (null !== $this->effective ? ($this->effective[$entityId][$permission] ?? false) : ($this->writes[$entityId][$permission] ?? false));
  }

  /**
   * @param 'upload'|'delete' $permission
   */
  public function mayMedia(string $permission): bool
  {
    $media = $this->effectiveMedia ?? ['upload' => $this->mediaUpload, 'delete' => $this->mediaDelete];
    return $media[$permission] ?? false;
  }
}
