<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Tabs of the record form (schema › form designer): every field in exactly one tab, new fields in
 * the first, renamed fields stay in theirs, one tab = no design. The fields stay columns.
 */
class FormTabsTest extends ApiTestCase
{
  public function testTabsArrangeTheFields(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('tabbed');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'fields' => [
      ['name' => 'title', 'type' => 'string'], ['name' => 'body', 'type' => 'text'], ['name' => 'seo_title', 'type' => 'string'], ['name' => 'seo_text', 'type' => 'text'],
    ]], $admin);
    // Without a design: one tab with every field
    $this->assertSame([['key' => 'main', 'label' => '', 'fields' => ['title', 'body', 'seo_title', 'seo_text']]], $entity['tabs']);

    $bad = $this->api('PUT', "/admin/entities/{$entity['id']}", ['tabs' => [['label' => 'Inhalt', 'fields' => ['title', 'nope']], ['label' => '', 'fields' => ['title']]]], $admin);
    $this->assertSame(422, $bad['status']);
    $this->assertSame(['tabs.0', 'tabs.1'], array_keys($bad['body']['error_data']));

    // Fields no tab names go into the first
    $saved = $this->api('PUT', "/admin/entities/{$entity['id']}", ['tabs' => [['key' => 'content', 'label' => 'Inhalt', 'fields' => ['body']], ['key' => 'seo', 'label' => 'SEO', 'fields' => ['seo_title', 'seo_text']]]], $admin)['body']['data'];
    $this->assertSame([['content', ['body', 'title']], ['seo', ['seo_title', 'seo_text']]], array_map(static fn(array $tab): array => [$tab['key'], $tab['fields']], $saved['tabs']));

    // A new field: first tab; a renamed one stays in its tab; a deleted one is gone
    $this->assertSame(200, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'teaser', 'type' => 'string'], $admin)['status']);
    $fields = array_column($this->api('GET', "/admin/entities/{$entity['id']}", token: $admin)['body']['data']['fields'], null, 'name');
    $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['seo_text']['id']}", ['name' => 'seo_description'], $admin);
    $this->api('DELETE', "/admin/entities/{$entity['id']}/fields/{$fields['seo_title']['id']}", token: $admin);
    $tabs = $this->api('GET', "/admin/entities/{$entity['id']}", token: $admin)['body']['data']['tabs'];
    $this->assertSame([['body', 'title', 'teaser'], ['seo_description']], array_column($tabs, 'fields'));
    // The record editor gets them too
    $this->assertSame(['Inhalt', 'SEO'], array_column($this->api('GET', "/entities/{$slug}", token: $admin)['body']['data']['tabs'], 'label'));

    // One tab without a name: back to the plain form
    $this->api('PUT', "/admin/entities/{$entity['id']}", ['tabs' => []], $admin);
    $this->assertCount(1, $this->api('GET', "/admin/entities/{$entity['id']}", token: $admin)['body']['data']['tabs']);
  }
}
