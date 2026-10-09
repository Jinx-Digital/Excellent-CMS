<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Domain\Project\Project;
use App\Infrastructure\Media\MediaStorageFactory;
use App\Infrastructure\Media\MediaStorages;
use Codeception\Test\Unit;
use InvalidArgumentException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MediaStorageTest extends Unit
{
  private const CONFIG = ['type' => 'local', 'url' => '', 's3' => ['bucket' => 'cms', 'region' => '', 'endpoint' => '', 'key' => 'k', 'secret' => 's', 'path_style' => false, 'prefix' => '', 'acl' => '']];

  public function testS3UploadThroughFlysystemSendsNoAcl(): void
  {
    $requests = [];
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
      $requests[] = ['method' => $method, 'url' => $url, 'headers' => implode("\n", $options['headers'] ?? [])];
      return new MockResponse('', ['http_code' => 200]);
    });
    $storage = MediaStorageFactory::create('r2', ['type' => 'r2', 'url' => 'https://media.example.com', 'http_client' => $http, 's3' => [
      'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'path_style' => true, 'prefix' => 'uploads',
    ] + self::CONFIG['s3']] + self::CONFIG, '/media', '/app');

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'data');
    rewind($stream);
    $storage->put('main/2026/10/abc.png', $stream, 'image/png');
    $storage->delete('main/2026/10/abc.png');

    $this->assertSame(['r2', 'r2', false], [$storage->disk(), $storage->type(), $storage->servedByCms()]);
    $this->assertSame(['PUT', 'https://acc.r2.cloudflarestorage.com/cms/uploads/main/2026/10/abc.png'], [$requests[0]['method'], $requests[0]['url']]);
    $this->assertStringContainsStringIgnoringCase('content-type: image/png', $requests[0]['headers']);
    $this->assertStringContainsStringIgnoringCase('cache-control: public, max-age=31536000, immutable', $requests[0]['headers']);
    $this->assertStringNotContainsStringIgnoringCase('x-amz-acl', $requests[0]['headers']);
    $this->assertSame('DELETE', end($requests)['method']);
    $this->assertSame('https://media.example.com/uploads/main/2026/10/abc.png', $storage->url('main/2026/10/abc.png'));
  }

  public function testAclCanBeSet(): void
  {
    $headers = '';
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers): MockResponse {
      $headers .= implode("\n", $options['headers'] ?? []);
      return new MockResponse('', ['http_code' => 200]);
    });
    $storage = MediaStorageFactory::create('s3', ['type' => 's3', 'http_client' => $http, 's3' => ['acl' => 'public-read'] + self::CONFIG['s3']] + self::CONFIG, '/media', '/app');
    $storage->put('a.txt', fopen('data://text/plain,x', 'rb'), 'text/plain');
    $this->assertStringContainsStringIgnoringCase('x-amz-acl: public-read', $headers);
  }

  public function testPrivateBucketIsReadByTheCms(): void
  {
    $urls = [];
    $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
      $urls[] = $method.' '.$url;
      // HEAD (exists?) and GET of the object
      return new MockResponse('HEAD' === $method ? '' : 'PNG-DATA', ['http_code' => 200, 'response_headers' => ['content-length' => '8']]);
    });
    $storage = MediaStorageFactory::create('kunde', ['type' => 'r2', 'private' => true, 'http_client' => $http, 's3' => [
      'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'path_style' => true, 'prefix' => 'uploads',
    ] + self::CONFIG['s3']] + self::CONFIG, '/media', '/app');

    $stream = $storage->read('p/2026/10/a.png');
    $this->assertIsResource($stream);
    $this->assertSame('PNG-DATA', stream_get_contents($stream));
    $this->assertSame('GET https://acc.r2.cloudflarestorage.com/cms/uploads/p/2026/10/a.png', end($urls));
  }

  public function testFactoryChecksConfiguration(): void
  {
    $local = MediaStorageFactory::create('local', self::CONFIG, '/media', '/app');
    $this->assertSame(['/media/a.png', true], [$local->url('a.png'), $local->servedByCms()]);
    $this->assertSame('https://cms.s3.us-east-1.amazonaws.com/a.png', MediaStorageFactory::create('s3', ['type' => 's3'] + self::CONFIG, '/media', '/app')->url('a.png'));

    // A private bucket is served by the CMS: its files get the address of the CMS, without the bucket prefix
    $private = MediaStorageFactory::create('kunde', ['type' => 'r2', 'private' => true, 's3' => ['endpoint' => 'https://acc.r2.cloudflarestorage.com', 'prefix' => 'uploads'] + self::CONFIG['s3']] + self::CONFIG, 'https://cms.example.com/media', '/app');
    $this->assertSame(['https://cms.example.com/media/p/a.png', true, 'kunde'], [$private->url('p/a.png'), $private->servedByCms(), $private->disk()]);

    foreach ([['type' => 'ftp'], ['type' => 'r2'], ['type' => 'r2', 'url' => 'https://pub.r2.dev'], ['type' => 's3', 's3' => ['bucket' => ''] + self::CONFIG['s3']]] as $invalid) {
      try {
        MediaStorageFactory::create('x', $invalid + self::CONFIG, '/media', '/app');
        $this->fail(json_encode($invalid).' should be rejected');
      } catch (InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function testStoragesByName(): void
  {
    $storages = new MediaStorages('storage', '', '/app');
    $this->assertSame([
      ['name' => 'local', 'label' => 'local', 'type' => 'local', 'private' => true, 'default' => true, 'source' => 'builtin'],
    ], $storages->options(), 'local is always there');
    $this->assertSame('/media/a.png', $storages->get('local')->url('a.png'), 'no MEDIA_URL: the CMS serves under /media');
    $this->assertNull($storages->find('weg'));
    $this->assertTrue($storages->isBuiltIn('local'));

    // Projects without a (known) storage use "local"
    $this->assertSame('local', $storages->forProject(new Project('1', 'p', 'P', 'p_'))->disk());
    $this->assertSame('local', $storages->forProject(new Project('1', 'p', 'P', 'p_', mediaStorage: 'weg'))->disk());
  }
}
