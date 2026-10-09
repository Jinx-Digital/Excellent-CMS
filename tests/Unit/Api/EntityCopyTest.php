<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class EntityCopyTest extends ApiTestCase
{
  public function testEntitiesAreCopiedIntoAnotherProject(): void
  {
    $admin = $this->login();
    $project = 'p'.substr(md5(uniqid('', true)), 0, 8);
    $created = $this->api('POST', '/admin/projects', ['name' => 'Kopie', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $this->assertSame(200, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
    $there = ['X-Project' => $project];

    $group = $this->uniqueSlug('seo');
    $this->api('POST', '/admin/groups', ['name' => $group, 'label' => 'SEO', 'fields' => [['name' => 'keywords', 'type' => 'string'], ['name' => 'image', 'type' => 'media']]], $admin);
    $slug = $this->uniqueSlug('partners');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Partner', 'access' => 'public', 'label_field' => 'name', 'tree_field' => 'parent', 'trash' => true, 'fields' => [
      ['name' => 'name', 'type' => 'string', 'required' => true],
      ['name' => 'country', 'type' => 'reference', 'reference' => 'countries'],
      ['name' => 'markets', 'type' => 'reference', 'reference' => 'countries', 'repeatable' => true],
      ['name' => 'logo', 'type' => 'media'],
      ['name' => 'seo', 'type' => 'group', 'group' => $group],
      ['name' => 'parent', 'type' => 'reference', 'reference' => $slug],
    ]], $admin);
    $logo = $this->upload('/media', self::png(), 'logo.png', $admin)['body']['data'];
    $image = $this->upload('/media', self::png(), 'seo.png', $admin)['body']['data'];
    $child = $this->createRecord($slug, ['name' => 'ACME Berlin', 'country' => $this->countryId('DE'), 'markets' => [$this->countryId('AT'), $this->countryId('FR')], 'logo' => $logo['id'], 'seo' => ['keywords' => 'acme', 'image' => $image['id']]], $admin);
    $parent = $this->createRecord($slug, ['name' => 'ACME'], $admin);
    $this->api('PUT', "/entities/{$slug}/records/{$child['id']}", ['parent' => $parent['id']], $admin);

    // Same project, unknown project, references to an entity the target does not have
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => 'main'], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => 'gibtsnicht'], $admin)['status']);
    $missing = $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => $project, 'records' => true], $admin);
    $this->assertSame(422, $missing['status'], json_encode($missing['body'], JSON_UNESCAPED_UNICODE));
    $this->assertStringContainsString('countries', $missing["body"]["error_data"]["project"][0] ?? json_encode($missing["body"], JSON_UNESCAPED_UNICODE));
    $this->assertSame([], array_values(array_filter($this->api('GET', '/admin/entities', token: $admin, headers: $there)['body']['data'], static fn(array $entity): bool => !$entity['global'])), 'nothing half done');
    $this->assertSame(403, $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => $project], $this->editorToken())['status']);

    // Regions and countries first: with their records (same ids) ...
    $ids = array_column($this->api('GET', '/admin/entities', token: $admin)['body']['data'], 'id', 'slug');
    $countriesId = $ids['countries'];
    $this->assertStringContainsString('regions', $this->api('POST', "/admin/entities/{$countriesId}/copy", ['project' => $project], $admin)['body']['error_data']['project'][0]);
    $regions = $this->api('POST', "/admin/entities/{$ids['regions']}/copy", ['project' => $project, 'records' => true], $admin);
    $this->assertSame(200, $regions['status'], json_encode($regions['body'], JSON_UNESCAPED_UNICODE));
    $countries = $this->api('POST', "/admin/entities/{$countriesId}/copy", ['project' => $project, 'records' => true], $admin);
    $this->assertSame(200, $countries['status'], json_encode($countries['body'], JSON_UNESCAPED_UNICODE));
    $total = $this->api('GET', '/main/content/countries?limit=1')['body']['meta']['total_items'];
    $this->assertSame($total, $countries['body']['data']['records']);
    $this->assertSame('Germany', $this->api('GET', "/{$project}/content/countries/{$this->countryId('DE')}")['body']['data']['name']);

    // ... then the partners: references stay, files and the field group are copied, the tree too
    $copy = $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => $project, 'records' => true], $admin);
    $this->assertSame(200, $copy['status'], json_encode($copy['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame([2, 2, []], [$copy['body']['data']['records'], $copy['body']['data']['media'], (array)$copy['body']['data']['cleared']]);

    $copied = $this->api('GET', "/{$project}/content/{$slug}/{$child['id']}?include=country")['body']['data'];
    $this->assertSame(['ACME Berlin', 'Germany', $parent['id']], [$copied['name'], $copied['country']['name'], $copied['parent']]);
    $this->assertSame([$this->countryId('AT'), $this->countryId('FR')], $copied['markets']);
    $this->assertNotSame($logo['id'], $copied['logo']['id']);
    $this->assertStringContainsString("/media/{$project}/", $copied['logo']['url']);
    $this->assertSame('acme', $copied['seo']['keywords']);
    $this->assertStringContainsString("/media/{$project}/", $copied['seo']['image']['url']);
    $this->assertContains($group, array_column($this->api('GET', '/admin/groups', token: $admin, headers: $there)['body']['data'], 'name'));
    // The original keeps its files
    $this->assertSame($logo['id'], $this->api('GET', "/main/content/{$slug}/{$child['id']}")['body']['data']['logo']['id']);

    // Only the schema, under another name
    $schemaOnly = $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => $project, 'slug' => $slug.'x'], $admin);
    $this->assertSame([200, 0], [$schemaOnly['status'], $schemaOnly['body']['data']['records']]);
    $fields = $this->api('GET', "/admin/entities/{$schemaOnly['body']['data']['entity']['id']}", token: $admin, headers: $there)['body']['data'];
    $this->assertSame([$slug.'x', 'parent'], [$fields['slug'], $fields['tree_field']]);
    $this->assertSame($slug.'x', array_column($fields['fields'], 'reference', 'name')['parent'], 'the tree points to the copy');
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => $project], $admin)['status'], 'slug taken there');
  }

  public function testReferencesToMissingRecordsAreEmptied(): void
  {
    $admin = $this->login();
    $project = 'p'.substr(md5(uniqid('', true)), 0, 8);
    $this->api('POST', '/admin/projects', ['name' => 'Leer', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    // regions and countries exist there, but empty
    $ids = array_column($this->api('GET', '/admin/entities', token: $admin)['body']['data'], 'id', 'slug');
    $this->api('POST', "/admin/entities/{$ids['regions']}/copy", ['project' => $project], $admin);
    $this->assertSame(200, $this->api('POST', "/admin/entities/{$ids['countries']}/copy", ['project' => $project], $admin)['status']);

    $slug = $this->uniqueSlug('cities');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Städte', 'fields' => [
      ['name' => 'name', 'type' => 'string'],
      ['name' => 'country', 'type' => 'reference', 'reference' => 'countries'],
    ]], $admin);
    $this->createRecord($slug, ['name' => 'Berlin', 'country' => $this->countryId('DE')], $admin);
    $this->createRecord($slug, ['name' => 'Wien', 'country' => $this->countryId('AT')], $admin);

    $copy = $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => $project, 'records' => true], $admin)['body']['data'];
    $this->assertSame([2], array_values((array)$copy['cleared']), 'both countries are missing there');
    $this->assertSame([null, null], array_column($this->api('GET', "/{$project}/content/{$slug}")['body']['data'], 'country'));
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(3, 3);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
