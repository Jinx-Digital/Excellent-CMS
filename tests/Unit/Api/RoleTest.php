<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Roles (yiisoft/rbac) for users and API clients, and fields limited to roles in the schema.
 */
class RoleTest extends ApiTestCase
{
  public function testRolesGivePermissionsToUsersAndClients(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('products'), 'name' => 'Produkte', 'access' => 'oauth', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $record = $this->createRecord($entity['slug'], ['title' => 'Stuhl'], $admin);
    [$reader, $editor] = [$this->uniqueSlug('reader'), $this->uniqueSlug('editor')];

    // A role with permissions, and one that contains it
    $role = $this->api('POST', '/admin/roles', ['slug' => $reader, 'name' => 'Leser Produkte', 'permissions' => [$entity['id'] => ['read' => true]]], $admin);
    $this->assertSame(200, $role['status'], json_encode($role['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(200, $this->api('POST', '/admin/roles', ['slug' => $editor, 'name' => 'Redaktion Produkte', 'roles' => [$reader], 'permissions' => [$entity['id'] => ['update' => true]]], $admin)['status']);
    $this->assertSame(422, $this->api('PUT', "/admin/roles/{$reader}", ['roles' => [$editor]], $admin)['status'], 'no circles');
    $this->assertSame(422, $this->api('POST', '/admin/roles', ['slug' => $reader, 'name' => 'Doppelt'], $admin)['status']);

    // A user with the outer role may read (inherited) and change records
    $email = $this->uniqueSlug('user').'@example.com';
    $user = $this->api('POST', '/admin/users', ['name' => 'Rollenträgerin', 'email' => $email, 'password' => 'passwort123', 'roles' => [$editor]], $admin)['body']['data'];
    $token = $this->login($email, 'passwort123');
    $this->assertSame(200, $this->api('PUT', "/entities/{$entity['slug']}/records/{$record['id']}", ['title' => 'Sessel'], $token)['status']);
    $this->assertSame(403, $this->api('DELETE', "/entities/{$entity['slug']}/records/{$record['id']}", token: $token)['status']);
    $effective = array_filter((array)$user['effective_permissions'][$entity['id']]);
    ksort($effective);
    $this->assertSame(['read' => true, 'update' => true], $effective);
    $this->assertSame(1, array_column($this->api('GET', '/admin/roles', token: $admin)['body']['data'], 'users', 'slug')[$editor]);

    // An API client with the role reads the protected entity
    $client = $this->api('POST', '/admin/clients', ['name' => 'Shop', 'roles' => [$reader]], $admin)['body']['data'];
    $access = $this->token($client);
    $this->assertSame(200, $access['status'], json_encode($access['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame('Sessel', $this->api('GET', "/main/content/{$entity['slug']}/{$record['id']}", token: $access['body']['access_token'])['body']['data']['title']);

    // Deleting the role takes the permissions away
    $this->api('DELETE', "/admin/roles/{$editor}", token: $admin);
    $this->assertSame(403, $this->api('PUT', "/entities/{$entity['slug']}/records/{$record['id']}", ['title' => 'X'], $token)['status']);
  }

  public function testFieldsLimitedToRoles(): void
  {
    $admin = $this->login();
    $pricing = $this->uniqueSlug('pricing');
    $this->api('POST', '/admin/roles', ['slug' => $pricing, 'name' => 'Preise'], $admin);
    $slug = $this->uniqueSlug('items');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Artikel', 'access' => 'public', 'revisions' => true, 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      // Only the role "pricing" sees and changes it
      ['name' => 'cost', 'type' => 'string', 'read_roles' => [$pricing], 'write_roles' => [$pricing]],
      // Everyone sees it, only "pricing" changes it
      ['name' => 'price', 'type' => 'integer', 'write_roles' => [$pricing]],
    ]], $admin);
    $this->assertSame([$pricing], array_column($entity['fields'], 'read_roles', 'name')['cost']);
    $this->assertSame(422, $this->api('POST', '/admin/entities', ['slug' => $this->uniqueSlug('x'), 'name' => 'X', 'fields' => [['name' => 'a', 'type' => 'string', 'read_roles' => ['gibtsnicht']]]], $admin)['status']);
    $record = $this->createRecord($slug, ['title' => 'Tisch', 'cost' => 'geheim-einkauf', 'price' => 100], $admin);
    $url = "/entities/{$slug}/records/{$record['id']}";
    $permissions = [$entity['id'] => ['read' => true, 'create' => true, 'update' => true]];

    // Without the role: the field is not there - not in records, filters, sort orders or the search
    $plain = $this->user([], $permissions, $admin);
    $seen = $this->api('GET', $url, token: $plain)['body']['data'];
    $this->assertArrayNotHasKey('cost', $seen);
    $this->assertSame(100, $seen['price']);
    $this->assertSame(422, $this->api('GET', "/entities/{$slug}/records?filter[cost]=geheim-einkauf", token: $plain)['status']);
    $this->assertSame(422, $this->api('GET', "/entities/{$slug}/records?sort=cost", token: $plain)['status']);
    $this->assertSame([], $this->api('GET', "/entities/{$slug}/records?s=geheim", token: $plain)['body']['data']);
    $fields = array_column($this->api('GET', "/entities/{$slug}", token: $plain)['body']['data']['fields'], null, 'name');
    $this->assertSame([false, false, true, false], [$fields['cost']['can_read'], $fields['cost']['can_write'], $fields['price']['can_read'], $fields['price']['can_write']]);
    // Changing it is refused, sending it unchanged is fine (whole records)
    $this->assertSame(422, $this->api('PUT', $url, ['price' => 50], $plain)['status']);
    $this->assertSame(422, $this->api('PUT', $url, ['cost' => 'billig'], $plain)['status']);
    $this->assertSame(200, $this->api('PUT', $url, ['title' => 'Tisch groß', 'price' => 100], $plain)['status']);
    $this->assertSame(422, $this->api('POST', "/entities/{$slug}/records", ['title' => 'Neu', 'price' => 5], $plain)['status']);
    // Revisions do not name or show it
    $history = $this->api('GET', "$url/revisions", token: $plain)['body']['data'];
    $this->assertNotContains('cost', array_merge(...array_column($history, 'changed')));
    $this->assertArrayNotHasKey('cost', $this->api('GET', "$url/revisions/{$history[1]['id']}", token: $plain)['body']['data']['record']);

    // With the role: everything
    $priced = $this->user([$pricing], $permissions, $admin);
    $this->assertSame('geheim-einkauf', $this->api('GET', $url, token: $priced)['body']['data']['cost']);
    $this->assertSame(200, $this->api('PUT', $url, ['price' => 80, 'cost' => 'neu'], $priced)['status']);

    // Content API: without a token limited fields are not public, a client with the role sees them
    $public = $this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data'];
    $this->assertArrayNotHasKey('cost', $public);
    $this->assertSame(80, $public['price']);
    $this->assertSame(422, $this->api('GET', "/main/content/{$slug}?filter[cost]=neu")['status']);
    $schema = array_column($this->api('GET', '/main/content')['body']['data'], 'fields', 'slug')[$slug];
    $this->assertSame(['title', 'price'], array_column($schema, 'name'));
    $this->assertSame(422, $this->api('GET', "/main/content/{$slug}?fields=cost")['status']);
    $client = $this->api('POST', '/admin/clients', ['name' => 'Kalkulation', 'roles' => [$pricing]], $admin)['body']['data'];
    $token = $this->token($client)['body']['access_token'];
    $this->assertSame('neu', $this->api('GET', "/main/content/{$slug}/{$record['id']}", token: $token)['body']['data']['cost']);

    // Administrators may do everything
    $this->assertSame('neu', $this->api('GET', $url, token: $admin)['body']['data']['cost']);
  }

  public function testOnlyOwnRecords(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('posts'), 'name' => 'Beiträge', 'access' => 'public', 'drafts' => true, 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $slug = $entity['slug'];
    $writer = $this->uniqueSlug('writer');
    $this->api('POST', '/admin/roles', ['slug' => $writer, 'name' => 'Schreibende', 'permissions' => [$entity['id'] => ['read' => true, 'create' => true, 'update_own' => true, 'delete_own' => true]]], $admin);
    $token = $this->user([$writer], [], $admin);

    $foreign = $this->createRecord($slug, ['title' => 'Vom Admin'], $admin);
    $own = $this->api('POST', "/entities/{$slug}/records", ['title' => 'Meiner'], $token)['body']['data'];

    // Own records: change, save for later, schedule, delete
    $this->assertSame(200, $this->api('PUT', "/entities/{$slug}/records/{$own['id']}", ['title' => 'Meiner, geändert'], $token)['status']);
    $this->assertSame(200, $this->api('PUT', "/entities/{$slug}/records/{$own['id']}/working-copy", ['title' => 'Später'], $token)['status']);
    // Records of others: refused, also in bulk
    $refused = $this->api('PUT', "/entities/{$slug}/records/{$foreign['id']}", ['title' => 'Nicht meiner'], $token);
    $this->assertSame([403, 'forbidden'], [$refused['status'], $refused['body']['error_code']]);
    $this->assertSame(403, $this->api('PUT', "/entities/{$slug}/records/{$foreign['id']}/working-copy", ['title' => 'X'], $token)['status']);
    $this->assertSame(403, $this->api('DELETE', "/entities/{$slug}/records/{$foreign['id']}", token: $token)['status']);
    $this->assertSame(403, $this->api('POST', "/entities/{$slug}/records/delete", ['ids' => [$own['id'], $foreign['id']]], $token)['status']);
    $this->assertSame(200, $this->api('POST', "/entities/{$slug}/records/delete", ['ids' => [$own['id']]], $token)['status']);

    // The permission carries the RBAC rule
    $rule = $this->container()->get(\Yiisoft\Db\Connection\ConnectionInterface::class)->createQuery()->from('yii_rbac_item')->select('rule_name')->where(['name' => "entity.{$entity['id']}.update_own"])->scalar();
    $this->assertSame(\App\Application\Access\OwnRecordRule::class, $rule);

    // API clients: their own records, not those of others
    $client = $this->api('POST', '/admin/clients', ['name' => 'Blog-App', 'entities' => [['entity' => $slug, 'create' => true, 'update_own' => true, 'delete_own' => true]]], $admin)['body']['data'];
    $access = $this->token($client)['body']['access_token'];
    $created = $this->api('POST', "/main/content/{$slug}", ['title' => 'Aus der App'], $access);
    $this->assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(200, $this->api('PUT', "/main/content/{$slug}/{$created['body']['data']['id']}", ['title' => 'Aus der App, geändert'], $access)['status']);
    $this->assertSame(403, $this->api('PUT', "/main/content/{$slug}/{$foreign['id']}", ['title' => 'X'], $access)['status']);
    $this->assertSame(403, $this->api('DELETE', "/main/content/{$slug}/{$foreign['id']}", token: $access)['status']);
    $this->assertSame(200, $this->api('DELETE', "/main/content/{$slug}/{$created['body']['data']['id']}", token: $access)['status']);
  }

  private function token(array $client): array
  {
    return $this->api('POST', '/main/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']], headers: ['Content-Type' => 'application/x-www-form-urlencoded']);
  }

  /**
   * @param list<string> $roles
   */
  private function user(array $roles, array $permissions, string $admin): string
  {
    $email = $this->uniqueSlug('field').'@example.com';
    $user = $this->api('POST', '/admin/users', ['name' => 'Feld', 'email' => $email, 'password' => 'passwort123', 'roles' => $roles, 'permissions' => $permissions], $admin);
    $this->assertSame(200, $user['status'], json_encode($user['body'], JSON_UNESCAPED_UNICODE));
    return $this->login($email, 'passwort123');
  }
}
