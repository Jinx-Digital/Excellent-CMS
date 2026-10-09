<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class NumberRangeTest extends ApiTestCase
{
  public function testNumbersWithinTheirRangeAndAsSlider(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('ranges');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Bereiche', 'fields' => [
      ['name' => 'width', 'type' => 'integer', 'min_value' => 20, 'max_value' => 80, 'slider' => true],
      ['name' => 'price', 'type' => 'decimal', 'scale' => 2, 'min_value' => '0,5'],
      ['name' => 'title', 'type' => 'string', 'min_value' => 1, 'slider' => true],
    ]], $admin);
    $fields = array_column($entity['fields'], null, 'name');
    $this->assertSame([20, 80, true], [$fields['width']['min_value'], $fields['width']['max_value'], $fields['width']['slider']]);
    $this->assertSame([0.5, null, false], [$fields['price']['min_value'], $fields['price']['max_value'], $fields['price']['slider']]);
    // Only numbers have a range
    $this->assertSame([null, null, false], [$fields['title']['min_value'], $fields['title']['max_value'], $fields['title']['slider']]);

    $this->assertSame(20, $this->createRecord($slug, ['width' => 20, 'price' => '0.50'], $admin)['width']);
    $invalid = $this->api('POST', "/entities/{$slug}/records", ['width' => 81, 'price' => '0.4'], $admin);
    $this->assertSame(422, $invalid['status']);
    $this->assertSame(['Between 20 and 80.'], $invalid['body']['error_data']['width']);
    $this->assertSame(['At least 0.5.'], $invalid['body']['error_data']['price']);
    // Empty stays allowed
    $this->assertNull($this->createRecord($slug, ['width' => null], $admin)['width']);

    // The rules of the settings: min <= max, a slider needs both ends; a partial change keeps the rest
    $url = "/admin/entities/{$entity['id']}/fields/{$fields['width']['id']}";
    $this->assertArrayHasKey('min_value', $this->api('PUT', $url, ['min_value' => 90], $admin)['body']['error_data']);
    $this->assertArrayHasKey('slider', $this->api('PUT', $url, ['max_value' => null], $admin)['body']['error_data']);
    $this->assertArrayHasKey('max_value', $this->api('PUT', $url, ['max_value' => 'viel'], $admin)['body']['error_data']);
    $changed = $this->api('PUT', $url, ['label' => 'Breite (%)'], $admin)['body']['data'];
    $this->assertSame([20, 80, true], [$changed['min_value'], $changed['max_value'], $changed['slider']]);

    // In blocks (field groups) as well
    $group = $this->api('POST', '/admin/groups', ['name' => $this->uniqueSlug('mt'), 'label' => 'Media', 'kind' => 'block', 'fields' => [
      ['name' => 'media_width', 'type' => 'integer', 'min_value' => 20, 'max_value' => 80, 'slider' => true],
    ]], $admin)['body']['data'];
    $this->assertTrue($group['fields'][0]['slider']);
  }
}
