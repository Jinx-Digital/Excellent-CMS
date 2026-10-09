<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Access\AccessControl;
use App\Domain\Entity\OAuthClient;
use Yiisoft\Db\Connection\ConnectionInterface;

final class OAuthClientRepository
{
  public function __construct(
    private ConnectionInterface $db,
    private AccessControl $access,
  ) {
  }

  /**
   * @return list<OAuthClient>
   */
  public function all(?string $projectId = null): array
  {
    $query = $this->db->createQuery()->from('oauth_client')->orderBy(['name' => SORT_ASC]);
    if (null !== $projectId) {
      $query->where(['project_id' => $projectId]);
    }
    return $this->hydrate($query->all());
  }

  /**
   * @param list<string> $ids
   * @return array<string, string> id => name
   */
  public function names(array $ids): array
  {
    return [] === $ids ? [] : array_map('strval', array_column($this->db->createQuery()->select(['id', 'name'])->from('oauth_client')->where(['id' => $ids])->all(), 'name', 'id'));
  }

  public function get(string $id): ?OAuthClient
  {
    return $this->hydrate($this->db->createQuery()->from('oauth_client')->where(['id' => $id])->all())[0] ?? null;
  }

  public function findByClientId(string $clientId): ?OAuthClient
  {
    return $this->hydrate($this->db->createQuery()->from('oauth_client')->where(['client_id' => $clientId])->all())[0] ?? null;
  }

  public function save(OAuthClient $client): void
  {
    $this->db->transaction(function () use ($client): void {
      $row = $client->toRow();
      $now = date('Y-m-d H:i:s');
      if ($this->db->createQuery()->from('oauth_client')->where(['id' => $client->id])->exists()) {
        unset($row['id']);
        $this->db->createCommand()->update('oauth_client', $row + ['updated_at' => $now], ['id' => $client->id])->execute();
      } else {
        $this->db->createCommand()->insert('oauth_client', $row + ['created_at' => $now, 'updated_at' => $now])->execute();
      }
      // Permissions and roles: yiisoft/rbac (see AccessControl)
      $subject = AccessControl::client($client->id);
      $permissions = [];
      foreach (array_values(array_unique($client->entityIds)) as $entityId) {
        $permissions[$entityId] = ['read' => true] + array_map('boolval', $client->writes[$entityId] ?? []);
      }
      $this->access->setDirectEntityPermissions($subject, $permissions);
      $this->access->setDirect($subject, AccessControl::MEDIA_UPLOAD, $client->mediaUpload);
      $this->access->setDirect($subject, AccessControl::MEDIA_DELETE, $client->mediaDelete);
      $this->access->setRoles($subject, $client->roles);
    });
  }

  public function delete(string $id): bool
  {
    $this->access->forget(AccessControl::client($id));
    return $this->db->createCommand()->delete('oauth_client', ['id' => $id])->execute() > 0;
  }

  public function touch(string $id): void
  {
    $this->db->createCommand()->update('oauth_client', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => $id])->execute();
  }

  /**
   * @param list<string> $scopes
   */
  public function insertToken(string $tokenHash, string $clientId, array $scopes, string $expiresAt): void
  {
    $this->db->createCommand()->insert('oauth_access_token', [
      'token_hash' => $tokenHash,
      'client_id' => $clientId,
      'scopes' => json_encode(array_values($scopes)),
      'expires_at' => $expiresAt,
      'created_at' => date('Y-m-d H:i:s'),
    ])->execute();
  }

  /**
   * @return array{client_id: string, scopes: list<string>}|null valid (not expired) token
   */
  public function findToken(string $tokenHash): ?array
  {
    $row = $this->db->createQuery()->from('oauth_access_token')
      ->where(['token_hash' => $tokenHash])
      ->andWhere(['>', 'expires_at', date('Y-m-d H:i:s')])
      ->one();
    if (!is_array($row)) {
      return null;
    }
    $scopes = json_decode((string)$row['scopes'], true);
    return ['client_id' => (string)$row['client_id'], 'scopes' => is_array($scopes) ? array_values(array_map('strval', $scopes)) : []];
  }

  public function revokeTokens(string $clientId): void
  {
    $this->db->createCommand()->delete('oauth_access_token', ['client_id' => $clientId])->execute();
  }

  public function deleteExpiredTokens(): int
  {
    return $this->db->createCommand()->delete('oauth_access_token', ['<', 'expires_at', date('Y-m-d H:i:s')])->execute();
  }

  /**
   * @return list<OAuthClient>
   */
  private function hydrate(array $rows): array
  {
    if ([] === $rows) {
      return [];
    }
    $access = $this->access;
    $access->preload(array_map(static fn(array $row): string => AccessControl::client((string)$row['id']), $rows));
    return array_map(static function (array $row) use ($access): OAuthClient {
      $subject = AccessControl::client((string)$row['id']);
      $own = $access->entityPermissions($subject, true);
      $writes = array_map(static fn(array $p): array => ['create' => $p['create'] ?? false, 'update' => $p['update'] ?? false, 'delete' => $p['delete'] ?? false, 'update_own' => $p['update_own'] ?? false, 'delete_own' => $p['delete_own'] ?? false], $own);
      $client = OAuthClient::fromRow($row, array_values(array_map('strval', array_keys(array_filter($own, static fn(array $p): bool => $p['read'] ?? false)))), $writes);
      $assigned = $access->roles($subject);
      $client->roles = $assigned;
      $client->mediaUpload = in_array(AccessControl::MEDIA_UPLOAD, $access->directItems($subject), true);
      $client->mediaDelete = in_array(AccessControl::MEDIA_DELETE, $access->directItems($subject), true);
      $client->effective = $access->entityPermissions($subject);
      $client->effectiveMedia = ['upload' => $access->has($subject, AccessControl::MEDIA_UPLOAD), 'delete' => $access->has($subject, AccessControl::MEDIA_DELETE)];
      return $client;
    }, array_values($rows));
  }
}
