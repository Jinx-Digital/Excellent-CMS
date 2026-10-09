<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class SlugMarkdownTest extends ApiTestCase
{
  public function testSlugsAreCleanAndUnique(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('articles');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Artikel', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'path', 'type' => 'slug', 'slug_source' => 'title'],
      ['name' => 'body', 'type' => 'markdown'],
    ]], $admin);
    $fields = array_column($entity['fields'], null, 'name');
    $this->assertSame(['title', true], [$fields['path']['slug_source'], $fields['path']['unique']]);

    // Made from the title, taken ones get a number
    $this->assertSame('ueber-uns-team', $this->createRecord($slug, ['title' => 'Über uns & Team!'], $admin)['path']);
    $home = $this->createRecord($slug, ['title' => 'Home'], $admin);
    $this->assertSame('home', $home['path']);
    $this->assertSame('home-2', $this->createRecord($slug, ['title' => 'Home'], $admin)['path']);
    // Entered ones are cleaned up and made unique too
    $this->assertSame('home-3', $this->createRecord($slug, ['title' => 'Start', 'path' => 'HOME'], $admin)['path']);
    $this->assertSame('mein-eigener-slug', $this->createRecord($slug, ['title' => 'X', 'path' => 'Mein eigener Slug'], $admin)['path']);

    // Changing the title keeps the slug; clearing the slug makes it again
    $changed = $this->api('PUT', "/entities/{$slug}/records/{$home['id']}", ['title' => 'Startseite'], $admin)['body']['data'];
    $this->assertSame('home', $changed['path']);
    $cleared = $this->api('PUT', "/entities/{$slug}/records/{$home['id']}", ['path' => ''], $admin)['body']['data'];
    $this->assertSame('startseite', $cleared['path']);
    // Saving it unchanged does not add a number
    $this->assertSame('startseite', $this->api('PUT', "/entities/{$slug}/records/{$home['id']}", ['path' => 'startseite'], $admin)['body']['data']['path']);

    $this->assertSame(422, $this->api('POST', "/entities/{$slug}/records", ['path' => '!!!'], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'p2', 'type' => 'slug', 'slug_source' => 'gibtsnicht'], $admin)['status']);

    $markdown = "# Titel\n\nMit **fett** und [Link](https://example.com).";
    $record = $this->createRecord($slug, ['title' => 'Markdown', 'body' => $markdown], $admin);
    $this->assertSame($markdown, $record['body']);
    $this->assertSame(1, $this->api('GET', "/entities/{$slug}/records?s=fett", token: $admin)['body']['meta']['total_items']);
  }

  public function testNewSlugFieldFillsExistingRecordsAndImport(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('teams');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Teams', 'fields' => [['name' => 'name', 'type' => 'string']]], $admin);
    foreach (['Rot', 'Blau', 'Rot'] as $name) {
      $this->createRecord($slug, ['name' => $name], $admin);
    }
    $this->assertSame(200, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'slug', 'type' => 'slug', 'slug_source' => 'name'], $admin)['status']);
    $slugs = array_column($this->api('GET', "/entities/{$slug}/records?sort=slug", token: $admin)['body']['data'], 'slug');
    $this->assertSame(['blau', 'rot', 'rot-2'], $slugs);

    // Import: empty slugs come from the name, taken ones get a number
    $upload = $this->upload('/imports', "name;slug\nRot;\nGrün;\nGelb;gelb\nGelb;gelb\n", 'teams.csv', $admin)['body']['data'];
    $run = $this->api('POST', "/imports/{$upload['import_id']}/run", ['mode' => 'existing', 'target' => $slug, 'columns' => [
      ['column' => 'name', 'field' => 'name'],
      ['column' => 'slug', 'field' => 'slug'],
    ]], $admin);
    $this->assertSame(200, $run['status'], json_encode($run['body'], JSON_UNESCAPED_UNICODE));
    $slugs = array_column($this->api('GET', "/entities/{$slug}/records?sort=slug", token: $admin)['body']['data'], 'slug');
    $this->assertSame(['blau', 'gelb', 'gelb-2', 'gruen', 'rot', 'rot-2', 'rot-3'], $slugs);

    // Detected by name and values
    $columns = $this->upload('/imports', "title;page_slug\nA;ueber-uns\nB;team-2\n", 'x.csv', $admin)['body']['data']['columns'];
    $this->assertSame(['slug', true], [$columns[1]['suggestion']['type'], $columns[1]['suggestion']['unique']]);
  }
}
