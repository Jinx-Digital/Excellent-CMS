<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class ProjectTest extends ApiTestCase
{
  public function testProjectsAreSeparated(): void
  {
    $admin = $this->login();
    $slug = 'p'.substr(md5(uniqid('', true)), 0, 8);
    $project = $this->api('POST', '/admin/projects', ['name' => 'Shop', 'slug' => $slug, 'table_prefix' => $slug.'_'], $admin);
    $this->assertSame(200, $project['status'], json_encode($project['body'], JSON_UNESCAPED_UNICODE));
    $shop = ['X-Project' => $slug];

    // Same entity name as in "main" - another table, other records
    $countries = $this->api('POST', '/admin/entities', ['slug' => 'countries', 'name' => 'Länder', 'access' => 'public', 'fields' => [['name' => 'name', 'type' => 'string']]], $admin, $shop);
    $this->assertSame(200, $countries['status'], json_encode($countries['body'], JSON_UNESCAPED_UNICODE));
    $this->api('POST', '/entities/countries/records', ['name' => 'Shopland'], $admin, $shop);

    $this->assertSame(['Shopland'], array_column($this->api('GET', "/{$slug}/content/countries")['body']['data'], 'name'));
    $this->assertGreaterThan(200, $this->api('GET', '/main/content/countries?limit=1')['body']['meta']['total_items']);
    $this->assertSame(1, $this->api('GET', '/entities/countries/records', token: $admin, headers: $shop)['body']['meta']['total_items']);
    // Its own entities - shared ones of the area "Global" come along in every project
    $own = array_filter($this->api('GET', "/{$slug}/content")['body']['data'], static fn(array $entity): bool => !($entity['global'] ?? false));
    $this->assertSame(['countries'], array_column($own, 'slug'));
    $this->assertSame(404, $this->api('GET', '/gibtsnicht/content/countries')['status']);

    // A reference to another project's entity is impossible
    $mainEntityId = array_column($this->api('GET', '/admin/entities', token: $admin)['body']['data'], 'id', 'slug')['countries'];
    $ref = $this->api('POST', '/admin/entities', ['slug' => 'cities', 'name' => 'Städte', 'fields' => [['name' => 'country', 'type' => 'reference', 'reference' => $mainEntityId]]], $admin, $shop);
    $this->assertSame(422, $ref['status']);

    // Users only see their projects
    $this->assertSame(403, $this->api('GET', '/entities', token: $this->editorToken(), headers: $shop)['status']);
    $me = $this->api('GET', '/auth/me', token: $this->editorToken())['body']['data'];
    $this->assertSame(['main'], array_column($me['projects'], 'slug'));
    $this->assertSame('main', $me['project']['slug']);

    // API clients and their tokens belong to one project
    $client = $this->api('POST', '/admin/clients', ['name' => 'Shop-Webseite', 'entities' => ['countries']], $admin, $shop)['body']['data'];
    $this->assertNotContains($client['id'], array_column($this->api('GET', '/admin/clients', token: $admin)['body']['data'], 'id'));
    $credentials = ['grant_type' => 'client_credentials', 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']];
    $form = ['Content-Type' => 'application/x-www-form-urlencoded'];
    $this->assertSame(401, $this->api('POST', '/main/oauth/token', $credentials, headers: $form)['status']);
    $token = $this->api('POST', "/{$slug}/oauth/token", $credentials, headers: $form)['body']['access_token'];
    $this->assertSame(200, $this->api('GET', "/{$slug}/content/countries", token: $token)['status']);
    $this->assertSame(401, $this->api('GET', '/main/content/countries', token: $token)['status']);

    // Only empty projects can be deleted, prefixes only changed while empty
    $id = $project['body']['data']['id'];
    $this->assertSame(422, $this->api('PUT', "/admin/projects/{$id}", ['table_prefix' => 'neu_'], $admin)['status']);
    $this->assertSame(409, $this->api('DELETE', "/admin/projects/{$id}", token: $admin)['status']);
    $this->assertSame(200, $this->api('DELETE', "/admin/entities/{$countries['body']['data']['id']}", token: $admin, headers: $shop)['status']);
    $this->assertSame(200, $this->api('DELETE', "/admin/projects/{$id}", token: $admin)['status']);
  }

  public function testProjectValidation(): void
  {
    $admin = $this->login();
    $invalid = $this->api('POST', '/admin/projects', ['name' => '', 'slug' => 'entities', 'table_prefix' => 'main_'], $admin);
    $this->assertSame(422, $invalid['status']);
    foreach (['name', 'slug', 'table_prefix'] as $key) {
      $this->assertArrayHasKey($key, $invalid['body']['error_data']);
    }
    $this->assertSame(403, $this->api('POST', '/admin/projects', ['name' => 'X', 'slug' => 'x', 'table_prefix' => 'x_'], $this->editorToken())['status']);
  }
}
