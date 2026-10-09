<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Application\Media\MediaService;
use App\Tests\Support\ApiTestCase;

class MediaTest extends ApiTestCase
{
  private const ROOT = __DIR__.'/../../../runtime/test-media';

  public function testUploadAndUseInRecords(): void
  {
    $admin = $this->login();
    $image = $this->upload('/media', self::png(40, 30), 'Logo Firma.png', $admin);
    $this->assertSame(200, $image['status'], json_encode($image['body'], JSON_UNESCAPED_UNICODE));
    $file = $image['body']['data'];
    $this->assertSame(['Logo Firma.png', 'image/png', 40, 30, true], [$file['name'], $file['mime_type'], $file['width'], $file['height'], $file['is_image']]);
    // Not used by a public entity yet: a signed address
    $this->assertMatchesRegularExpression('#^http://localhost/media/main/\d{4}/\d{2}/[1-9A-Za-z]{21,22}\.png\?expires=\d+&signature=[0-9a-f]{64}$#', $file['url']);
    $this->assertFileExists(self::ROOT.self::path($file['url']));

    $text = $this->upload('/media', "Hallo\nWelt\n", 'notiz.txt', $admin)['body']['data'];
    $this->assertSame(['text/plain', false], [$text['mime_type'], $text['is_image']]);

    $entity = $this->createEntity(['slug' => $this->uniqueSlug('partners'), 'name' => 'Partner', 'access' => 'public', 'fields' => [
      ['name' => 'name', 'type' => 'string'],
      ['name' => 'logo', 'type' => 'media', 'media_accept' => ['image/*']],
      ['name' => 'contract', 'type' => 'media'],
    ]], $admin);
    $this->assertSame([['image/*'], []], array_column(array_slice($entity['fields'], 1), 'media_accept'));

    $record = $this->createRecord($entity['slug'], ['name' => 'ACME', 'logo' => $file['id'], 'contract' => $text['id']], $admin);
    $this->assertSame(self::path($file['url']), self::path($record['logo']['url']));
    $this->assertSame('notiz.txt', $record['contract']['name']);

    // The admin app sends the file object back unchanged
    $saved = $this->api('PUT', "/entities/{$entity['slug']}/records/{$record['id']}", ['logo' => $record['logo']], $admin);
    $this->assertSame(200, $saved['status'], json_encode($saved['body'], JSON_UNESCAPED_UNICODE));

    $content = $this->api('GET', "/main/content/{$entity['slug']}")['body']['data'][0];
    $this->assertSame(['id', 'url', 'name', 'mime_type', 'size', 'width', 'height', 'is_image', 'transform_url', 'focal_point'], array_keys($content['logo']));

    $invalid = $this->api('POST', "/entities/{$entity['slug']}/records", ['logo' => $text['id'], 'contract' => 'gibtsnicht'], $admin);
    $this->assertSame(422, $invalid['status']);
    $this->assertStringContainsString('all images', $invalid['body']['error_data']['logo'][0]);
    $this->assertStringContainsString('does not exist', $invalid['body']['error_data']['contract'][0]);

    // Files in use stay, unused ones go
    $unused = $this->upload('/media', self::png(2, 2), 'weg.png', $admin)['body']['data'];
    // At least this one (tests share the database: others may have left unused files)
    $this->assertGreaterThanOrEqual(1, $this->cleanup());
    $this->assertFileDoesNotExist(self::ROOT.self::path($unused['url']));
    $this->assertFileExists(self::ROOT.self::path($file['url']));

    $this->assertSame(200, $this->api('DELETE', "/entities/{$entity['slug']}/records/{$record['id']}", token: $admin)['status']);
    $this->assertSame(2, $this->cleanup());
    $this->assertFileDoesNotExist(self::ROOT.self::path($file['url']));
  }

  public function testRejectsScriptsAndOtherTypes(): void
  {
    $admin = $this->login();
    foreach (['shell.php' => "<?php system(\$_GET['c']);", 'page.html' => '<html><script>alert(1)</script></html>', 'bild.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'] as $name => $content) {
      $result = $this->upload('/media', $content, $name, $admin);
      $this->assertSame(422, $result['status'], $name);
      $this->assertSame('media_type', $result['body']['error_code'], $name);
    }
    $this->assertSame(400, $this->upload('/media', str_repeat('x', 1024 * 1024 + 1), 'gross.txt', $admin)['status']);
    $this->assertSame(401, $this->upload('/media', self::png(1, 1), 'a.png', 'falsch')['status']);
  }

  public function testMediaFieldsCannotChangeTypeOrBeImported(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('docs'), 'name' => 'Dokumente', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'file', 'type' => 'media'],
    ]], $admin);
    $fields = array_column($entity['fields'], 'id', 'name');
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['file']}", ['type' => 'string'], $admin)['status']);
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['title']}", ['type' => 'media'], $admin)['status']);
    $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'f2', 'type' => 'media', 'unique' => true], $admin)['status']);

    $upload = $this->upload('/imports', "title;file\nA;x.png\n", 'docs.csv', $admin)['body']['data'];
    $preview = $this->api('POST', "/imports/{$upload['import_id']}/preview", ['mode' => 'existing', 'target' => $entity['slug'], 'columns' => [
      ['column' => 'title', 'field' => 'title'],
      ['column' => 'file', 'field' => 'file'],
    ]], $admin);
    $this->assertSame(422, $preview['status']);
    $this->assertArrayHasKey('columns.file.field', $preview['body']['error_data']);
  }

  public function testAllowedTypesPerField(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('podcast');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Podcast', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'cover', 'type' => 'media', 'media_accept' => ['image/png']],
      ['name' => 'notes', 'type' => 'media', 'media_accept' => 'text/*,application/pdf'],
    ]], $admin);
    $this->assertSame([['image/png'], ['text/*', 'application/pdf']], array_column(array_slice($entity['fields'], 1), 'media_accept'));

    // Checked during the upload when the field is named ...
    $gif = $this->upload('/media', self::gif(), 'cover.gif', $admin, ['entity' => $slug, 'field' => 'cover']);
    $this->assertSame(422, $gif['status']);
    $this->assertStringContainsString('PNG', $gif['body']['error']);
    $png = $this->upload('/media', self::png(2, 2), 'cover.png', $admin, ['entity' => $slug, 'field' => 'cover']);
    $this->assertSame(200, $png['status']);
    $text = $this->upload('/media', "Shownotes\n", 'notes.txt', $admin, ['entity' => $slug, 'field' => 'notes'])['body']['data'];

    // ... and always when the record is saved
    $this->assertSame(422, $this->api('POST', "/entities/{$slug}/records", ['cover' => $text['id']], $admin)['status']);
    $this->assertSame(200, $this->api('POST', "/entities/{$slug}/records", ['cover' => $png['body']['data']['id'], 'notes' => $text['id']], $admin)['status']);

    $invalid = $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'f', 'type' => 'media', 'media_accept' => ['application/x-msdownload', 'font/*']], $admin);
    $this->assertSame(422, $invalid['status']);
    $this->assertStringContainsString('application/x-msdownload', $invalid['body']['error_data']['media_accept'][0]);

    $options = $this->api('GET', '/admin/schema-options', token: $admin)['body']['data']['media_types'];
    $images = array_column($options, 'items', 'group')['Images'];
    $this->assertSame('image/*', $images[0]['value']);
    $this->assertContains('image/png', array_column($images, 'value'));
  }

  public function testSeveralFilesInOrderWithMinAndMax(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('albums');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Alben', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'photos', 'type' => 'media', 'media_accept' => ['image/*'], 'repeatable' => true, 'repeat_min' => 2, 'repeat_max' => 3],
      ['name' => 'cover', 'type' => 'media'],
    ]], $admin);
    $fields = array_column($entity['fields'], null, 'name');
    $this->assertSame([2, 3, true, true], [$fields['photos']['repeat_min'], $fields['photos']['repeat_max'], $fields['photos']['repeatable'], $fields['photos']['sortable']]);
    $this->assertFalse($fields['cover']['repeatable']);

    $ids = array_map(fn(int $i): string => $this->upload('/media', self::png($i, $i), "foto-{$i}.png", $admin)['body']['data']['id'], [1, 2, 3, 4]);

    // The order is kept, file objects are accepted like ids
    $record = $this->createRecord($slug, ['title' => 'Urlaub', 'photos' => [$ids[2], $ids[0]]], $admin);
    $this->assertSame([$ids[2], $ids[0]], array_column($record['photos'], 'id'));
    $reordered = $this->api('PUT', "/entities/{$slug}/records/{$record['id']}", ['photos' => [$record['photos'][1], $record['photos'][0], $ids[1]]], $admin)['body']['data'];
    $this->assertSame([$ids[0], $ids[2], $ids[1]], array_column($reordered['photos'], 'id'));
    $content = array_column($this->api('GET', "/main/content/{$slug}?filter[title]=Urlaub")['body']['data'], null, 'title');
    $this->assertSame(['foto-1.png', 'foto-3.png', 'foto-2.png'], array_column($content['Urlaub']['photos'], 'name'));

    $tooFew = $this->api('POST', "/entities/{$slug}/records", ['photos' => [$ids[0]]], $admin);
    $this->assertStringContainsString('at least 2', $tooFew['body']['error_data']['photos'][0]);
    $tooMany = $this->api('POST', "/entities/{$slug}/records", ['photos' => $ids], $admin);
    $this->assertStringContainsString('At most 3', $tooMany['body']['error_data']['photos'][0]);
    // Optional: empty is fine
    $this->assertSame([], $this->createRecord($slug, ['title' => 'Leer'], $admin)['photos']);

    // Used files are known to the library and kept by the cleanup
    $this->assertSame(1, $this->api('GET', "/media/{$ids[1]}", token: $admin)['body']['data']['usage_count']);
    $this->assertSame(409, $this->api('DELETE', "/media/{$ids[1]}", token: $admin)['status']);

    // Max 2 is refused while a record has 3; one file -> list keeps the file
    $this->assertSame(422, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['photos']['id']}", ['repeat_max' => 2], $admin)['status']);
    $this->createRecord($slug, ['title' => 'Mit Cover', 'cover' => $ids[3]], $admin);
    $this->assertSame(200, $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['cover']['id']}", ['repeatable' => true], $admin)['status']);
    $covers = array_column($this->api('GET', "/entities/{$slug}/records?s=Cover", token: $admin)['body']['data'], 'cover');
    $this->assertSame([$ids[3]], array_column($covers[0], 'id'));
  }

  public function testLibraryUploadsAreKeptAndReused(): void
  {
    $admin = $this->login();
    // Uploaded in the media library: kept, even while nothing uses it
    $kept = $this->upload('/media', self::png(5, 5), 'mediathek-logo.png', $admin, ['keep' => '1'])['body']['data'];
    $this->assertTrue($kept['kept']);
    $loose = $this->upload('/media', self::png(6, 6), 'mediathek-lose.png', $admin)['body']['data'];
    $this->assertFalse($loose['kept']);

    // The same file in several records
    $slug = $this->uniqueSlug('partners');
    $this->createEntity(['slug' => $slug, 'name' => 'Partner', 'fields' => [['name' => 'name', 'type' => 'string'], ['name' => 'logo', 'type' => 'media', 'media_accept' => ['image/*']]]], $admin);
    $a = $this->createRecord($slug, ['name' => 'A', 'logo' => $kept['id']], $admin);
    $this->createRecord($slug, ['name' => 'B', 'logo' => $kept['id']], $admin);
    $this->assertSame(2, $this->api('GET', "/media/{$kept['id']}", token: $admin)['body']['data']['usage_count']);

    // Picker of a field: only the types it allows; only kept files on request
    $this->upload('/media', "Text\n", 'mediathek-notiz.txt', $admin, ['keep' => '1']);
    $images = array_column($this->api('GET', '/media?s=mediathek&accept=image/*', token: $admin)['body']['data'], 'name');
    $this->assertEqualsCanonicalizing(['mediathek-logo.png', 'mediathek-lose.png'], $images);
    $this->assertEqualsCanonicalizing(['mediathek-logo.png', 'mediathek-notiz.txt'], array_column($this->api('GET', '/media?s=mediathek&kept=1', token: $admin)['body']['data'], 'name'));

    // Cleanup: kept files stay unused, the others go
    $this->api('PUT', "/entities/{$slug}/records/{$a['id']}", ['logo' => null], $admin);
    $this->cleanup();
    $this->assertSame(200, $this->api('GET', "/media/{$kept['id']}", token: $admin)['status']);
    $this->assertSame(404, $this->api('GET', "/media/{$loose['id']}", token: $admin)['status']);

    // Rename, and "keep" can be switched off
    $renamed = $this->api('PATCH', "/media/{$kept['id']}", ['name' => 'Firmenlogo.png', 'kept' => false], $admin)['body']['data'];
    $this->assertSame(['Firmenlogo.png', false], [$renamed['name'], $renamed['kept']]);
  }

  public function testLibraryListsFilesWithUsages(): void
  {
    $admin = $this->login();
    $used = $this->upload('/media', self::png(3, 3), 'bibliothek-verwendet.png', $admin)['body']['data'];
    $unused = $this->upload('/media', "Notiz\n", 'bibliothek-frei.txt', $admin)['body']['data'];
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('gallery'), 'name' => 'Galerie', 'label_field' => 'title', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      ['name' => 'image', 'type' => 'media'],
    ]], $admin);
    $record = $this->createRecord($entity['slug'], ['title' => 'Sonnenuntergang', 'image' => $used['id']], $admin);

    $list = array_column($this->api('GET', '/media?s=bibliothek', token: $admin)['body']['data'], null, 'name');
    $this->assertEqualsCanonicalizing(['bibliothek-frei.txt', 'bibliothek-verwendet.png'], array_keys($list));
    $this->assertSame([], $list['bibliothek-frei.txt']['usages']);
    $usage = $list['bibliothek-verwendet.png']['usages'][0];
    $this->assertSame([$entity['slug'], 'image', $record['id'], 'Sonnenuntergang'], [$usage['entity'], $usage['field'], $usage['record_id'], $usage['record_label']]);
    $this->assertSame(['bibliothek-verwendet.png'], array_column($this->api('GET', '/media?s=bibliothek&usage=used', token: $admin)['body']['data'], 'name'));
    $this->assertSame(['bibliothek-frei.txt'], array_column($this->api('GET', '/media?s=bibliothek&usage=unused', token: $admin)['body']['data'], 'name'));
    $this->assertSame(['bibliothek-verwendet.png'], array_column($this->api('GET', '/media?s=bibliothek&kind=image', token: $admin)['body']['data'], 'name'));

    // The editor may not read the gallery: the usage is only counted
    $editorView = $this->api('GET', "/media/{$used['id']}", token: $this->editorToken())['body']['data'];
    $this->assertSame([1, [], 1], [$editorView['usage_count'], $editorView['usages'], $editorView['hidden_usages']]);
    $this->assertSame(403, $this->api('DELETE', "/media/{$unused['id']}", token: $this->editorToken())['status']);

    $this->assertSame(409, $this->api('DELETE', "/media/{$used['id']}", token: $admin)['status']);
    $this->assertSame(200, $this->api('DELETE', "/media/{$unused['id']}", token: $admin)['status']);
    $this->assertSame(404, $this->api('GET', "/media/{$unused['id']}", token: $admin)['status']);
  }

  private function cleanup(): int
  {
    return $this->container()->get(MediaService::class)->removeUnused(0);
  }

  private static function gif(): string
  {
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagegif($image);
    return (string)ob_get_clean();
  }

  /** Path of a file below the media folder, without the signature */
  private static function path(string $url): string
  {
    return substr((string)parse_url($url, PHP_URL_PATH), strlen('/media'));
  }

  private static function png(int $width, int $height): string
  {
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
