<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Field type "enum": only the values the admin set, as single value or list.
 */
class EnumFieldTest extends ApiTestCase
{
  public function testOnlyTheSetValues(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('orders');
    $this->assertSame(422, $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Aufträge', 'fields' => [['name' => 'status', 'type' => 'enum']]], $admin)['status'], 'needs values');
    $this->assertSame(422, $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Aufträge', 'fields' => [['name' => 'status', 'type' => 'enum', 'options' => ['a', 'a']]]], $admin)['status'], 'values once');

    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Aufträge', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      // Objects, plain values or "value = Label" lines
      ['name' => 'status', 'type' => 'enum', 'options' => [['value' => 'open', 'label' => 'Offen'], ['value' => 'done', 'label' => 'Erledigt'], 'archived']],
      ['name' => 'tags', 'type' => 'enum', 'repeatable' => true, 'options' => "red = Rot\nblue = Blau"],
    ]], $admin);
    $fields = array_column($entity['fields'], null, 'name');
    $this->assertSame([['value' => 'open', 'label' => 'Offen'], ['value' => 'done', 'label' => 'Erledigt'], ['value' => 'archived', 'label' => 'archived']], $fields['status']['options']);
    $this->assertSame(['red', 'blue'], array_column($fields['tags']['options'], 'value'));

    $record = $this->createRecord($slug, ['title' => 'A', 'status' => 'open', 'tags' => ['blue', 'red']], $admin);
    $this->assertSame(['open', ['blue', 'red']], [$record['status'], $record['tags']]);
    $invalid = $this->api('POST', "/entities/{$slug}/records", ['title' => 'B', 'status' => 'Offen', 'tags' => ['green']], $admin);
    $this->assertSame(422, $invalid['status']);
    $this->assertStringContainsString('open, done, archived', $invalid['body']['error_data']['status'][0]);
    $this->assertArrayHasKey('tags', $invalid['body']['error_data']);

    // The content API returns the value; the schema has the labels
    $this->assertSame('open', $this->api('GET', "/main/content/{$slug}")['body']['data'][0]['status']);
    $this->assertSame(['open'], array_column($this->api('GET', "/main/content/{$slug}?filter[status]=open")['body']['data'], 'status'));

    // Values in use cannot be taken out, unused ones can
    $field = $fields['status'];
    $removed = $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$field['id']}", ['options' => ['done', 'archived']], $admin);
    $this->assertSame(422, $removed['status']);
    $this->assertStringContainsString('"open" (1)', $removed['body']['error_data']['options'][0]);
    $kept = $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$field['id']}", ['options' => [['value' => 'open', 'label' => 'Neu'], ['value' => 'done', 'label' => 'Erledigt']]], $admin);
    $this->assertSame(200, $kept['status'], json_encode($kept['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame('Neu', $kept['body']['data']['options'][0]['label']);
  }
}
