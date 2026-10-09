<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class TranslationTest extends ApiTestCase
{
  public function testTranslatableFieldsAndLanguages(): void
  {
    $admin = $this->login();
    $slug = 'l'.substr(md5(uniqid('', true)), 0, 8);
    $project = $this->api('POST', '/admin/projects', ['name' => 'Website', 'slug' => $slug, 'table_prefix' => $slug.'_', 'languages' => ['de', 'en', 'fr']], $admin)['body']['data'];
    $this->assertSame(['de', 'en', 'fr'], $project['languages']);
    $site = ['X-Project' => $slug];

    $entity = $this->api('POST', '/admin/entities', ['slug' => 'pages', 'name' => 'Seiten', 'access' => 'public', 'label_field' => 'title', 'fields' => [
      ['name' => 'title', 'type' => 'string', 'translatable' => true, 'required' => true],
      ['name' => 'slug', 'type' => 'slug', 'slug_source' => 'title', 'translatable' => true],
      ['name' => 'body', 'type' => 'markdown', 'translatable' => true],
      ['name' => 'code', 'type' => 'string'],
    ]], $admin, $site);
    $this->assertSame(200, $entity['status'], json_encode($entity['body'], JSON_UNESCAPED_UNICODE));
    $entity = $entity['body']['data'];
    $this->assertSame([true, true, true, false], array_column($entity['fields'], 'translatable'));
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'n', 'type' => 'integer', 'translatable' => true], $admin, $site)['status']);

    // Slugs per language from the title in the same language
    $about = $this->api('POST', '/entities/pages/records', ['title' => 'Über uns', 'code' => 'A', '_i18n' => ['title' => ['en' => 'About us', 'fr' => 'À propos']]], $admin, $site)['body']['data'];
    $this->assertSame(['ueber-uns', 'about-us', 'a-propos'], [$about['slug'], $about['_i18n']['slug']['en'], $about['_i18n']['slug']['fr']]);
    $second = $this->api('POST', '/entities/pages/records', ['title' => 'Team', '_i18n' => ['title' => ['en' => 'About us']]], $admin, $site)['body']['data'];
    $this->assertSame(['team', 'about-us-2', null], [$second['slug'], $second['_i18n']['slug']['en'], $second['_i18n']['slug']['fr']]);

    // Content API: one language (empty ones fall back to the default), or all
    $en = array_column($this->api('GET', "/{$slug}/content/pages?lang=en&sort=title")['body']['data'], null, 'code');
    $this->assertSame(['About us', 'about-us'], [$en['A']['title'], $en['A']['slug']]);
    $fr = $this->api('GET', "/{$slug}/content/pages/{$second['id']}?lang=fr")['body']['data'];
    $this->assertSame(['Team', 'team'], [$fr['title'], $fr['slug']]);
    $all = $this->api('GET', "/{$slug}/content/pages/{$about['id']}?lang=all")['body']['data'];
    $this->assertSame(['de' => 'Über uns', 'en' => 'About us', 'fr' => 'À propos'], $all['title']);
    $this->assertSame('A', $all['code']);
    $this->assertSame(422, $this->api('GET', "/{$slug}/content/pages?lang=it")['status']);
    // Filter in a language
    $this->assertSame([$about['id']], array_column($this->api('GET', "/{$slug}/content/pages?lang=en&filter[slug]=about-us")['body']['data'], 'id'));

    // Only sent languages change
    $updated = $this->api('PUT', "/entities/pages/records/{$about['id']}", ['_i18n' => ['title' => ['fr' => 'Qui sommes-nous']]], $admin, $site)['body']['data'];
    $this->assertSame(['About us', 'Qui sommes-nous', 'a-propos'], [$updated['_i18n']['title']['en'], $updated['_i18n']['title']['fr'], $updated['_i18n']['slug']['fr']]);
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$entity['fields'][0]['id']}", ['type' => 'text'], $admin, $site)['status']);

    // New default language: English moves into the fields' own columns
    $this->assertSame(200, $this->api('PUT', "/admin/projects/{$project['id']}", ['languages' => ['en', 'de', 'it']], $admin)['status']);
    $default = $this->api('GET', "/{$slug}/content/pages/{$about['id']}")['body']['data'];
    $this->assertSame(['About us', 'about-us'], [$default['title'], $default['slug']]);
    $all = $this->api('GET', "/{$slug}/content/pages/{$about['id']}?lang=all")['body']['data'];
    $this->assertSame(['en' => 'About us', 'de' => 'Über uns', 'it' => null], $all['title']);
    $this->assertSame(422, $this->api('PUT', "/admin/projects/{$project['id']}", ['languages' => ['es', 'en']], $admin)['status']);

    // Not translatable any more: the other languages are gone
    $bodyField = array_column($entity['fields'], 'id', 'name')['body'];
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$bodyField}", ['translatable' => false], $admin, $site)['status']);
    $record = $this->api('GET', "/entities/pages/records/{$about['id']}", token: $admin, headers: $site)['body']['data'];
    $this->assertArrayNotHasKey('body', $record['_i18n']);
  }
}
