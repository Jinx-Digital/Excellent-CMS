<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Each project picks the storage of its new uploads; files stay in the storage they were written to.
 */
class ProjectMediaStorageTest extends ApiTestCase
{
  private const ROOT = __DIR__.'/../../../runtime';

  public function testProjectsPickTheirStorage(): void
  {
    $admin = $this->login();
    // A second storage (admin app) - from an earlier run or new
    if (200 !== $this->api('POST', '/admin/storages', ['name' => 'second', 'label' => 'Zweiter Speicher', 'type' => 'local', 'settings' => ['path' => 'runtime/test-media-2']], $admin)['status']) {
      $this->assertContains('second', array_column($this->api('GET', '/admin/storages', token: $admin)['body']['data'], 'name'));
    }
    $response = $this->api('GET', '/admin/media-storages', token: $admin);
    $this->assertSame(200, $response['status'], json_encode($response['body'], JSON_UNESCAPED_UNICODE));
    $storages = array_column($response['body']['data'], null, 'name');
    $this->assertSame(['name' => 'local', 'label' => 'local', 'type' => 'local', 'private' => true, 'default' => true, 'source' => 'builtin'], $storages['local']);
    $this->assertSame(['name' => 'second', 'label' => 'Zweiter Speicher', 'type' => 'local', 'private' => true, 'default' => false, 'source' => 'admin'], $storages['second']);
    $this->assertSame(403, $this->api('GET', '/admin/media-storages', token: $this->editorToken())['status']);

    $slug = 's'.bin2hex(random_bytes(4));
    $this->assertSame(422, $this->api('POST', '/admin/projects', ['name' => 'Speicher', 'slug' => $slug, 'table_prefix' => $slug.'_', 'media_storage' => 'gibtsnicht'], $admin)['status']);
    $created = $this->api('POST', '/admin/projects', ['name' => 'Speicher', 'slug' => $slug, 'table_prefix' => $slug.'_', 'media_storage' => 'second'], $admin);
    $this->assertSame(200, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
    $project = $created['body']['data'];
    $this->assertSame('second', $project['media_storage']);
    $here = ['X-Project' => $slug];

    $entity = $this->api('POST', '/admin/entities', ['slug' => 'logos', 'name' => 'Logos', 'access' => 'public', 'fields' => [['name' => 'logo', 'type' => 'media']]], $admin, $here)['body']['data'];
    $first = $this->upload('/media', self::png(), 'eins.png', $admin, headers: $here)['body']['data'];
    $this->assertFileExists(self::ROOT.'/test-media-2'.self::path($first['url']));
    $this->assertFileDoesNotExist(self::ROOT.'/test-media'.self::path($first['url']));
    $this->assertSame(200, $this->api('POST', '/entities/logos/records', ['logo' => $first['id']], $admin, $here)['status']);

    // Back to the default storage: new uploads go there, the old file stays where it is and works
    $this->assertNull($this->api('PUT', "/admin/projects/{$project['id']}", ['media_storage' => ''], $admin)['body']['data']['media_storage']);
    $second = $this->upload('/media', self::png(), 'zwei.png', $admin, headers: $here)['body']['data'];
    $this->assertFileExists(self::ROOT.'/test-media'.self::path($second['url']));
    $url = $this->api('GET', "/{$slug}/content/logos")['body']['data'][0]['logo']['url'];
    $this->assertSame(200, $this->fetch($url)['status']);
    // Other tests count the unused files
    $this->assertSame(200, $this->api('DELETE', "/media/{$second['id']}", token: $admin, headers: $here)['status']);

    // Copied into a project of another storage: the file moves along
    $this->api('PUT', "/admin/projects/{$project['id']}", ['media_storage' => 'second'], $admin);
    $target = 'z'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Ziel', 'slug' => $target, 'table_prefix' => $target.'_'], $admin);
    $copy = $this->api('POST', "/admin/entities/{$entity['id']}/copy", ['project' => $target, 'records' => true], $admin, $here);
    $this->assertSame(200, $copy['status'], json_encode($copy['body'], JSON_UNESCAPED_UNICODE));
    $copied = $this->api('GET', "/{$target}/content/logos")['body']['data'][0]['logo'];
    $this->assertNotSame($first['id'], $copied['id']);
    $this->assertFileExists(self::ROOT.'/test-media'.self::path($copied['url']));
    $this->assertSame(200, $this->fetch($copied['url'])['status']);
  }

  private static function path(string $url): string
  {
    return substr((string)parse_url($url, PHP_URL_PATH), strlen('/media'));
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(3, 3);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
