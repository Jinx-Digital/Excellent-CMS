<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class ContentWriteTest extends ApiTestCase
{
  public function testClientsCreateUpdateAndDelete(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('orders');
    $this->createEntity(['slug' => $slug, 'name' => 'Bestellungen', 'access' => 'public', 'trash' => true, 'fields' => [
      ['name' => 'number', 'type' => 'string', 'unique' => true, 'required' => true],
      ['name' => 'amount', 'type' => 'decimal'],
    ]], $admin);
    $writer = $this->client($admin, [['entity' => $slug, 'create' => true, 'update' => true, 'delete' => true]]);
    $reader = $this->client($admin, [$slug]);

    // Create: the same checks as in the admin app
    $created = $this->api('POST', "/main/content/{$slug}", ['number' => 'A-1', 'amount' => '12,50'], $writer);
    $this->assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['A-1', 12.5], [$created['body']['data']['number'], $created['body']['data']['amount']]);
    $this->assertArrayNotHasKey('_i18n', $created['body']['data']);
    $id = $created['body']['data']['id'];
    $this->assertSame(422, $this->api('POST', "/main/content/{$slug}", ['number' => 'A-1'], $writer)['status']);
    $this->assertSame(422, $this->api('POST', "/main/content/{$slug}", ['amount' => 'viel'], $writer)['status']);

    // Update only changes what is sent, PATCH works too
    $updated = $this->api('PATCH', "/main/content/{$slug}/{$id}", ['amount' => 20], $writer)['body']['data'];
    $this->assertEquals(['A-1', 20], [$updated['number'], $updated['amount']]);

    // Permissions: reading clients and anonymous callers cannot write - public entities neither
    $this->assertSame(403, $this->api('POST', "/main/content/{$slug}", ['number' => 'B'], $reader)['status']);
    $this->assertSame(403, $this->api('DELETE', "/main/content/{$slug}/{$id}", token: $reader)['status']);
    $this->assertSame(401, $this->api('POST', "/main/content/{$slug}", ['number' => 'C'])['status']);

    // Delete: into the trash of the entity
    $this->assertSame(200, $this->api('DELETE', "/main/content/{$slug}/{$id}", token: $writer)['status']);
    $this->assertSame(404, $this->api('GET', "/main/content/{$slug}/{$id}")['status']);
    $this->assertSame(1, $this->api('GET', "/entities/{$slug}", token: $admin)['body']['data']['trash_count']);

    // The permissions show in the admin app and take effect at once
    $clients = array_column($this->api('GET', '/admin/clients', token: $admin)['body']['data'], null, 'name');
    $this->assertSame([true, true, true], [$clients['Schreiber']['entities'][0]['create'], $clients['Schreiber']['entities'][0]['update'], $clients['Schreiber']['entities'][0]['delete']]);
    $this->api('PUT', "/admin/clients/{$clients['Schreiber']['id']}", ['entities' => [['entity' => $slug, 'create' => false]]], $admin);
    $this->assertSame(403, $this->api('POST', "/main/content/{$slug}", ['number' => 'D'], $writer)['status']);
  }

  public function testWritingInALanguage(): void
  {
    $admin = $this->login();
    $project = 'w'.substr(md5(uniqid('', true)), 0, 8);
    $this->api('POST', '/admin/projects', ['name' => 'Schreiben', 'slug' => $project, 'table_prefix' => $project.'_', 'languages' => ['de', 'en']], $admin);
    $headers = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'news', 'name' => 'News', 'fields' => [
      ['name' => 'title', 'type' => 'string', 'translatable' => true],
      ['name' => 'slug', 'type' => 'slug', 'slug_source' => 'title', 'translatable' => true],
    ]], $admin, $headers);
    $client = $this->api('POST', '/admin/clients', ['name' => 'Redaktionssystem', 'entities' => [['entity' => 'news', 'create' => true, 'update' => true]]], $admin, $headers)['body']['data'];
    $token = $this->api('POST', "/{$project}/oauth/token", ['grant_type' => 'client_credentials', 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']], headers: ['Content-Type' => 'application/x-www-form-urlencoded'])['body']['access_token'];

    $de = $this->api('POST', "/{$project}/content/news", ['title' => 'Neuigkeiten'], $token)['body']['data'];
    // ?lang=en: the values are the English ones
    $en = $this->api('PUT', "/{$project}/content/news/{$de['id']}?lang=en", ['title' => 'News'], $token)['body']['data'];
    $this->assertSame(['News', 'news'], [$en['title'], $en['slug']]);
    $all = $this->api('GET', "/{$project}/content/news/{$de['id']}?lang=all", token: $token)['body']['data'];
    $this->assertSame(['de' => 'Neuigkeiten', 'en' => 'News'], $all['title']);
    $this->assertSame(['de' => 'neuigkeiten', 'en' => 'news'], $all['slug']);
  }

  /**
   * @param list<mixed> $entities
   */
  private function client(string $admin, array $entities): string
  {
    $name = is_array($entities[0]) ? 'Schreiber' : 'Leser';
    $client = $this->api('POST', '/admin/clients', ['name' => $name, 'entities' => $entities], $admin)['body']['data'];
    return $this->api('POST', '/main/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']], headers: ['Content-Type' => 'application/x-www-form-urlencoded'])['body']['access_token'];
  }
}
