<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Field types color, phone, date range and JSON: stored in one form, delivered typed.
 */
class SimpleTypesTest extends ApiTestCase
{
  public function testValuesAreNormalized(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('events');
    $this->createEntity(['slug' => $slug, 'name' => 'Events', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'color', 'type' => 'color'],
      ['name' => 'colors', 'type' => 'color', 'repeatable' => true],
      ['name' => 'phone', 'type' => 'phone'],
      ['name' => 'period', 'type' => 'daterange'],
      ['name' => 'data', 'type' => 'json'],
    ]], $admin);
    $this->assertSame(422, $this->api('POST', '/admin/entities', ['slug' => $slug.'x', 'name' => 'X', 'fields' => [['name' => 'p', 'type' => 'daterange', 'repeatable' => true]]], $admin)['status'], 'no lists of date ranges');

    $record = $this->createRecord($slug, [
      'title' => 'A',
      'color' => '#1E40AF',
      'colors' => ['14f', 'ff000080'],
      'phone' => '0049 (30) 123 456-7',
      'period' => '01.10.2026 - 05.10.2026',
      'data' => '{"a": [1, 2.0], "b": {}}',
    ], $admin);
    $this->assertSame(['#1e40af', ['#1144ff', '#ff000080'], '+49301234567'], [$record['color'], $record['colors'], $record['phone']]);
    $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-05'], $record['period']);

    // The content API: date range as object, JSON as it was ({} stays an object)
    $raw = $this->api('GET', "/main/content/{$slug}")['body']['data'][0];
    $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-05'], $raw['period']);
    $this->assertSame(['a' => [1, 2], 'b' => []], $raw['data']);
    $this->assertStringContainsString('"b":{}', $this->api('GET', "/main/content/{$slug}")['content']);

    // Objects from the API, open end
    $second = $this->createRecord($slug, ['title' => 'B', 'period' => ['from' => '2026-11-01'], 'data' => ['x' => true]], $admin);
    $this->assertSame([['from' => '2026-11-01', 'to' => null], ['x' => true]], [$second['period'], $second['data']]);
    $this->assertSame(['A', 'B'], array_column($this->api('GET', "/main/content/{$slug}?sort=period")['body']['data'], 'title'), 'sorted by start');

    $invalid = $this->api('POST', "/entities/{$slug}/records", ['color' => 'blau', 'phone' => '030 123456', 'period' => '05.10.2026 - 01.10.2026', 'data' => '{a:1}'], $admin);
    $this->assertSame(422, $invalid['status']);
    $this->assertSame(['color', 'phone', 'period', 'data'], array_keys($invalid['body']['error_data']));
  }
}
