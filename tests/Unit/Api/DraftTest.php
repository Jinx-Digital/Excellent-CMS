<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Drafts: visible and editable in the admin app, unknown to the content API until published.
 */
class DraftTest extends ApiTestCase
{
  public function testDraftsStayOutOfTheContentApi(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('news');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'News', 'access' => 'public', 'drafts' => true, 'fields' => [
      ['name' => 'title', 'type' => 'string', 'required' => true],
      ['name' => 'text', 'type' => 'text', 'required' => true],
      ['name' => 'image', 'type' => 'media'],
    ]], $admin);
    $this->assertTrue($entity['drafts']);

    // A draft may leave required fields empty; published records may not
    $this->assertSame(422, $this->api('POST', "/entities/{$slug}/records", ['title' => 'Ohne Text'], $admin)['status']);
    $draft = $this->createRecord($slug, ['title' => 'Entwurf', 'draft' => true], $admin);
    $this->assertTrue($draft['draft']);
    $published = $this->createRecord($slug, ['title' => 'Online', 'text' => 'Hallo'], $admin);
    $this->assertFalse($published['draft']);

    // Admin app: both, filterable
    $this->assertSame(['Entwurf', 'Online'], array_column($this->api('GET', "/entities/{$slug}/records?sort=title", token: $admin)['body']['data'], 'title'));
    $filtered = $this->api("GET", "/entities/{$slug}/records?filter[draft]=1", token: $admin);
    $this->assertSame(200, $filtered["status"], json_encode($filtered["body"], JSON_UNESCAPED_UNICODE));
    $this->assertSame(["Entwurf"], array_column($filtered["body"]["data"], "title"));

    // Content API: no drafts, not even by id
    $this->assertSame(['Online'], array_column($this->api('GET', "/main/content/{$slug}")['body']['data'], 'title'));
    $this->assertSame(404, $this->api('GET', "/main/content/{$slug}/{$draft['id']}")['status']);
    $this->assertArrayNotHasKey('draft', $this->api('GET', "/main/content/{$slug}/{$published['id']}")['body']['data']);

    // Publishing checks the required fields that were not sent
    $refused = $this->api('PUT', "/entities/{$slug}/records/{$draft['id']}", ['draft' => false], $admin);
    $this->assertSame(422, $refused['status']);
    $this->assertArrayHasKey('text', $refused['body']['error_data']);
    $this->assertSame(200, $this->api('PUT', "/entities/{$slug}/records/{$draft['id']}", ['draft' => false, 'text' => 'Fertig'], $admin)['status']);
    $this->assertSame(['Entwurf', 'Online'], array_column($this->api('GET', "/main/content/{$slug}?sort=title")['body']['data'], 'title'));

    // Back to draft: gone from the API again; files of drafts are not public
    $image = $this->upload('/media', self::png(), 'bild.png', $admin)['body']['data'];
    $this->api('PUT', "/entities/{$slug}/records/{$published['id']}", ['draft' => true, 'image' => $image['id']], $admin);
    $this->assertSame(['Entwurf'], array_column($this->api('GET', "/main/content/{$slug}")['body']['data'], 'title'));
    $this->assertSame(403, $this->fetch(strtok($image['url'], '?'))['status']);

    // Drafts cannot be switched off while there are some
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}", ['drafts' => false], $admin)['status']);
    $this->api('PUT', "/entities/{$slug}/records/{$published['id']}", ['draft' => false], $admin);
    $off = $this->api('PUT', "/admin/entities/{$entity['id']}", ['drafts' => false], $admin);
    $this->assertSame(200, $off['status'], json_encode($off['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(2, count($this->api('GET', "/main/content/{$slug}")['body']['data']));
  }

  public function testWorkingCopiesKeepTheLiveVersion(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('pages');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Seiten', 'access' => 'public', 'drafts' => true, 'revisions' => true, 'fields' => [
      ['name' => 'title', 'type' => 'string', 'required' => true],
      ['name' => 'text', 'type' => 'text', 'required' => true],
      ['name' => 'image', 'type' => 'media'],
    ]], $admin);
    $page = $this->createRecord($slug, ['title' => 'Live', 'text' => 'Alt'], $admin);
    $this->assertNull($page['_working_copy']);
    $image = $this->upload('/media', self::png(), 'neu.png', $admin)['body']['data'];

    // Saved for later: the admin app sees the working copy, the content API still the live version
    $saved = $this->api('PUT', "/entities/{$slug}/records/{$page['id']}/working-copy", ['title' => 'Live', 'text' => '', 'image' => $image['id']], $admin);
    $this->assertSame(200, $saved['status'], json_encode($saved['body'], JSON_UNESCAPED_UNICODE));
    $record = $saved['body']['data'];
    $this->assertSame('Alt', $record['text']);
    $this->assertSame(['', $image['id']], [$record['_working_copy']['text'] ?? '', $record['_working_copy']['image']['id']], 'required fields may stay empty in a working copy');
    $this->assertSame('Alt', $this->api('GET', "/main/content/{$slug}/{$page['id']}")['body']['data']['text']);
    $this->assertSame([true], array_column($this->api('GET', "/entities/{$slug}/records", token: $admin)['body']['data'], '_working_copy'));
    // Working on: the copy is replaced, still nothing live; no revisions for it
    $this->api('PUT', "/entities/{$slug}/records/{$page['id']}/working-copy", ['text' => 'Neu'], $admin);
    $this->assertSame('Neu', $this->api('GET', "/entities/{$slug}/records/{$page['id']}", token: $admin)['body']['data']['_working_copy']['text']);
    $this->assertCount(1, $this->api('GET', "/entities/{$slug}/records/{$page['id']}/revisions", token: $admin)['body']['data']);
    // Its files are not removed by the cleanup of unused files
    $this->assertNotContains($image['id'], array_column($this->container()->get(\App\Repository\MediaRepository::class)->unused(date('Y-m-d H:i:s', time() + 60)), 'id'));

    // Publishing checks like saving and makes it live; the working copy is gone
    $this->assertSame(422, $this->api('POST', "/entities/{$slug}/records/{$page['id']}/working-copy/publish", ['text' => ''], $admin)['status']);
    $published = $this->api('POST', "/entities/{$slug}/records/{$page['id']}/working-copy/publish", ['text' => 'Neu', 'image' => $image['id']], $admin)['body']['data'];
    $this->assertSame(['Neu', null, false], [$published['text'], $published['_working_copy'], $published['draft']]);
    $this->assertSame('Neu', $this->api('GET', "/main/content/{$slug}/{$page['id']}")['body']['data']['text']);

    // Discarding keeps the live version
    $this->api('PUT', "/entities/{$slug}/records/{$page['id']}/working-copy", ['title' => 'Verworfen'], $admin);
    $discarded = $this->api('DELETE', "/entities/{$slug}/records/{$page['id']}/working-copy", token: $admin)['body']['data'];
    $this->assertSame(['Live', null], [$discarded['title'], $discarded['_working_copy']]);

    // Drafts are not live: they have no working copy; editors need the update permission
    $draft = $this->createRecord($slug, ['title' => 'Entwurf', 'draft' => true], $admin);
    $this->assertSame(409, $this->api('PUT', "/entities/{$slug}/records/{$draft['id']}/working-copy", ['title' => 'X'], $admin)['status']);
    $this->assertSame(403, $this->api('PUT', "/entities/{$slug}/records/{$page['id']}/working-copy", ['title' => 'X'], $this->editorToken())['status']);

    // Back to draft: the working copy is discarded
    $this->api('PUT', "/entities/{$slug}/records/{$page['id']}/working-copy", ['title' => 'Kopie'], $admin);
    $this->assertNull($this->api('PUT', "/entities/{$slug}/records/{$page['id']}", ['draft' => true], $admin)['body']['data']['_working_copy']);
    $this->api('PUT', "/entities/{$slug}/records/{$page['id']}", ['draft' => false], $admin);

    // Entities without drafts: every record is live, working copies work the same
    $plain = $this->createEntity(['slug' => $this->uniqueSlug('plain'), 'name' => 'Ohne Entwürfe', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $item = $this->createRecord($plain['slug'], ['title' => 'Live'], $admin);
    $saved = $this->api('PUT', "/entities/{$plain['slug']}/records/{$item['id']}/working-copy", ['title' => 'Später'], $admin);
    $this->assertSame(200, $saved['status'], json_encode($saved['body'], JSON_UNESCAPED_UNICODE));
    $copy = $saved['body']['data'];
    $this->assertSame(['Live', 'Später'], [$copy['title'], $copy['_working_copy']['title']]);
    $this->assertSame('Später', $this->api('POST', "/entities/{$plain['slug']}/records/{$item['id']}/working-copy/publish", ['title' => 'Später'], $admin)['body']['data']['title']);

    // Deleted with its record
    $this->api('PUT', "/entities/{$slug}/records/{$page['id']}/working-copy", ['title' => 'Weg'], $admin);
    $this->api('DELETE', "/entities/{$slug}/records/{$page['id']}", token: $admin);
    // Other tests count the unused files
    $this->assertSame(200, $this->api('DELETE', "/media/{$image['id']}", token: $admin)['status']);
    $this->assertFalse($this->container()->get(\Yiisoft\Db\Connection\ConnectionInterface::class)->createQuery()->from('working_copy')->where(['record_id' => $page['id']])->exists());
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
