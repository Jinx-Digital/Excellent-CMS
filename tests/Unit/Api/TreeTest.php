<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class TreeTest extends ApiTestCase
{
  public function testPagesFormATree(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('pages');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'access' => 'public', 'label_field' => 'title', 'tree_field' => 'parent', 'fields' => [
      ['name' => 'title', 'type' => 'string', 'unique' => true],
      ['name' => 'parent', 'type' => 'reference', 'reference' => $slug],
    ]], $admin);
    $this->assertSame('parent', $entity['tree_field']);

    $a = $this->createRecord($slug, ['title' => 'A'], $admin);
    $b = $this->createRecord($slug, ['title' => 'B', 'parent' => $a['id']], $admin);
    $c = $this->createRecord($slug, ['title' => 'C', 'parent' => $b['id']], $admin);
    $this->createRecord($slug, ['title' => 'D'], $admin);

    // Roots with the number of their children
    $roots = $this->api('GET', "/entities/{$slug}/records?filter[parent][null]=true&sort=title", token: $admin)['body']['data'];
    $this->assertSame([['A', 1], ['D', 0]], array_map(static fn(array $r): array => [$r['title'], $r['_children']], $roots));
    $children = $this->api('GET', "/entities/{$slug}/records?filter[parent]={$a['id']}", token: $admin)['body']['data'];
    $this->assertSame(['B'], array_column($children, 'title'));

    $detail = $this->api('GET', "/entities/{$slug}/records/{$c['id']}", token: $admin)['body']['data'];
    $this->assertSame(['A', 'B'], array_column($detail['_path'], 'label'));

    // No circles
    foreach ([$c['id'], $a['id']] as $parent) {
      $circle = $this->api('PUT', "/entities/{$slug}/records/{$a['id']}", ['parent' => $parent], $admin);
      $this->assertSame(422, $circle['status']);
      $this->assertArrayHasKey('parent', $circle['body']['error_data']);
    }
    // Moving a branch is fine
    $this->assertSame(200, $this->api('PUT', "/entities/{$slug}/records/{$c['id']}", ['parent' => $a['id']], $admin)['status']);
    $this->assertSame(409, $this->api('DELETE', "/entities/{$slug}/records/{$a['id']}", token: $admin)['status']);

    $tree = $this->api('GET', "/main/content/{$slug}?tree=1&sort=title&fields=title")['body']['data'];
    $this->assertSame(['A', 'D'], array_column($tree, 'title'));
    $this->assertSame(['B', 'C'], array_column($tree[0]['children'], 'title'));
    $this->assertSame([], $tree[0]['children'][0]['children']);
  }

  public function testTreeFieldMustBeASelfReference(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('nodes');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Knoten', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'country', 'type' => 'reference', 'reference' => 'countries'],
      ['name' => 'parent', 'type' => 'reference', 'reference' => $slug],
    ]], $admin);
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}", ['tree_field' => 'country'], $admin)['status']);
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}", ['tree_field' => 'gibtsnicht'], $admin)['status']);
    $this->assertSame('parent', $this->api('PUT', "/admin/entities/{$entity['id']}", ['tree_field' => 'parent'], $admin)['body']['data']['tree_field']);

    // Deleting the parent field ends the tree
    $fields = array_column($entity['fields'], 'id', 'name');
    $this->assertSame(200, $this->api('DELETE', "/admin/entities/{$entity['id']}/fields/{$fields['parent']}", token: $admin)['status']);
    $this->assertNull($this->api('GET', "/admin/entities/{$entity['id']}", token: $admin)['body']['data']['tree_field']);
  }

  public function testImportCannotCloseACircle(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('menu');
    $this->createEntity(['slug' => $slug, 'name' => 'Menü', 'tree_field' => 'parent', 'fields' => [
      ['name' => 'title', 'type' => 'string', 'unique' => true],
      ['name' => 'parent', 'type' => 'reference', 'reference' => $slug],
    ]], $admin);
    $x = $this->createRecord($slug, ['title' => 'X'], $admin);
    $this->createRecord($slug, ['title' => 'Y', 'parent' => $x['id']], $admin);

    // Each row alone is fine, together they are a circle
    $upload = $this->upload('/imports', "title;parent\nX;Y\n", 'menu.csv', $admin)['body']['data'];
    $run = $this->api('POST', "/imports/{$upload['import_id']}/run", ['mode' => 'existing', 'target' => $slug, 'key' => 'title', 'columns' => [
      ['column' => 'title', 'field' => 'title'],
      ['column' => 'parent', 'field' => 'parent', 'match' => 'title'],
    ]], $admin);
    $this->assertSame(422, $run['status'], json_encode($run['body'], JSON_UNESCAPED_UNICODE));
    $this->assertStringContainsString('in a circle', $run['body']['error_data']['tree'][0]);
    $this->assertNull($this->api('GET', "/entities/{$slug}/records/{$x['id']}", token: $admin)['body']['data']['parent']);
  }

  public function testSlugsAreUniquePerLevelAndComeAsPath(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('pages');
    $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'access' => 'public', 'label_field' => 'title', 'tree_field' => 'parent', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'slug', 'type' => 'slug', 'slug_source' => 'title'],
      ['name' => 'parent', 'type' => 'reference', 'reference' => $slug],
    ]], $admin);

    $products = $this->createRecord($slug, ['title' => 'Produkte'], $admin);
    $about = $this->createRecord($slug, ['title' => 'Über uns'], $admin);
    $team = $this->createRecord($slug, ['title' => 'Team', 'parent' => $about['id']], $admin);
    $productTeam = $this->createRecord($slug, ['title' => 'Team', 'parent' => $products['id']], $admin);
    $this->assertSame(['team', 'team'], [$team['slug'], $productTeam['slug']], 'unique per level only');
    $this->assertSame('produkte-2', $this->createRecord($slug, ['title' => 'Produkte'], $admin)['slug'], 'the top level is a level too');
    $this->assertSame('team-2', $this->createRecord($slug, ['title' => 'Team', 'parent' => $about['id']], $admin)['slug']);

    // Moving a record checks its slug on the new level
    $sales = $this->createRecord($slug, ['title' => 'Vertrieb', 'parent' => $about['id']], $admin);
    $moved = $this->api('PUT', "/entities/{$slug}/records/{$team['id']}", ['parent' => $products['id']], $admin)['body']['data'];
    $this->assertSame('team-2', $moved['slug']);
    $this->assertSame('vertrieb', $this->api('PUT', "/entities/{$slug}/records/{$sales['id']}", ['parent' => $products['id']], $admin)['body']['data']['slug']);

    // Content API: the whole path, and records by path
    $list = array_column($this->api('GET', "/main/content/{$slug}?fields=title,slug")['body']['data'], 'slug');
    sort($list);
    $this->assertSame(['produkte', 'produkte-2', 'produkte/team', 'produkte/team-2', 'produkte/vertrieb', 'ueber-uns', 'ueber-uns/team-2'], $list);
    $this->assertSame('produkte/team', $this->api('GET', "/main/content/{$slug}/{$productTeam['id']}")['body']['data']['slug']);
    $tree = $this->api('GET', "/main/content/{$slug}?tree=1&sort=slug&fields=slug")['body']['data'];
    $this->assertSame(['produkte/team', 'produkte/team-2', 'produkte/vertrieb'], array_column($tree[0]['children'], 'slug'), 'sorted by the segment');
    $found = $this->api('GET', "/main/content/{$slug}?filter[slug]=produkte/team")['body']['data'];
    $this->assertSame([$productTeam['id']], array_column($found, 'id'));
    $this->assertSame([], $this->api('GET', "/main/content/{$slug}?filter[slug]=gibts/nicht")['body']['data']);
    $this->assertCount(2, $this->api('GET', "/main/content/{$slug}?filter[slug]=team-2")['body']['data'], 'a single segment as before');
    // The admin app keeps the segment
    $this->assertSame('team', $this->api('GET', "/entities/{$slug}/records/{$productTeam['id']}", token: $admin)['body']['data']['slug']);

    // Without the tree, slugs must be unique in the whole entity
    $entityId = array_column($this->api('GET', '/admin/entities', token: $admin)['body']['data'], 'id', 'slug')[$slug];
    $flat = $this->api('PUT', "/admin/entities/{$entityId}", ['tree_field' => null], $admin);
    $this->assertSame(422, $flat['status']);
    $this->assertStringContainsString('several levels', $flat['body']['error_data']['tree_field'][0]);
    $this->assertSame(200, $this->api('PUT', "/entities/{$slug}/records/{$productTeam['id']}", ['slug' => 'produkt-team'], $admin)['status']);
    $this->assertSame(200, $this->api('PUT', "/entities/{$slug}/records/{$moved['id']}", ['slug' => 'team-alt'], $admin)['status']);
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entityId}", ['tree_field' => null], $admin)['status']);
    $this->assertSame('team', $this->createRecord($slug, ['title' => 'Team'], $admin)['slug']);
    $this->assertSame('team-3', $this->createRecord($slug, ['title' => 'Team'], $admin)['slug'], 'now unique everywhere (team-2 is below "Über uns")');
    $this->assertSame('team-alt', $this->api('GET', "/main/content/{$slug}/{$team['id']}")['body']['data']['slug'], 'no paths without tree');

    // Back to a tree - and deleting the parent field ends it again
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entityId}", ['tree_field' => 'parent'], $admin)['status']);
    $this->assertSame('team', $this->createRecord($slug, ['title' => 'Team', 'parent' => $products['id']], $admin)['slug'], 'free below "Produkte" (the other "team" is at the top)');
    $parentField = array_column($this->api('GET', "/admin/entities/{$entityId}", token: $admin)['body']['data']['fields'], 'id', 'name')['parent'];
    $this->assertSame(422, $this->api('DELETE', "/admin/entities/{$entityId}/fields/{$parentField}", token: $admin)['status'], 'two "team" on different levels');
  }

  public function testTranslatedSlugPaths(): void
  {
    $admin = $this->login();
    $project = 'p'.substr(md5(uniqid('', true)), 0, 8);
    $this->api('POST', '/admin/projects', ['name' => 'Website', 'slug' => $project, 'table_prefix' => $project.'_', 'languages' => ['de', 'en']], $admin);
    $headers = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'pages', 'name' => 'Seiten', 'access' => 'public', 'tree_field' => 'parent', 'fields' => [
      ['name' => 'title', 'type' => 'string', 'translatable' => true],
      ['name' => 'slug', 'type' => 'slug', 'slug_source' => 'title', 'translatable' => true],
      ['name' => 'parent', 'type' => 'reference', 'reference' => 'pages'],
    ]], $admin, $headers);
    $about = $this->api('POST', '/entities/pages/records', ['title' => 'Über uns', '_i18n' => ['title' => ['en' => 'About us']]], $admin, $headers)['body']['data'];
    $team = $this->api('POST', '/entities/pages/records', ['title' => 'Mannschaft', 'parent' => $about['id'], '_i18n' => ['title' => ['en' => 'Team']]], $admin, $headers)['body']['data'];
    // No English title: the German slug stands in
    $history = $this->api('POST', '/entities/pages/records', ['title' => 'Geschichte', 'parent' => $about['id']], $admin, $headers)['body']['data'];

    $slugs = function (string $query) use ($project, $about, $team, $history): array {
      $byId = array_column($this->api('GET', "/{$project}/content/pages?{$query}")['body']['data'], 'slug', 'id');
      return [$byId[$about['id']], $byId[$team['id']], $byId[$history['id']]];
    };
    $this->assertSame(['ueber-uns', 'ueber-uns/mannschaft', 'ueber-uns/geschichte'], $slugs(''));
    $this->assertSame(['about-us', 'about-us/team', 'about-us/geschichte'], $slugs('lang=en'));
    $this->assertSame(['de' => 'ueber-uns/mannschaft', 'en' => 'about-us/team'], $slugs('lang=all')[1]);
    $this->assertSame(['Team'], array_column($this->api('GET', "/{$project}/content/pages?lang=en&filter[slug]=about-us/team")['body']['data'], 'title'));
    $this->assertSame(['Geschichte'], array_column($this->api('GET', "/{$project}/content/pages?lang=en&filter[slug]=about-us/geschichte")['body']['data'], 'title'));
  }
}
