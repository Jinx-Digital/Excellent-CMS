<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class RepeatableTest extends ApiTestCase
{
  public function testListsOfValuesAndReferences(): void
  {
    $admin = $this->login();
    $tagsSlug = $this->uniqueSlug('tags');
    $this->createEntity(['slug' => $tagsSlug, 'name' => 'Tags', 'label_field' => 'name', 'fields' => [['name' => 'name', 'type' => 'string', 'unique' => true]]], $admin);
    $red = $this->createRecord($tagsSlug, ['name' => 'rot'], $admin);
    $blue = $this->createRecord($tagsSlug, ['name' => 'blau'], $admin);

    $slug = $this->uniqueSlug('products');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Produkte', 'access' => 'public', 'label_field' => 'title', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'keywords', 'type' => 'string', 'repeatable' => true, 'repeat_min' => 2, 'repeat_max' => 3, 'sortable' => false],
      ['name' => 'sizes', 'type' => 'integer', 'repeatable' => true],
      ['name' => 'tags', 'type' => 'reference', 'reference' => $tagsSlug, 'repeatable' => true],
    ]], $admin);
    $fields = array_column($entity['fields'], null, 'name');
    $this->assertSame([true, false, 2, 3], [$fields['keywords']['repeatable'], $fields['keywords']['sortable'], $fields['keywords']['repeat_min'], $fields['keywords']['repeat_max']]);

    $record = $this->createRecord($slug, ['title' => 'Tasse', 'keywords' => ['Küche', 'Kaffee'], 'sizes' => ['1.000', 250], 'tags' => [$blue['id'], $red['id']]], $admin);
    $this->assertSame([['Küche', 'Kaffee'], [1000, 250]], [$record['keywords'], $record['sizes']]);
    $this->assertSame(['blau', 'rot'], array_column($record['_refs']['tags'], 'label'));

    // Checked like single values, with min and max
    $invalid = $this->api('POST', "/entities/{$slug}/records", ['keywords' => ['a'], 'sizes' => ['zwölf'], 'tags' => ['gibtsnicht']], $admin)['body']['error_data'];
    $this->assertStringContainsString('at least 2', $invalid['keywords'][0]);
    $this->assertStringContainsString('zwölf', $invalid['sizes'][0]);
    $this->assertStringContainsString('does not exist', $invalid['tags'][0]);
    $this->assertSame(422, $this->api('POST', "/entities/{$slug}/records", ['keywords' => ['a', 'b', 'c', 'd']], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'u', 'type' => 'string', 'repeatable' => true, 'unique' => true], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'b', 'type' => 'boolean', 'repeatable' => true], $admin)['status']);

    // Content API: lists, filter "contains", embedded references
    $this->createRecord($slug, ['title' => 'Teller', 'keywords' => ['Küche', 'Geschirr'], 'tags' => [$blue['id']]], $admin);
    $this->assertSame(['Tasse'], array_column($this->api('GET', "/main/content/{$slug}?filter[tags]={$red['id']}")['body']['data'], 'title'));
    $this->assertSame(['Teller', 'Tasse'], array_column($this->api('GET', "/main/content/{$slug}?filter[keywords]=Küche&sort=-title")['body']['data'], 'title'));
    $this->assertSame(['Teller'], array_column($this->api('GET', "/main/content/{$slug}?filter[sizes][ne]=250")['body']['data'], 'title'));
    $included = $this->api('GET', "/main/content/{$slug}/{$record['id']}?include=tags")['body']['data'];
    $this->assertSame(['blau', 'rot'], array_column($included['tags'], 'name'));

    // A record in a list cannot be deleted
    $this->assertSame(409, $this->api('DELETE', "/entities/{$tagsSlug}/records/{$red['id']}", token: $admin)['status']);

    // Import: several values in a cell, separated by |
    $upload = $this->upload('/imports', "title;keywords;tags\nBecher;Küche | Tee;rot | blau\nKanne;Tee;gelb\n", 'p.csv', $admin)['body']['data'];
    $run = $this->api('POST', "/imports/{$upload['import_id']}/run", ['mode' => 'existing', 'target' => $slug, 'columns' => [
      ['column' => 'title', 'field' => 'title'],
      ['column' => 'keywords', 'field' => 'keywords'],
      ['column' => 'tags', 'field' => 'tags', 'match' => 'name'],
    ]], $admin)['body']['data'];
    $this->assertSame(['create' => 1, 'update' => 0, 'error' => 1], $run['summary']);
    $mug = $this->api('GET', "/main/content/{$slug}?filter[title]=Becher")['body']['data'][0];
    $this->assertSame([['Küche', 'Tee'], [$red['id'], $blue['id']]], [$mug['keywords'], $mug['tags']]);

    // Switching "repeatable" off keeps single values, refuses longer lists
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['keywords']['id']}", ['repeatable' => false], $admin)['status']);
    $title = $fields['title']['id'];
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$title}", ['repeatable' => true], $admin)['status']);
    $this->assertSame(['Becher'], $this->api('GET', "/main/content/{$slug}/{$mug['id']}")['body']['data']['title']);
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$title}", ['repeatable' => false], $admin)['status']);
    $this->assertSame('Becher', $this->api('GET', "/main/content/{$slug}/{$mug['id']}")['body']['data']['title']);
  }
}
