<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\OAuthClient;
use App\Repository\EntityRepository;
use App\Repository\OAuthClientRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;

/**
 * OAuth 2.0 client credentials (RFC 6749 section 4.4) for the content API:
 *
 *   POST /api/v1/oauth/token
 *   grant_type=client_credentials&client_id=…&client_secret=…[&scope=products countries]
 *   (or the credentials as HTTP Basic auth)
 *
 * returns {"access_token": "…", "token_type": "Bearer", "expires_in": 3600, "scope": "products countries"}.
 * Scopes are entity slugs; without a scope the token covers all entities the client may read.
 * Tokens are random strings, only their SHA-256 is stored.
 */
final class OAuthService
{
  public function __construct(
    private OAuthClientRepository $clients,
    private EntityRepository $entityRepository,
    private CurrentClient $currentClient,
    private CurrentProject $currentProject,
    private int $tokenTtl = 3600,
    private ?\App\Application\Access\AccessControl $access = null,
  ) {
  }

  /**
   * @return array{client: OAuthClient, secret: string}
   */
  public function createClient(array $data): array
  {
    $secret = self::randomToken();
    $client = new OAuthClient(
      id: Id::new(),
      name: '',
      clientId: 'cms_'.bin2hex(random_bytes(12)),
      secretHash: password_hash($secret, PASSWORD_DEFAULT),
      projectId: $this->currentProject->get()->id,
    );
    $this->apply($client, $data, true);
    $this->clients->save($client);
    return ['client' => $this->get($client->id), 'secret' => $secret];
  }

  public function updateClient(string $id, array $data): OAuthClient
  {
    $client = $this->get($id);
    $this->apply($client, $data, false);
    $this->clients->save($client);
    if (!$client->isActive) {
      $this->clients->revokeTokens($client->id);
    }
    return $this->get($id);
  }

  /**
   * New secret; all tokens issued so far stop working.
   */
  public function regenerateSecret(string $id): string
  {
    $client = $this->get($id);
    $secret = self::randomToken();
    $client->secretHash = password_hash($secret, PASSWORD_DEFAULT);
    $this->clients->save($client);
    $this->clients->revokeTokens($client->id);
    return $secret;
  }

  public function deleteClient(string $id): void
  {
    $this->clients->delete($this->get($id)->id);
  }

  public function get(string $id): OAuthClient
  {
    $client = $this->clients->get($id);
    // Admin app: only the clients of the current project
    if (null === $client || ($this->currentProject->isScoped() && $client->projectId !== $this->currentProject->id())) {
      throw UserFacingException::notFound(I18n::t('This API client does not exist.'));
    }
    return $client;
  }

  /**
   * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
   */
  public function issueToken(string $grantType, string $clientId, string $secret, ?string $scope): array
  {
    if ('client_credentials' !== $grantType) {
      throw new OAuthException('unsupported_grant_type', 'Only grant_type=client_credentials is supported.');
    }
    if ('' === $clientId || '' === $secret) {
      throw new OAuthException('invalid_request', 'client_id and client_secret are required.');
    }
    $client = $this->clients->findByClientId($clientId);
    if (null === $client || !$client->verifySecret($secret)) {
      throw new OAuthException('invalid_client', 'Client authentication failed.', 401);
    }
    if (!$client->isActive) {
      throw new OAuthException('invalid_client', 'The client is disabled.', 401);
    }
    // /api/v1/<project>/oauth/token: the client must belong to that project
    if ($this->currentProject->isScoped() && $client->projectId !== $this->currentProject->id()) {
      throw new OAuthException('invalid_client', 'The client belongs to another project.', 401);
    }

    $allowed = [];
    foreach ($client->readableEntityIds() as $entityId) {
      $entity = $this->entityRepository->findById($entityId);
      if (null !== $entity) {
        $allowed[$entity->slug] = $entity->id;
      }
    }

    $requested = array_values(array_filter(preg_split('/[\s,]+/', trim((string)$scope)) ?: []));
    if ([] === $requested) {
      $granted = $allowed;
    } else {
      $granted = [];
      foreach ($requested as $slug) {
        if (!isset($allowed[$slug])) {
          throw new OAuthException('invalid_scope', sprintf('The client may not read "%s".', $slug));
        }
        $granted[$slug] = $allowed[$slug];
      }
    }

    $token = self::randomToken();
    $this->clients->insertToken(hash('sha256', $token), $client->id, array_values($granted), date('Y-m-d H:i:s', time() + $this->tokenTtl));
    $this->clients->touch($client->id);

    return [
      'access_token' => $token,
      'token_type' => 'Bearer',
      'expires_in' => $this->tokenTtl,
      'scope' => implode(' ', array_keys($granted)),
    ];
  }

  /**
   * Checks a bearer token of the content API and remembers the client for this request.
   * The client's current permissions count - removing an entity from a client takes effect at once.
   */
  public function authenticate(string $token): bool
  {
    $stored = $this->clients->findToken(hash('sha256', $token));
    if (null === $stored) {
      return false;
    }
    $client = $this->clients->get($stored['client_id']);
    // A token only works in the content API of its client's project
    if (null === $client || !$client->isActive || $client->projectId !== $this->currentProject->id()) {
      return false;
    }
    $this->currentClient->set($client, array_values(array_intersect($stored['scopes'], $client->readableEntityIds())));
    return true;
  }

  public function deleteExpiredTokens(): int
  {
    return $this->clients->deleteExpiredTokens();
  }

  /**
   * @return list<OAuthClient>
   */
  public function all(): array
  {
    return $this->clients->all($this->currentProject->isScoped() ? (string)$this->currentProject->id() : null);
  }

  public function present(OAuthClient $client): array
  {
    $entities = [];
    foreach ($client->entityIds as $entityId) {
      $entity = $this->entityRepository->findById($entityId);
      if (null !== $entity) {
        $entities[] = ['id' => $entity->id, 'slug' => $entity->slug, 'name' => $entity->name] + ($client->writes[$entity->id] ?? []) + ['create' => false, 'update' => false, 'delete' => false, 'update_own' => false, 'delete_own' => false];
      }
    }
    return [
      'id' => $client->id,
      'name' => $client->name,
      'client_id' => $client->clientId,
      'is_active' => $client->isActive,
      'rate_limit' => $client->rateLimit,
      'entities' => $entities,
      'media' => ['upload' => $client->mediaUpload, 'delete' => $client->mediaDelete],
      // Roles; effective: what it may do with them (entity id => permissions, media)
      'roles' => $client->roles,
      'effective' => ['entities' => (object)($client->effective ?? []), 'media' => $client->effectiveMedia ?? ['upload' => $client->mediaUpload, 'delete' => $client->mediaDelete]],
      'last_used_at' => $client->lastUsedAt,
      'created_at' => $client->createdAt,
    ];
  }

  private function apply(OAuthClient $client, array $data, bool $isNew): void
  {
    $errors = [];
    if ($isNew || array_key_exists('name', $data)) {
      $client->name = trim((string)($data['name'] ?? ''));
      if ('' === $client->name || mb_strlen($client->name) > 100) {
        $errors['name'][] = I18n::t('Please enter a name (e.g. "Website").');
      }
    }
    if (array_key_exists('is_active', $data)) {
      $client->isActive = (bool)filter_var($data['is_active'], FILTER_VALIDATE_BOOL);
    }
    if (array_key_exists('rate_limit', $data)) {
      $limit = $data['rate_limit'];
      if (null === $limit || '' === $limit) {
        $client->rateLimit = null;
      } elseif (false === filter_var($limit, FILTER_VALIDATE_INT) || (int)$limit < 0) {
        $errors['rate_limit'][] = I18n::t('Please enter a number from 0 (empty = default, 0 = unlimited).');
      } else {
        $client->rateLimit = (int)$limit;
      }
    }
    if (array_key_exists('entities', $data)) {
      // Entities as slugs/ids (read only), or {entity, create, update, delete}
      $ids = [];
      $writes = [];
      foreach ((array)$data['entities'] as $value) {
        $key = is_array($value) ? (string)($value['entity'] ?? $value['id'] ?? $value['slug'] ?? '') : (string)$value;
        $entity = $this->entityRepository->findById($key) ?? $this->entityRepository->findBySlug($key);
        if (null === $entity) {
          $errors['entities'][] = I18n::t('The entity "{entity}" does not exist.', ['entity' => $key]);
          continue;
        }
        // Public entities may be assigned too: the client keeps access if one becomes protected
        $ids[] = $entity->id;
        $flag = static fn(string $name): bool => is_array($value) && (bool)filter_var($value[$name] ?? false, FILTER_VALIDATE_BOOL);
        $writes[$entity->id] = ['create' => $flag('create'), 'update' => $flag('update'), 'delete' => $flag('delete'), 'update_own' => $flag('update_own'), 'delete_own' => $flag('delete_own')];
      }
      $client->entityIds = array_values(array_unique($ids));
      $client->writes = $writes;
    }
    if (array_key_exists('roles', $data)) {
      $client->roles = array_values(array_unique(array_map('strval', (array)$data['roles'])));
      foreach ($client->roles as $role) {
        if (null === $this->access || !$this->access->roleExists($role)) {
          $errors['roles'][] = I18n::t('This role does not exist: {role}', ['role' => $role]);
        }
      }
    }
    if (array_key_exists('media', $data)) {
      // Media of the project: {upload: bool, delete: bool}
      $media = (array)$data['media'];
      $client->mediaUpload = (bool)filter_var($media['upload'] ?? false, FILTER_VALIDATE_BOOL);
      $client->mediaDelete = (bool)filter_var($media['delete'] ?? false, FILTER_VALIDATE_BOOL);
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
  }

  private static function randomToken(): string
  {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }
}
