<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Application\Content\RecordLocks;
use App\Tests\Support\ApiTestCase;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Records being edited are locked: other users can only read them, admins and editors take them over.
 */
class RecordLockTest extends ApiTestCase
{
  public function testLocksKeepOthersOut(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('articles'), 'name' => 'Artikel', 'drafts' => true, 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $slug = $entity['slug'];
    $record = $this->createRecord($slug, ['title' => 'Hallo'], $admin);
    $url = "/entities/{$slug}/records/{$record['id']}";
    $permissions = [$entity['id'] => ['read' => true, 'update' => true, 'delete' => true]];
    $anna = $this->user('Anna', [], $permissions, $admin);
    $ben = $this->user('Ben', [], $permissions, $admin);
    // The role "editor" (Redakteur) may take over records
    $eva = $this->user('Eva', ['editor'], $permissions, $admin);
    $this->assertSame([['editor'], true], array_values(array_intersect_key($this->api('GET', '/auth/me', token: $eva)['body']['data']['user'], ['roles' => 1, 'take_over' => 1])));

    // Anna opens the record: locked for her, renewing keeps it
    $lock = $this->api('POST', "$url/lock", token: $anna)['body']['data'];
    $this->assertSame([true, 'Anna'], [$lock['mine'], $lock['user']['name']]);
    $this->assertTrue($this->api('POST', "$url/lock", token: $anna)['body']['data']['mine']);

    // Ben only gets her lock, and cannot save, delete or publish
    $this->assertSame([false, 'Anna'], [$this->api('POST', "$url/lock", token: $ben)['body']['data']['mine'], $this->api('GET', $url, token: $ben)['body']['data']['_lock']['user']['name']]);
    $refused = $this->api('PUT', $url, ['title' => 'Ben war hier'], $ben);
    $this->assertSame([423, 'record_locked'], [$refused['status'], $refused['body']['error_code']]);
    $this->assertSame(423, $this->api('DELETE', $url, token: $ben)['status']);
    $this->assertSame(423, $this->api('PUT', "$url/working-copy", ['title' => 'X'], $ben)['status']);
    $this->assertSame(423, $this->api('POST', "/entities/{$slug}/records/delete", ['ids' => [$record['id']]], $ben)['status']);
    $this->assertSame(200, $this->api('PUT', $url, ['title' => 'Anna'], $anna)['status']);
    // The list shows who is editing
    $this->assertSame('Anna', $this->api('GET', "/entities/{$slug}/records", token: $ben)['body']['data'][0]['_lock']['user']['name']);
    // Authors cannot take over, editors can - Anna can then only read
    $this->assertSame(403, $this->api('POST', "$url/lock/take-over", token: $ben)['status']);
    $this->assertTrue($this->api('POST', "$url/lock/take-over", token: $eva)['body']['data']['mine']);
    $this->assertFalse($this->api('POST', "$url/lock", token: $anna)['body']['data']['mine']);
    $this->assertSame(423, $this->api('PUT', $url, ['title' => 'Anna'], $anna)['status']);

    // Released when leaving: free for the next one
    $this->api('DELETE', "$url/lock", token: $eva);
    $this->assertNull($this->api('GET', $url, token: $ben)['body']['data']['_lock']);
    $this->assertTrue($this->api('POST', "$url/lock", token: $ben)['body']['data']['mine']);

    // A lock nobody renews runs out (page closed)
    $this->container()->get(ConnectionInterface::class)->createCommand()->update('record_lock', ['seen_at' => date('Y-m-d H:i:s', time() - RecordLocks::TTL - 1)], ['record_id' => $record['id']])->execute();
    $this->assertNull($this->api('GET', $url, token: $anna)['body']['data']['_lock']);
    $this->assertSame(200, $this->api('PUT', $url, ['title' => 'Wieder frei'], $anna)['status']);
    $this->assertTrue($this->api('POST', "$url/lock", token: $anna)['body']['data']['mine']);

    // Admins take over too; users can only release their own lock
    $this->api('DELETE', "$url/lock", token: $ben);
    $this->assertSame('Anna', $this->api('GET', $url, token: $ben)['body']['data']['_lock']['user']['name']);
    $this->assertTrue($this->api('POST', "$url/lock/take-over", token: $admin)['body']['data']['mine']);
    $this->api('DELETE', "$url/lock", token: $admin);
  }

  public function testRoles(): void
  {
    $admin = $this->login();
    $user = $this->api('POST', '/admin/users', ['name' => 'Rolle', 'email' => $this->uniqueSlug('role').'@example.com', 'password' => 'passwort123', 'roles' => ['editor']], $admin)['body']['data'];
    $this->assertSame([['editor'], false, true], [$user['roles'], $user['is_admin'], $user['take_over']]);
    $this->assertSame([[], false], array_values(array_intersect_key($this->api('PUT', "/admin/users/{$user['id']}", ['roles' => []], $admin)['body']['data'], ['roles' => 1, 'take_over' => 1])));
    $this->assertSame(422, $this->api('PUT', "/admin/users/{$user['id']}", ['roles' => ['chef']], $admin)['status']);
  }

  /**
   * @return string token of the new user
   */
  /**
   * @param list<string> $roles
   */
  private function user(string $name, array $roles, array $permissions, string $admin): string
  {
    $email = $this->uniqueSlug(strtolower($name)).'@example.com';
    $user = $this->api('POST', '/admin/users', ['name' => $name, 'email' => $email, 'password' => 'passwort123', 'roles' => $roles, 'permissions' => $permissions], $admin);
    $this->assertSame(200, $user['status'], json_encode($user['body'], JSON_UNESCAPED_UNICODE));
    return $this->login($email, 'passwort123');
  }
}
