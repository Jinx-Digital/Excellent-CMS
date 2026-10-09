<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class GroupTest extends ApiTestCase
{
  public function testGroupsInEntitiesAndGroups(): void
  {
    $admin = $this->login();
    $suffix = substr(md5(uniqid('', true)), 0, 6);

    // seo = keywords (list) + description; teaser = title + image + seo (a group in a group)
    $seo = $this->api('POST', '/admin/groups', ['name' => 'seo_'.$suffix, 'label' => 'SEO', 'fields' => [
      ['name' => 'keywords', 'type' => 'string', 'repeatable' => true, 'repeat_max' => 5],
      ['name' => 'description', 'type' => 'string', 'length' => 160, 'required' => true],
    ]], $admin);
    $this->assertSame(200, $seo['status'], json_encode($seo['body'], JSON_UNESCAPED_UNICODE));
    $seo = $seo['body']['data'];
    $teaser = $this->api('POST', '/admin/groups', ['name' => 'teaser_'.$suffix, 'label' => 'Teaser', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'image', 'type' => 'media'],
      ['name' => 'link', 'type' => 'url'],
      ['name' => 'seo', 'type' => 'group', 'group' => $seo['id']],
    ]], $admin)['body']['data'];
    $this->assertSame('description', $teaser['fields'][3]['group']['fields'][1]['name']);

    // A group cannot contain itself, not even through another group
    $this->assertSame(422, $this->api('POST', "/admin/groups/{$seo['id']}/fields", ['name' => 'teaser', 'type' => 'group', 'group' => $teaser['id']], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/groups/{$seo['id']}/fields", ['name' => 'nr', 'type' => 'autoincrement'], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/groups/{$seo['id']}/fields", ['name' => 't', 'type' => 'string', 'translatable' => true], $admin)['status']);

    // The same group in two entities; the teaser repeatable and sortable
    $pages = $this->createEntity(['slug' => 'pages_'.$suffix, 'name' => 'Seiten', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'seo', 'type' => 'group', 'group' => 'seo_'.$suffix],
      ['name' => 'teasers', 'type' => 'group', 'group' => $teaser['id'], 'repeatable' => true, 'repeat_max' => 3],
    ]], $admin);
    $this->createEntity(['slug' => 'news_'.$suffix, 'name' => 'News', 'fields' => [
      ['name' => 'headline', 'type' => 'string'],
      ['name' => 'seo', 'type' => 'group', 'group' => $seo['id']],
    ]], $admin);

    $image = $this->upload('/media', self::png(), 'teaser.png', $admin)['body']['data'];
    $record = $this->api('POST', "/entities/pages_{$suffix}/records", [
      'title' => 'Start',
      'seo' => ['keywords' => ['cms', 'headless'], 'description' => 'Ein CMS'],
      'teasers' => [
        ['title' => 'Eins', 'image' => $image['id'], 'link' => 'https://example.com/eins', 'seo' => ['description' => 'Unterseite']],
        ['title' => 'Zwei'],
      ],
    ], $admin);
    $this->assertSame(200, $record['status'], json_encode($record['body'], JSON_UNESCAPED_UNICODE));
    $data = $record['body']['data'];
    $this->assertSame(['keywords' => ['cms', 'headless'], 'description' => 'Ein CMS'], $data['seo']);
    $this->assertSame(['Eins', 'Zwei'], array_column($data['teasers'], 'title'));
    $this->assertSame($image['url'], $data['teasers'][0]['image']['url']);
    $this->assertSame('Unterseite', $data['teasers'][0]['seo']['description']);

    // Fields inside groups are checked; problems name their path
    $invalid = $this->api('POST', "/entities/pages_{$suffix}/records", ['seo' => ['keywords' => ['a']], 'teasers' => [['title' => 'a', 'seo' => ['description' => str_repeat('x', 200)]], ['title' => 'b'], ['title' => 'c'], ['title' => 'd']]], $admin)['body']['error_data'];
    $this->assertStringContainsString('Description: Please fill in', $invalid['seo'][0]);
    $this->assertStringContainsString('Seo › Description: At most 160 characters', implode(' ', $invalid['teasers']));
    $this->assertStringContainsString('At most 3 items', implode(' ', $invalid['teasers']));

    // Content API, and a new field in the group shows up everywhere at once
    $this->api('POST', "/admin/groups/{$seo['id']}/fields", ['name' => 'robots', 'type' => 'string'], $admin);
    $content = $this->api('GET', "/main/content/pages_{$suffix}")['body']['data'][0];
    $this->assertSame(['keywords', 'description', 'robots'], array_keys($content['seo']));
    $this->assertSame('teaser.png', $content['teasers'][0]['image']['name']);

    // Files used inside groups are in use
    $library = $this->api('GET', "/media/{$image['id']}", token: $admin)['body']['data'];
    $this->assertSame(1, $library['usage_count']);
    $this->assertSame('teasers', $library['usages'][0]['field']);

    // A group in use cannot be deleted, a field cannot change into a group
    $this->assertSame(409, $this->api('DELETE', "/admin/groups/{$seo['id']}", token: $admin)['status']);
    $title = array_column($pages['fields'], 'id', 'name')['title'];
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$pages['id']}/fields/{$title}", ['type' => 'group', 'group' => $seo['id']], $admin)['status']);
  }

  public function testTranslatableGroups(): void
  {
    $admin = $this->login();
    $slug = 'g'.substr(md5(uniqid('', true)), 0, 8);
    $this->api('POST', '/admin/projects', ['name' => 'Mehrsprachig', 'slug' => $slug, 'table_prefix' => $slug.'_', 'languages' => ['de', 'en']], $admin);
    $site = ['X-Project' => $slug];
    $this->api('PUT', '/admin/variables', ['variables' => [['name' => 'url', 'translatable' => true, 'value' => 'https://example.de', 'translations' => ['en' => 'https://example.com']]]], $admin, $site);
    $seo = $this->api('POST', '/admin/groups', ['name' => 'seo', 'label' => 'SEO', 'fields' => [['name' => 'description', 'type' => 'string'], ['name' => 'canonical', 'type' => 'url']]], $admin, $site)['body']['data'];
    $created = $this->api('POST', '/admin/entities', ['slug' => 'pages', 'name' => 'Seiten', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'seo', 'type' => 'group', 'group' => $seo['id'], 'translatable' => true],
    ]], $admin, $site);
    $this->assertSame(200, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

    $this->api('POST', '/entities/pages/records', ['title' => 'Start', 'seo' => ['description' => 'Willkommen', 'canonical' => '{{url}}/start'], '_i18n' => ['seo' => ['en' => ['description' => 'Welcome', 'canonical' => '{{url}}/home']]]], $admin, $site);
    $this->assertSame(['description' => 'Welcome', 'canonical' => 'https://example.com/home'], $this->api('GET', "/{$slug}/content/pages?lang=en")['body']['data'][0]['seo']);
    $all = $this->api('GET', "/{$slug}/content/pages?lang=all")['body']['data'][0]['seo'];
    $this->assertSame(['Willkommen', 'Welcome'], [$all['de']['description'], $all['en']['description']]);
    $this->assertSame('https://example.de/start', $all['de']['canonical']);
  }

  public function testFieldsBecomeAGroup(): void
  {
    $admin = $this->login();
    $suffix = substr(md5(uniqid('', true)), 0, 6);
    $articles = $this->createEntity(['slug' => 'articles_'.$suffix, 'name' => 'Artikel', 'access' => 'public', 'label_field' => 'title', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'keywords', 'type' => 'string', 'repeatable' => true],
      ['name' => 'description', 'type' => 'text'],
      ['name' => 'body', 'type' => 'markdown'],
    ]], $admin);
    $record = $this->createRecord('articles_'.$suffix, ['title' => 'A', 'keywords' => ['x', 'y'], 'description' => 'Kurz', 'body' => '# Text'], $admin);
    $this->createRecord('articles_'.$suffix, ['title' => 'B'], $admin);

    $converted = $this->api('POST', "/admin/entities/{$articles['id']}/fields/group", ['fields' => ['keywords', 'description'], 'group_name' => 'meta_'.$suffix, 'group_label' => 'Meta', 'name' => 'meta'], $admin);
    $this->assertSame(200, $converted['status'], json_encode($converted['body'], JSON_UNESCAPED_UNICODE));
    // The group field takes the place of the first field
    $this->assertSame(['title', 'meta', 'body'], array_column($converted['body']['data']['fields'], 'name'));

    $content = array_column($this->api('GET', "/main/content/articles_{$suffix}?sort=title")['body']['data'], null, 'title');
    $this->assertSame(['keywords' => ['x', 'y'], 'description' => 'Kurz'], $content['A']['meta']);
    $this->assertNull($content['B']['meta']);
    $this->assertSame('# Text', $content['A']['body']);

    // Another entity with the same fields joins the existing group, keeping its values
    $news = $this->createEntity(['slug' => 'news_'.$suffix, 'name' => 'News', 'access' => 'public', 'fields' => [
      ['name' => 'headline', 'type' => 'string'],
      ['name' => 'description', 'type' => 'text'],
    ]], $admin);
    $this->createRecord('news_'.$suffix, ['headline' => 'N', 'description' => 'Neu'], $admin);
    $joined = $this->api('POST', "/admin/entities/{$news['id']}/fields/group", ['fields' => ['description'], 'group' => 'meta_'.$suffix, 'name' => 'meta'], $admin);
    $this->assertSame(200, $joined['status'], json_encode($joined['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['keywords' => [], 'description' => 'Neu'], $this->api('GET', "/main/content/news_{$suffix}")['body']['data'][0]['meta']);

    // Fields that do not fit the group, or into groups at all
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$news['id']}/fields/group", ['fields' => ['headline'], 'group' => 'meta_'.$suffix, 'name' => 'm2'], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$articles['id']}/fields/group", ['fields' => ['title'], 'group_name' => 'x_'.$suffix, 'name' => 'title'], $admin)['status']);
    $this->assertSame(200, $this->api('GET', "/entities/articles_{$suffix}/records/{$record['id']}", token: $admin)['status']);
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }

  public function testBlocksAndFieldGroupsApart(): void
  {
    $admin = $this->login();
    $suffix = bin2hex(random_bytes(3));
    $seo = $this->api('POST', '/admin/groups', ['name' => 'kseo_'.$suffix, 'label' => 'SEO', 'fields' => [['name' => 'description', 'type' => 'string']]], $admin)['body']['data'];
    $hero = $this->api('POST', '/admin/groups', ['name' => 'khero_'.$suffix, 'label' => 'Hero', 'kind' => 'block', 'category' => ' Kopf ', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin)['body']['data'];
    $this->assertSame(['group', 'block'], [$seo['kind'], $hero['kind']]);
    // Categories organize them: trimmed, empty = none, at most 60 characters
    $this->assertSame(['Kopf', null], [$hero['category'], $seo['category']]);
    $this->assertNull($this->api('PUT', "/admin/groups/{$hero['id']}", ['category' => ''], $admin)['body']['data']['category']);
    $this->assertSame(422, $this->api('PUT', "/admin/groups/{$hero['id']}", ['category' => str_repeat('x', 61)], $admin)['status']);
    $this->assertSame('Inhalt', $this->api('PUT', "/admin/groups/{$hero['id']}", ['category' => 'Inhalt'], $admin)['body']['data']['category']);
    $kinds = static fn(array $list): array => array_unique(array_column($list, 'kind'));
    $this->assertSame(['block'], array_values($kinds($this->api('GET', '/admin/groups?kind=block', token: $admin)['body']['data'])));
    $this->assertSame(['group'], array_values($kinds($this->api('GET', '/admin/groups?kind=group', token: $admin)['body']['data'])));

    // Blocks only in block lists, field groups only in group fields
    $slug = $this->uniqueSlug('kinds');
    $wrong = $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Seiten', 'fields' => [
      ['name' => 'content', 'type' => 'group', 'blocks' => [$seo['id']]],
      ['name' => 'seo', 'type' => 'group', 'group' => $hero['id']],
    ]], $admin);
    $this->assertSame(422, $wrong['status']);
    $this->assertSame(['fields.0.blocks', 'fields.1.group'], array_keys($wrong['body']['error_data']));
    $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'fields' => [
      ['name' => 'content', 'type' => 'group', 'blocks' => [$hero['id']]],
      ['name' => 'seo', 'type' => 'group', 'group' => $seo['id']],
    ]], $admin);
    // A block may hold field groups
    $this->assertSame(200, $this->api('POST', "/admin/groups/{$hero['id']}/fields", ['name' => 'meta', 'type' => 'group', 'group' => $seo['id']], $admin)['status']);

    // The kind changes only while nothing uses it
    $this->assertSame(422, $this->api('PUT', "/admin/groups/{$seo['id']}", ['kind' => 'block'], $admin)['status']);
    $unused = $this->api('POST', '/admin/groups', ['name' => 'kfree_'.$suffix, 'label' => 'Frei', 'fields' => [['name' => 'x', 'type' => 'string']]], $admin)['body']['data'];
    $this->assertSame('block', $this->api('PUT', "/admin/groups/{$unused['id']}", ['kind' => 'block'], $admin)['body']['data']['kind']);
  }
}
