<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Application\Media\MediaService;
use App\Tests\Support\ApiTestCase;

/**
 * Revisions: a snapshot after every change, shown like the record, restorable as a whole or by field.
 */
class RevisionTest extends ApiTestCase
{
  public function testHistoryCompareAndRestore(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('pages');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'access' => 'public', 'trash' => true, 'revisions' => true, 'fields' => [
      ['name' => 'title', 'type' => 'string', 'required' => true],
      ['name' => 'text', 'type' => 'text'],
      ['name' => 'image', 'type' => 'media'],
      ['name' => 'old', 'type' => 'string'],
    ]], $admin);
    $image = $this->upload('/media', self::png(), 'erstes.png', $admin)['body']['data'];
    $record = $this->createRecord($slug, ['title' => 'Erste Fassung', 'text' => 'Hallo', 'image' => $image['id'], 'old' => 'weg'], $admin);
    $id = $record['id'];
    $this->api('PUT', "/entities/{$slug}/records/{$id}", ['title' => 'Zweite Fassung', 'image' => null], $admin);
    $this->api('PUT', "/entities/{$slug}/records/{$id}", ['title' => 'Zweite Fassung'], $admin); // nothing changed: no revision
    $this->api('PUT', "/entities/{$slug}/records/{$id}", ['text' => 'Welt'], $admin);

    $history = $this->api('GET', "/entities/{$slug}/records/{$id}/revisions", token: $admin)['body']['data'];
    $this->assertSame(['update', 'update', 'create'], array_column($history, 'action'));
    $this->assertSame([['text'], ['title', 'image'], []], array_column($history, 'changed'));
    $this->assertSame('Administrator', $history[0]['created_by']['name']);

    // The file of the first version stays although no record uses it any more (tests share the
    // database: other unused files may go)
    $this->container()->get(MediaService::class)->removeUnused(0);
    $this->assertSame(200, $this->api('GET', "/media/{$image['id']}", token: $admin)['status']);

    // A field deleted since then: listed as removed, the rest shown like the record
    $old = array_column($entity['fields'], null, 'name')['old'];
    $this->api('DELETE', "/admin/entities/{$entity['id']}/fields/{$old['id']}", token: $admin);
    $first = $this->api('GET', "/entities/{$slug}/records/{$id}/revisions/{$history[2]['id']}", token: $admin)['body']['data'];
    $this->assertSame(['Erste Fassung', 'Hallo', 'erstes.png'], [$first['record']['title'], $first['record']['text'], $first['record']['image']['name']]);
    $this->assertSame([['name' => 'old', 'label' => 'Old', 'value' => 'weg']], $first['removed']);

    // Restore one field, then everything
    $restored = $this->api('POST', "/entities/{$slug}/records/{$id}/revisions/{$history[2]['id']}/restore", ['fields' => ['title']], $admin);
    $this->assertSame(200, $restored['status'], json_encode($restored['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['Erste Fassung', 'Welt', null], [$restored['body']['data']['title'], $restored['body']['data']['text'], $restored['body']['data']['image']]);
    $all = $this->api('POST', "/entities/{$slug}/records/{$id}/revisions/{$history[2]['id']}/restore", [], $admin)['body']['data'];
    $this->assertSame(['Erste Fassung', 'Hallo', $image['id']], [$all['title'], $all['text'], $all['image']['id']]);
    $this->assertCount(5, $this->api('GET', "/entities/{$slug}/records/{$id}/revisions", token: $admin)['body']['data'], 'restoring is a change too');

    // Permissions: reading needs "read", restoring "update"
    $this->assertSame(403, $this->api('GET', "/entities/{$slug}/records/{$id}/revisions", token: $this->editorToken())['status']);

    // Trash and restore are in the history; deleting for good removes it
    $this->api('DELETE', "/entities/{$slug}/records/{$id}", token: $admin);
    $this->api('POST', "/entities/{$slug}/records/restore", ['ids' => [$id]], $admin);
    $this->assertSame(['restore', 'trash'], array_slice(array_column($this->api('GET', "/entities/{$slug}/records/{$id}/revisions", token: $admin)['body']['data'], 'action'), 0, 2));
    $this->api('POST', "/entities/{$slug}/records/delete", ['ids' => [$id], 'permanent' => true], $admin);
    $this->assertSame(404, $this->api('GET', "/entities/{$slug}/records/{$id}/revisions", token: $admin)['status']);
    // Other tests count the unused files
    $this->assertSame(200, $this->api('DELETE', "/media/{$image['id']}", token: $admin)['status']);
  }

  public function testOnlyEntitiesWithRevisions(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('links');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Links', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $this->assertFalse($entity['revisions'], 'off by default');
    $id = $this->createRecord($slug, ['title' => 'A'], $admin)['id'];
    $this->api('PUT', "/entities/{$slug}/records/{$id}", ['title' => 'B'], $admin);
    $this->assertSame([], $this->api('GET', "/entities/{$slug}/records/{$id}/revisions", token: $admin)['body']['data']);

    // Switched on: the state before the first change becomes the first revision
    $this->api('PUT', "/admin/entities/{$entity['id']}", ['revisions' => true], $admin);
    $this->api('PUT', "/entities/{$slug}/records/{$id}", ['title' => 'C'], $admin);
    $this->assertSame(['update', 'create'], array_column($this->api('GET', "/entities/{$slug}/records/{$id}/revisions", token: $admin)['body']['data'], 'action'));
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
