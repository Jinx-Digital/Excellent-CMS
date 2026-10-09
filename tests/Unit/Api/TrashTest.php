<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class TrashTest extends ApiTestCase
{
  public function testBulkDeleteWithoutTrashIsPermanent(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('notes'), 'name' => 'Notizen', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $this->assertFalse($entity['trash']);
    $ids = array_map(fn(string $t): string => $this->createRecord($entity['slug'], ['title' => $t], $admin)['id'], ['A', 'B', 'C']);

    $result = $this->api('POST', "/entities/{$entity['slug']}/records/delete", ['ids' => [$ids[0], $ids[1], 'gibtsnicht']], $admin)['body']['data'];
    $this->assertSame([0, 2], [$result['trashed'], $result['deleted']]);
    $this->assertSame('not_found', $result['failed'][0]['code']);
    $this->assertSame(1, $this->api('GET', "/entities/{$entity['slug']}/records", token: $admin)['body']['meta']['total_items']);

    $this->assertSame(400, $this->api('GET', "/entities/{$entity['slug']}/records?trash=1", token: $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/entities/{$entity['slug']}/records/delete", ['ids' => []], $admin)['status']);
    // The editor has no delete permission for anything but countries
    $this->assertSame(403, $this->api('POST', "/entities/{$entity['slug']}/records/delete", ['ids' => [$ids[2]]], $this->editorToken())['status']);
  }

  public function testTrashHidesRestoresAndEmpties(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('products');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Produkte', 'access' => 'public', 'trash' => true, 'fields' => [
      ['name' => 'sku', 'type' => 'string', 'unique' => true],
    ]], $admin);
    $this->assertTrue($entity['trash']);
    $a = $this->createRecord($slug, ['sku' => 'A'], $admin);
    $b = $this->createRecord($slug, ['sku' => 'B'], $admin);
    $orders = $this->createEntity(['slug' => $this->uniqueSlug('orders'), 'name' => 'Bestellungen', 'fields' => [['name' => 'product', 'type' => 'reference', 'reference' => $slug]]], $admin);
    $this->createRecord($orders['slug'], ['product' => $b['id']], $admin);

    // B is in use: A goes to the trash, B stays
    $result = $this->api('POST', "/entities/{$slug}/records/delete", ['ids' => [$a['id'], $b['id']]], $admin)['body']['data'];
    $this->assertSame([1, 0], [$result['trashed'], $result['deleted']]);
    $this->assertSame([$b['id'], 'record_in_use'], [$result['failed'][0]['id'], $result['failed'][0]['code']]);

    // Hidden everywhere except in the trash
    $this->assertSame(['B'], array_column($this->api('GET', "/entities/{$slug}/records", token: $admin)['body']['data'], 'sku'));
    $this->assertSame(['B'], array_column($this->api('GET', "/main/content/{$slug}")['body']['data'], 'sku'));
    $this->assertSame(404, $this->api('GET', "/main/content/{$slug}/{$a['id']}")['status']);
    $this->assertSame(404, $this->api('GET', "/entities/{$slug}/records/{$a['id']}", token: $admin)['status']);
    $trash = $this->api('GET', "/entities/{$slug}/records?trash=1", token: $admin)['body']['data'];
    $this->assertSame('A', $trash[0]['sku']);
    $this->assertNotEmpty($trash[0]['deleted_at']);
    $this->assertSame([1, 1], [$this->api('GET', "/entities/{$slug}", token: $admin)['body']['data']['trash_count'], count($trash)]);

    // Its unique value is still taken
    $taken = $this->api('POST', "/entities/{$slug}/records", ['sku' => 'A'], $admin);
    $this->assertSame(422, $taken['status']);
    $this->assertStringContainsString('trash', $taken['body']['error_data']['sku'][0]);

    // Switching the trash off needs an empty trash
    $off = $this->api('PUT', "/admin/entities/{$entity['id']}", ['trash' => false], $admin);
    $this->assertSame(422, $off['status']);
    $this->assertArrayHasKey('trash', $off['body']['error_data']);

    $this->assertSame(1, $this->api('POST', "/entities/{$slug}/records/restore", ['ids' => [$a['id']]], $admin)['body']['data']['restored']);
    $this->assertSame(2, $this->api('GET', "/main/content/{$slug}")['body']['meta']['total_items']);

    $this->api('POST', "/entities/{$slug}/records/delete", ['ids' => [$a['id']]], $admin);
    $emptied = $this->api('POST', "/entities/{$slug}/trash/empty", token: $admin)['body']['data'];
    $this->assertSame(1, $emptied['deleted']);
    $this->assertSame(200, $this->createRecord($slug, ['sku' => 'A'], $admin) ? 200 : 0);

    // Single delete also goes to the trash; permanent deletes skip it
    $c = $this->createRecord($slug, ['sku' => 'C'], $admin);
    $this->assertSame(200, $this->api('DELETE', "/entities/{$slug}/records/{$c['id']}", token: $admin)['status']);
    $this->assertSame(1, count($this->api('GET', "/entities/{$slug}/records?trash=1", token: $admin)['body']['data']));
    $this->assertSame(1, $this->api('POST', "/entities/{$slug}/records/delete", ['ids' => [$c['id']], 'permanent' => true], $admin)['body']['data']['deleted']);

    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}", ['trash' => false], $admin)['status']);
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}", ['trash' => true], $admin)['status']);
  }

  public function testImportReportsKeysInTheTrash(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('items');
    $this->createEntity(['slug' => $slug, 'name' => 'Artikel', 'trash' => true, 'fields' => [
      ['name' => 'sku', 'type' => 'string', 'unique' => true],
      ['name' => 'qty', 'type' => 'integer'],
    ]], $admin);
    $a = $this->createRecord($slug, ['sku' => 'A', 'qty' => 1], $admin);
    $this->api('DELETE', "/entities/{$slug}/records/{$a['id']}", token: $admin);

    $upload = $this->upload('/imports', "sku;qty\nA;5\nB;7\n", 'items.csv', $admin)['body']['data'];
    $preview = $this->api('POST', "/imports/{$upload['import_id']}/preview", ['mode' => 'existing', 'target' => $slug, 'key' => 'sku', 'columns' => [
      ['column' => 'sku', 'field' => 'sku'],
      ['column' => 'qty', 'field' => 'qty'],
    ]], $admin)['body']['data'];
    $this->assertSame(['create' => 1, 'update' => 0, 'error' => 1], $preview['summary']);
    $this->assertStringContainsString('trash', $preview['errors'][0]['message']);
  }
}
