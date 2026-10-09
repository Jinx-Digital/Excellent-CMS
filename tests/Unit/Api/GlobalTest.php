<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class GlobalTest extends ApiTestCase
{
  private const GLOBAL = ['X-Project' => 'global'];

  public function testTheAreaGlobalIsAProjectOfItsOwn(): void
  {
    $admin = $this->login();
    $projects = array_column($this->api('GET', '/admin/projects', token: $admin)['body']['data'], null, 'slug');
    $this->assertTrue($projects['global']['is_global']);
    $this->assertFalse($projects['main']['is_global']);
    $this->assertSame('main', $this->api('GET', '/auth/me', token: $admin)['body']['data']['project']['slug'], 'not the default project');

    $this->assertSame(409, $this->api('DELETE', "/admin/projects/{$projects['global']['id']}", token: $admin)['status']);
    $this->assertSame(422, $this->api('PUT', "/admin/projects/{$projects['global']['id']}", ['slug' => 'shared'], $admin)['status']);
    $this->assertSame(422, $this->api('POST', '/admin/projects', ['name' => 'Global 2', 'slug' => 'global', 'table_prefix' => 'gx_'], $admin)['status']);
    // Only admins work in it
    $this->assertSame(403, $this->api('GET', '/entities', token: $this->editorToken(), headers: self::GLOBAL)['status']);
  }

  public function testGlobalEntitiesAreSharedWithEveryProject(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('currencies');
    $created = $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Währungen', 'access' => 'public', 'label_field' => 'code', 'fields' => [
      ['name' => 'code', 'type' => 'string', 'required' => true],
      ['name' => 'symbol', 'type' => 'media'],
    ]], $admin, self::GLOBAL);
    $this->assertSame(200, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
    $entity = $created['body']['data'];
    $this->assertTrue($entity['global']);

    // Records are kept once - in the area "Global" or from any project
    $euro = $this->api('POST', "/entities/{$slug}/records", ['code' => 'EUR'], $admin, self::GLOBAL)['body']['data'];
    $image = $this->upload('/media', self::png(), 'dollar.png', $admin, ['entity' => $slug, 'field' => 'symbol']);
    $this->assertSame(200, $image['status'], json_encode($image['body'], JSON_UNESCAPED_UNICODE));
    $this->assertStringContainsString('/media/global/', $image['body']['data']['url'], 'files of global records are shared too');
    $dollar = $this->createRecord($slug, ['code' => 'USD', 'symbol' => $image['body']['data']['id']], $admin);

    // Every project sees it in its navigation and serves it
    $session = $this->api('GET', '/auth/me', token: $admin)['body']['data'];
    $this->assertTrue(array_column($session['entities'], 'global', 'slug')[$slug]);
    $project = 'p'.substr(md5(uniqid('', true)), 0, 8);
    $this->api('POST', '/admin/projects', ['name' => 'Shop', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    foreach (['main', $project] as $name) {
      $list = $this->api('GET', "/{$name}/content/{$slug}?sort=code")['body']['data'];
      $this->assertSame(['EUR', 'USD'], array_column($list, 'code'), $name);
      $this->assertStringContainsString('/media/global/', $list[1]['symbol']['url']);
    }

    // Projects reference global records
    $prices = $this->uniqueSlug('prices');
    $this->api('POST', '/admin/entities', ['slug' => $prices, 'name' => 'Preise', 'access' => 'public', 'fields' => [
      ['name' => 'amount', 'type' => 'decimal'],
      ['name' => 'currency', 'type' => 'reference', 'reference' => $slug],
    ]], $admin, ['X-Project' => $project]);
    $price = $this->api('POST', "/entities/{$prices}/records", ['amount' => '9,99', 'currency' => $dollar['id']], $admin, ['X-Project' => $project]);
    $this->assertSame(200, $price['status'], json_encode($price['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame('USD', $this->api('GET', "/{$project}/content/{$prices}?include=currency")['body']['data'][0]['currency']['code']);
    $referencesResponse = $this->api("GET", "/entities/{$slug}/records/{$dollar['id']}/references", token: $admin);
    $this->assertSame(200, $referencesResponse["status"], json_encode($referencesResponse["body"], JSON_UNESCAPED_UNICODE));
    $references = $referencesResponse["body"]["data"];
    $this->assertNotContains($prices, array_column($references, 'entity'), '"used in" lists what can be opened in this project');

    // One name space: no project entity with a global name, no global one with a project's name
    $this->assertSame(422, $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Doppelt', 'fields' => [['name' => 'x', 'type' => 'string']]], $admin)['status']);
    $taken = $this->api('POST', '/admin/entities', ['slug' => 'countries', 'name' => 'Länder', 'fields' => [['name' => 'x', 'type' => 'string']]], $admin, self::GLOBAL);
    $this->assertSame(422, $taken['status']);
    $this->assertStringContainsString('free everywhere', $taken['body']['error_data']['slug'][0]);

    // The schema is edited in the area "Global" only
    $this->assertSame(403, $this->api('PUT', "/admin/entities/{$entity['id']}", ['name' => 'Geld'], $admin)['status']);
    $this->assertSame(403, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'rate', 'type' => 'decimal'], $admin)['status']);
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}", ['name' => 'Geld'], $admin, self::GLOBAL)['status']);

    // Still referenced by a project: cannot go
    $this->assertSame(409, $this->api('DELETE', "/admin/entities/{$entity['id']}", token: $admin, headers: self::GLOBAL)['status']);
    $inUse = $this->api('DELETE', "/entities/{$slug}/records/{$dollar['id']}", token: $admin);
    $this->assertSame(409, $inUse['status'], 'a price of another project points to it');
    $this->assertStringContainsString('in another project', $inUse['body']['error']);
    $this->assertSame(200, $this->api('DELETE', "/entities/{$slug}/records/{$euro['id']}", token: $admin)['status']);
  }

  public function testGlobalEntitiesAnswerInTheProjectsLanguages(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('units');
    $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Einheiten', 'access' => 'public', 'fields' => [['name' => 'name', 'type' => 'string']]], $admin, self::GLOBAL);
    $this->api('POST', "/entities/{$slug}/records", ['name' => 'Meter'], $admin, self::GLOBAL);
    $project = 'p'.substr(md5(uniqid('', true)), 0, 8);
    $this->api('POST', '/admin/projects', ['name' => 'Web', 'slug' => $project, 'table_prefix' => $project.'_', 'languages' => ['fr', 'it']], $admin);

    $french = $this->api('GET', "/{$project}/content/{$slug}?lang=it");
    $this->assertSame(200, $french['status'], 'a language of the project - the default text stands in');
    $this->assertSame('Meter', $french['body']['data'][0]['name']);
    $this->assertSame(422, $this->api('GET', "/{$project}/content/{$slug}?lang=xx")['status']);
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(3, 3);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
