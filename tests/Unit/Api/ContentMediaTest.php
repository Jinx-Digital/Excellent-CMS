<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Application\Media\MediaService;
use App\Tests\Support\ApiTestCase;

class ContentMediaTest extends ApiTestCase
{
  public function testClientsUploadAndDeleteMediaOfTheirProject(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('partners'), 'name' => 'Partner', 'fields' => [
      ['name' => 'name', 'type' => 'string'],
      ['name' => 'logo', 'type' => 'media', 'media_accept' => ['image/*']],
    ]], $admin);
    $writer = $this->token($admin, ['upload' => true, 'delete' => true], [['entity' => $entity['slug'], 'create' => true]]);

    $image = $this->upload('/main/media', self::png(), 'logo.png', $writer);
    $this->assertSame(201, $image['status'], json_encode($image['body'], JSON_UNESCAPED_UNICODE));
    $file = $image['body']['data'];
    $this->assertSame(['logo.png', 'image/png', false], [$file['name'], $file['mime_type'], $file['kept']]);

    // The allowed types of a media field are checked at once
    $text = $this->upload('/main/media', "Hallo\n", 'notiz.txt', $writer, ['entity' => $entity['slug'], 'field' => 'logo']);
    $this->assertSame(422, $text['status']);
    $this->assertSame(422, $this->upload('/main/media', "Hallo\n", 'notiz.txt', $writer, ['entity' => $entity['slug'], 'field' => 'name'])['status']);
    $this->assertSame(404, $this->upload('/main/media', "Hallo\n", 'notiz.txt', $writer, ['entity' => 'gibtsnicht', 'field' => 'logo'])['status']);
    $kept = $this->upload('/main/media', "Hallo\n", 'notiz.txt', $writer, ['keep' => '1'])['body']['data'];
    $this->assertTrue($kept['kept']);

    // Used in a record: it stays until the record is gone
    $record = $this->api('POST', "/main/content/{$entity['slug']}", ['name' => 'ACME', 'logo' => $file['id']], $writer);
    $this->assertSame(201, $record['status'], json_encode($record['body'], JSON_UNESCAPED_UNICODE));
    // The upload is signed (not used yet), the record of the public entity has the plain address
    $this->assertSame(strtok($file['url'], '?'), $record['body']['data']['logo']['url']);

    $info = $this->api('GET', "/main/media/{$file['id']}", token: $writer);
    $this->assertSame(1, $info['body']['data']['usage_count']);
    $this->assertArrayNotHasKey('usages', $info['body']['data'], 'which records use it stays hidden');

    $inUse = $this->api('DELETE', "/main/media/{$file['id']}", token: $writer);
    $this->assertSame([409, 'media_in_use'], [$inUse['status'], $inUse['body']['error_code']]);
    $this->assertSame(200, $this->api('DELETE', "/main/media/{$kept['id']}", token: $writer)['status']);
    $this->assertSame(404, $this->api('GET', "/main/media/{$kept['id']}", token: $writer)['status']);
  }

  public function testMediaNeedsPermissionsAndStaysInItsProject(): void
  {
    $admin = $this->login();
    $file = $this->upload('/media', self::png(), 'logo.png', $admin)['body']['data'];

    $this->assertSame(401, $this->api('DELETE', "/main/media/{$file['id']}")['status']);
    $reader = $this->token($admin, null, ['countries']);
    $this->assertSame(403, $this->upload('/main/media', self::png(), 'logo.png', $reader)['status']);
    $this->assertSame(403, $this->api('GET', "/main/media/{$file['id']}", token: $reader)['status']);

    $uploader = $this->token($admin, ['upload' => true]);
    $this->assertSame(200, $this->api('GET', "/main/media/{$file['id']}", token: $uploader)['status']);
    $denied = $this->api('DELETE', "/main/media/{$file['id']}", token: $uploader);
    $this->assertSame(403, $denied['status']);
    $this->assertStringContainsString('delete', $denied['body']['error']);

    // The permissions show in the admin app
    $clients = array_column($this->api('GET', '/admin/clients', token: $admin)['body']['data'], 'media', 'name');
    $this->assertSame(['upload' => true, 'delete' => false], $clients['Medien']);

    // Clients of another project do not see the files of this one
    $slug = 'p'.substr(md5(uniqid('', true)), 0, 8);
    $project = $this->api('POST', '/admin/projects', ['name' => 'Shop', 'slug' => $slug, 'table_prefix' => $slug.'_'], $admin);
    $this->assertSame(200, $project['status'], json_encode($project['body'], JSON_UNESCAPED_UNICODE));
    $other = $this->token($admin, ['upload' => true, 'delete' => true], [], $slug);
    $this->assertSame(404, $this->api('GET', "/{$slug}/media/{$file['id']}", token: $other)['status']);
    $this->assertSame(404, $this->api('DELETE', "/{$slug}/media/{$file['id']}", token: $other)['status']);
    $this->assertSame(401, $this->api('GET', "/main/media/{$file['id']}", token: $other)['status'], 'tokens belong to one project');
    $this->assertNotNull($this->container()->get(MediaService::class)->find($file['id']));

    // No unused file left behind for the cleanup tests
    $this->assertSame(200, $this->api('DELETE', "/media/{$file['id']}", token: $admin)['status']);
  }

  /**
   * Token of a new client with media permissions (null: none).
   *
   * @param array{upload?: bool, delete?: bool}|null $media
   */
  private function token(string $admin, ?array $media, array $entities = [], string $project = 'main'): string
  {
    $headers = ['X-Project' => $project];
    $client = $this->api('POST', '/admin/clients', ['name' => null !== $media ? 'Medien' : 'Leser', 'entities' => $entities] + (null !== $media ? ['media' => $media] : []), $admin, $headers);
    $this->assertSame(200, $client['status'], json_encode($client['body'], JSON_UNESCAPED_UNICODE));
    $data = $client['body']['data'];
    return $this->api('POST', "/{$project}/oauth/token", ['grant_type' => 'client_credentials', 'client_id' => $data['client_id'], 'client_secret' => $data['client_secret']], headers: ['Content-Type' => 'application/x-www-form-urlencoded'])['body']['access_token'];
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(4, 3);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
