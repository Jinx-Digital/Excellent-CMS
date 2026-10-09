<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class RegexTest extends ApiTestCase
{
  public function testValuesMustMatchThePattern(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('parts');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Teile', 'fields' => [
      ['name' => 'code', 'type' => 'regex', 'pattern' => '^[A-Z]{2}-\d{4}$', 'pattern_message' => 'Format: AB-1234', 'unique' => true],
      ['name' => 'aliases', 'type' => 'regex', 'pattern' => '^[a-z]+$', 'repeatable' => true],
    ]], $admin);
    $fields = array_column($entity['fields'], null, 'name');
    $this->assertSame(['^[A-Z]{2}-\d{4}$', 'Format: AB-1234'], [$fields['code']['pattern'], $fields['code']['pattern_message']]);

    $this->assertSame('AB-1234', $this->createRecord($slug, ['code' => 'AB-1234', 'aliases' => ['schraube', 'bolzen']], $admin)['code']);
    $invalid = $this->api('POST', "/entities/{$slug}/records", ['code' => 'ab-12', 'aliases' => ['ok', 'Nicht OK']], $admin)['body']['error_data'];
    $this->assertSame('Format: AB-1234', $invalid['code'][0]);
    $this->assertStringContainsString('Nicht OK', $invalid['aliases'][0]);

    // Pattern required and valid; a new pattern must fit the values there are
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'x', 'type' => 'regex'], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'y', 'type' => 'regex', 'pattern' => '^[a-z'], $admin)['status']);
    $changed = $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['code']['id']}", ['pattern' => '^\d+$'], $admin);
    $this->assertSame(422, $changed['status']);
    $this->assertStringContainsString('AB-1234', $changed['body']['error_data']['pattern'][0]);
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['code']['id']}", ['pattern' => '^[A-Z]{2}-\d{4,6}$'], $admin)['status']);

    // Import is checked too
    $upload = $this->upload('/imports', "code\nCD-5678\nfalsch\n", 'teile.csv', $admin)['body']['data'];
    $preview = $this->api('POST', "/imports/{$upload['import_id']}/preview", ['mode' => 'existing', 'target' => $slug, 'columns' => [['column' => 'code', 'field' => 'code']]], $admin)['body']['data'];
    $this->assertSame(['create' => 1, 'update' => 0, 'error' => 1], $preview['summary']);
  }
}
