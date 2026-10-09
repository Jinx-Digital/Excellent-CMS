<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Order field: new records go to the end, drag & drop reorders, lists use the order by default.
 */
class OrderFieldTest extends ApiTestCase
{
  public function testRecordsKeepTheirOrder(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('clients');
    $twice = $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Kunden', 'fields' => [
      ['name' => 'sort', 'type' => 'order'],
      ['name' => 'position', 'type' => 'order'],
    ]], $admin);
    $this->assertSame(422, $twice['status'], 'one order field per entity');

    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Kunden', 'access' => 'public', 'fields' => [
      ['name' => 'name', 'type' => 'string'],
      ['name' => 'sort', 'type' => 'order', 'required' => true],
    ]], $admin);
    $this->assertSame('sort', $entity['order_field']);
    $this->assertFalse(array_column($entity['fields'], null, 'name')['sort']['required'], 'filled in by the CMS');

    // Without a value: the end of the list
    $ids = [];
    foreach (['A', 'B', 'C', 'D'] as $name) {
      $ids[$name] = $this->createRecord($slug, ['name' => $name], $admin)['id'];
    }
    $this->assertSame(10, $this->createRecord($slug, ['name' => 'X', 'sort' => 10], $admin)['sort'], 'a given value stays');
    $this->assertSame(11, $this->createRecord($slug, ['name' => 'E'], $admin)['sort']);
    $names = fn(string $path = '', ?array $headers = null): array => array_column($this->api('GET', $path ?: "/entities/{$slug}/records", token: $admin)['body']['data'], 'name');
    $this->assertSame(['A', 'B', 'C', 'D', 'X', 'E'], $names());
    $this->assertSame(['A', 'B', 'C', 'D', 'X', 'E'], array_column($this->api('GET', "/main/content/{$slug}")['body']['data'], 'name'), 'content API too');
    $this->assertSame(['E', 'X', 'D', 'C', 'B', 'A'], $names("/entities/{$slug}/records?sort=-sort"));

    // Drag & drop on a page (B, C, D): D moves up - the others keep their places, all numbered 1 ...
    $result = $this->api('POST', "/entities/{$slug}/records/order", ['ids' => [$ids['D'], $ids['B'], $ids['C']]], $admin);
    $this->assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['A', 'D', 'B', 'C', 'X', 'E'], $names());
    $this->assertSame([1, 2, 3, 4, 5, 6], array_column($this->api('GET', "/entities/{$slug}/records", token: $admin)['body']['data'], 'sort'));

    // Needs "update"; entities without an order field cannot be reordered
    $this->assertSame(403, $this->api('POST', "/entities/{$slug}/records/order", ['ids' => [$ids['A']]], $this->editorToken())['status']);
    $this->assertSame(422, $this->api('POST', '/entities/countries/records/order', ['ids' => []], $admin)['status']);

    // Renaming keeps the order, deleting the field ends it
    $field = array_column($entity['fields'], null, 'name')['sort'];
    $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$field['id']}", ['name' => 'position'], $admin);
    $this->assertSame('position', $this->api('GET', "/admin/entities/{$entity['id']}", token: $admin)['body']['data']['order_field']);
    $this->assertSame(200, $this->api('DELETE', "/admin/entities/{$entity['id']}/fields/{$field['id']}", token: $admin)['status']);
    $this->assertNull($this->api('GET', "/admin/entities/{$entity['id']}", token: $admin)['body']['data']['order_field']);
  }
}
