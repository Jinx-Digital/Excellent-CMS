<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class ActorTest extends ApiTestCase
{
  public function testRecordsKnowWhoCreatedChangedAndDeletedThem(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('notes');
    $this->createEntity(['slug' => $slug, 'name' => 'Notizen', 'access' => 'public', 'trash' => true, 'fields' => [['name' => 'text', 'type' => 'string']]], $admin);
    $client = $this->api('POST', '/admin/clients', ['name' => 'Importer', 'entities' => [['entity' => $slug, 'update' => true, 'delete' => true]]], $admin)['body']['data'];
    $token = $this->api('POST', '/main/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']], headers: ['Content-Type' => 'application/x-www-form-urlencoded'])['body']['access_token'];

    $record = $this->createRecord($slug, ['text' => 'A'], $admin);
    $this->assertSame(['user', 'Administrator'], [$record['created_by']['type'], $record['created_by']['name']]);
    $this->assertSame($record['created_by'], $record['updated_by']);

    // Changed through the content API: the client
    $this->api('PUT', "/main/content/{$slug}/{$record['id']}", ['text' => 'B'], $token);
    $changed = $this->api('GET', "/entities/{$slug}/records/{$record['id']}", token: $admin)['body']['data'];
    $this->assertSame(['client', 'Importer'], [$changed['updated_by']['type'], $changed['updated_by']['name']]);
    $this->assertSame('user', $changed['created_by']['type']);

    // Not in the content API
    $this->assertArrayNotHasKey('created_by', $this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data']);

    // Deleted into the trash: deleted_by
    $this->api('DELETE', "/main/content/{$slug}/{$record['id']}", token: $token);
    $trash = $this->api('GET', "/entities/{$slug}/records?trash=1", token: $admin)['body']['data'][0];
    $this->assertSame('Importer', $trash['deleted_by']['name']);

    // Filter by who created
    $userId = $record['created_by']['id'];
    $this->createRecord($slug, ['text' => 'C'], $admin);
    $this->assertSame(1, $this->api('GET', "/entities/{$slug}/records?filter[created_by]=user:{$userId}", token: $admin)['body']['meta']['total_items']);
  }
}
