<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class SchemaAndRecordTest extends ApiTestCase
{
  public function testCreateEntityAndRecordsWithValidation(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('products');
    $entity = $this->createEntity([
      'slug' => $slug,
      'name' => 'Produkte',
      'label_field' => 'name',
      'fields' => [
        ['name' => 'sku', 'type' => 'string', 'length' => 20, 'required' => true, 'unique' => true],
        ['name' => 'name', 'type' => 'string', 'required' => true],
        ['name' => 'price', 'type' => 'decimal', 'scale' => 2],
        ['name' => 'available', 'type' => 'date'],
        ['name' => 'active', 'type' => 'boolean'],
        ['name' => 'country', 'type' => 'reference', 'reference' => 'countries'],
      ],
    ], $admin);
    $this->assertCount(6, $entity['fields']);

    $record = $this->createRecord($slug, ['sku' => 'A1', 'name' => 'Tasse', 'price' => '12,50', 'available' => '29.10.2025', 'active' => 'ja', 'country' => $this->countryId('DE')], $admin);
    $this->assertSame(12.5, $record['price']);
    $this->assertSame('2025-10-29', $record['available']);
    $this->assertTrue($record['active']);
    $this->assertSame('Germany', $record['_refs']['country']['label']);

    $invalid = $this->api('POST', "/entities/{$slug}/records", ['sku' => 'A1', 'price' => 'teuer', 'available' => '31.02.2025', 'country' => 'gibtsnicht', 'unknown' => 1], $admin);
    $this->assertSame(422, $invalid['status']);
    foreach (['sku', 'name', 'price', 'available', 'country', 'unknown'] as $field) {
      $this->assertArrayHasKey($field, $invalid['body']['error_data'], json_encode($invalid['body']['error_data'], JSON_UNESCAPED_UNICODE));
    }

    // Partial update: only the sent field changes
    $updated = $this->api('PUT', "/entities/{$slug}/records/{$record['id']}", ['price' => '9.99'], $admin);
    $this->assertSame(9.99, $updated['body']['data']['price']);
    $this->assertSame('Tasse', $updated['body']['data']['name']);

    $list = $this->api('GET', "/entities/{$slug}/records?s=tass", token: $admin);
    $this->assertSame(1, $list['body']['meta']['total_items']);
  }

  public function testReferencedRecordsAndEntitiesCannotBeDeleted(): void
  {
    $admin = $this->login();
    $authors = $this->createEntity(['slug' => $this->uniqueSlug('authors'), 'name' => 'Autoren', 'fields' => [['name' => 'name', 'type' => 'string']]], $admin);
    $books = $this->createEntity(['slug' => $this->uniqueSlug('books'), 'name' => 'Bücher', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'author', 'type' => 'reference', 'reference' => $authors['slug']],
    ]], $admin);
    $author = $this->createRecord($authors['slug'], ['name' => 'Goethe'], $admin);
    $book = $this->createRecord($books['slug'], ['title' => 'Faust', 'author' => $author['id']], $admin);

    $delete = $this->api('DELETE', "/entities/{$authors['slug']}/records/{$author['id']}", token: $admin);
    $this->assertSame(409, $delete['status']);
    $this->assertSame('record_in_use', $delete['body']['error_code']);
    $references = $this->api('GET', "/entities/{$authors['slug']}/records/{$author['id']}/references", token: $admin)['body']['data'];
    $this->assertSame(1, $references[0]['count']);

    $this->assertSame(409, $this->api('DELETE', "/admin/entities/{$authors['id']}", token: $admin)['status']);

    $this->assertSame(200, $this->api('DELETE', "/entities/{$books['slug']}/records/{$book['id']}", token: $admin)['status']);
    $this->assertSame(200, $this->api('DELETE', "/entities/{$authors['slug']}/records/{$author['id']}", token: $admin)['status']);
    $this->assertSame(200, $this->api('DELETE', "/admin/entities/{$books['id']}", token: $admin)['status']);
    $this->assertSame(200, $this->api('DELETE', "/admin/entities/{$authors['id']}", token: $admin)['status']);
  }

  public function testChangeFieldTypeConvertsExistingValues(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('events'), 'name' => 'Termine', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'day', 'type' => 'string'],
      ['name' => 'country', 'type' => 'string'],
    ]], $admin);
    $this->createRecord($entity['slug'], ['title' => 'A', 'day' => '29.10.2025', 'country' => 'DE'], $admin);
    $this->createRecord($entity['slug'], ['title' => 'B', 'day' => '2025-11-01', 'country' => 'AT'], $admin);
    $fields = array_column($entity['fields'], 'id', 'name');

    // Text -> date: every value is converted
    $changed = $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['day']}", ['type' => 'date'], $admin);
    $this->assertSame(200, $changed['status'], json_encode($changed['body'], JSON_UNESCAPED_UNICODE));
    $days = array_column($this->api('GET', "/entities/{$entity['slug']}/records?sort=day", token: $admin)['body']['data'], 'day');
    $this->assertSame(['2025-10-29', '2025-11-01'], $days);

    // Text -> reference, looked up by the ISO code of the countries
    $changed = $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['country']}", ['type' => 'reference', 'reference' => 'countries', 'match' => 'alpha2code'], $admin);
    $this->assertSame(200, $changed['status'], json_encode($changed['body'], JSON_UNESCAPED_UNICODE));
    $records = $this->api('GET', "/entities/{$entity['slug']}/records?sort=title", token: $admin)['body']['data'];
    $this->assertSame('Germany', $records[0]['_refs']['country']['label']);

    // Not convertible: nothing changes
    $failed = $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['title']}", ['type' => 'integer'], $admin);
    $this->assertSame(422, $failed['status']);
    $this->assertStringContainsString('2 existing values', $failed['body']['error_data']['type'][0]);
  }

  public function testUniqueFieldAndRename(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('tags'), 'name' => 'Tags', 'fields' => [['name' => 'name', 'type' => 'string']]], $admin);
    $this->createRecord($entity['slug'], ['name' => 'rot'], $admin);
    $this->createRecord($entity['slug'], ['name' => 'rot'], $admin);
    $fieldId = $entity['fields'][0]['id'];

    $unique = $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fieldId}", ['unique' => true], $admin);
    $this->assertSame(422, $unique['status']);
    $this->assertStringContainsString('more than once', $unique['body']['error_data']['unique'][0]);

    $renamed = $this->api('PUT', "/admin/entities/{$entity['id']}", ['slug' => $entity['slug'].'_neu', 'access' => 'oauth'], $admin);
    $this->assertSame(200, $renamed['status']);
    $this->assertSame(2, $this->api('GET', "/entities/{$entity['slug']}_neu/records", token: $admin)['body']['meta']['total_items']);
    $this->assertSame(404, $this->api('GET', "/entities/{$entity['slug']}/records", token: $admin)['status']);
  }

  public function testUuidAndAutoIncrementFieldsAreFilledAutomatically(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('tickets'), 'name' => 'Tickets', 'fields' => [
      ['name' => 'nr', 'type' => 'autoincrement', 'required' => true],
      ['name' => 'code', 'type' => 'uuid', 'uuid_version' => 4, 'unique' => true],
      ['name' => 'title', 'type' => 'string'],
    ]], $admin);
    $fields = array_column($entity['fields'], null, 'name');
    $this->assertTrue($fields['nr']['unique']);
    $this->assertFalse($fields['nr']['required']);
    $this->assertSame(4, $fields['code']['uuid_version']);

    $first = $this->createRecord($entity['slug'], ['title' => 'A'], $admin);
    $second = $this->createRecord($entity['slug'], ['title' => 'B', 'nr' => null], $admin);
    $this->assertSame([1, 2], [$first['nr'], $second['nr']]);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-/', $first['code']);
    $this->assertNotSame($first['code'], $second['code']);

    // Explicit numbers like in SQL, the counter continues after them
    $this->assertSame(10, $this->createRecord($entity['slug'], ['title' => 'C', 'nr' => 10], $admin)['nr']);
    $this->assertSame(11, $this->createRecord($entity['slug'], ['title' => 'D'], $admin)['nr']);
    $this->assertSame(422, $this->api('POST', "/entities/{$entity['slug']}/records", ['nr' => 10], $admin)['status']);

    // Updates keep the number and the UUID
    $updated = $this->api('PUT', "/entities/{$entity['slug']}/records/{$first['id']}", ['title' => 'A2', 'nr' => null], $admin)['body']['data'];
    $this->assertSame([1, $first['code']], [$updated['nr'], $updated['code']]);
    $this->assertSame('A2', $this->api('GET', "/entities/{$entity['slug']}/records?filter[nr]=1", token: $admin)['body']['data'][0]['title']);

    // Only one counter per entity
    $second = $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'nr2', 'type' => 'autoincrement'], $admin);
    $this->assertSame(422, $second['status']);
    $this->assertArrayHasKey('type', $second['body']['error_data']);
  }

  public function testNewCounterAndUuidFieldsNumberExistingRecords(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('orders'), 'name' => 'Bestellungen', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    foreach (['A', 'B', 'C'] as $title) {
      $this->createRecord($entity['slug'], ['title' => $title], $admin);
    }

    $this->assertSame(200, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'nr', 'type' => 'autoincrement'], $admin)['status']);
    $this->assertSame(200, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'uuid', 'type' => 'uuid', 'unique' => true, 'required' => true], $admin)['status']);
    $records = $this->api('GET', "/entities/{$entity['slug']}/records?sort=nr", token: $admin)['body']['data'];
    $this->assertSame([[1, 'A'], [2, 'B'], [3, 'C']], array_map(static fn(array $r): array => [$r['nr'], $r['title']], $records));
    $this->assertCount(3, array_unique(array_filter(array_column($records, 'uuid'))));
    $this->assertSame(4, $this->createRecord($entity['slug'], ['title' => 'D'], $admin)['nr']);

    // Counter -> integer and back: values stay, numbering continues after the highest one
    $nr = array_column($this->api('GET', "/admin/entities/{$entity['id']}", token: $admin)['body']['data']['fields'], 'id', 'name')['nr'];
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$nr}", ['type' => 'integer'], $admin)['status']);
    $this->createRecord($entity['slug'], ['title' => 'E'], $admin);
    $changed = $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$nr}", ['type' => 'autoincrement'], $admin);
    $this->assertSame(200, $changed['status'], json_encode($changed['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(6, $this->createRecord($entity['slug'], ['title' => 'F'], $admin)['nr']);
    $this->assertSame(200, $this->api('DELETE', "/admin/entities/{$entity['id']}/fields/{$nr}", token: $admin)['status']);
  }

  public function testInvalidSchemaIsRejected(): void
  {
    $result = $this->api('POST', '/admin/entities', ['slug' => 'Böse Tabelle', 'name' => '', 'fields' => [
      ['name' => 'id', 'type' => 'string'],
      ['name' => 'text', 'type' => 'text', 'unique' => true],
      ['name' => 'ref', 'type' => 'reference', 'reference' => 'gibtsnicht'],
    ]], $this->login());

    $this->assertSame(422, $result['status']);
    foreach (['slug', 'name', 'fields.0.name', 'fields.1.unique', 'fields.2.reference'] as $key) {
      $this->assertArrayHasKey($key, $result['body']['error_data'], json_encode($result['body']['error_data'], JSON_UNESCAPED_UNICODE));
    }
  }
}
