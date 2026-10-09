<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * GET /media/<path>: files of public entities for everyone, all others only with a signed address.
 */
class MediaFileTest extends ApiTestCase
{
  public function testPublicFilesAreOpenProtectedOnesNeedASignature(): void
  {
    $admin = $this->login();
    $png = self::png();
    $public = $this->createEntity(['slug' => $this->uniqueSlug('logos'), 'name' => 'Logos', 'access' => 'public', 'trash' => true, 'fields' => [
      ['name' => 'logo', 'type' => 'media'],
    ]], $admin);
    $protected = $this->createEntity(['slug' => $this->uniqueSlug('contracts'), 'name' => 'Verträge', 'access' => 'oauth', 'fields' => [
      ['name' => 'file', 'type' => 'media'],
    ]], $admin);

    // Public entity: the content API hands out the plain address, everyone may read it
    $logo = $this->upload('/media', $png, 'logo.png', $admin)['body']['data'];
    $record = $this->createRecord($public['slug'], ['logo' => $logo['id']], $admin);
    $url = $this->api('GET', "/main/content/{$public['slug']}")['body']['data'][0]['logo']['url'];
    $this->assertStringNotContainsString('signature=', $url);
    $file = $this->fetch($url);
    $this->assertSame([200, $png, 'image/png', (string)strlen($png)], [$file['status'], $file['content'], $file['headers']['Content-Type'], $file['headers']['Content-Length']]);
    $this->assertStringStartsWith('public', $file['headers']['Cache-Control']);
    $this->assertSame(304, $this->fetch($url, ['If-None-Match' => $file['headers']['ETag']])['status']);
    $part = $this->fetch($url, ['Range' => 'bytes=0-9']);
    $this->assertSame([206, substr($png, 0, 10), 'bytes 0-9/'.strlen($png)], [$part['status'], $part['content'], $part['headers']['Content-Range']]);
    $this->assertSame(416, $this->fetch($url, ['Range' => 'bytes=99999-'])['status']);

    // In the trash the record is not public any more
    $this->assertSame(200, $this->api('DELETE', "/entities/{$public['slug']}/records/{$record['id']}", token: $admin)['status']);
    $this->assertSame(403, $this->fetch($url)['status']);

    // Protected entity: only the signed address works
    $contract = $this->upload('/media', $png, 'vertrag.png', $admin)['body']['data'];
    $signed = $this->createRecord($protected['slug'], ['file' => $contract['id']], $admin)['file']['url'];
    $this->assertMatchesRegularExpression('#\?expires=\d+&signature=[0-9a-f]{64}$#', $signed);
    $file = $this->fetch($signed);
    $this->assertSame([200, $png], [$file['status'], $file['content']]);
    $this->assertStringStartsWith('private', $file['headers']['Cache-Control']);
    $plain = strtok($signed, '?');
    $this->assertSame(403, $this->fetch($plain)['status']);
    $this->assertSame(403, $this->fetch(preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $signed))['status'], 'changed signature');
    $this->assertSame(403, $this->fetch($plain.'?expires='.(time() - 1).'&'.explode('&', $signed)[1])['status'], 'other expiry');

    // A signature belongs to its file
    $other = $this->upload('/media', $png, 'anderer.png', $admin)['body']['data'];
    $this->assertSame(403, $this->fetch(strtok($other['url'], '?').'?'.explode('?', $signed)[1])['status']);
    // Other tests count the unused files
    $this->assertSame(200, $this->api('DELETE', "/media/{$other['id']}", token: $admin)['status']);

    $this->assertSame(404, $this->fetch('http://localhost/media/main/2026/01/gibtsnicht.png')['status']);
    $this->assertSame(404, $this->fetch('http://localhost/media/main/../../.env')['status']);
  }

  public function testImagesCanBeTransformed(): void
  {
    $admin = $this->login();
    $entity = $this->createEntity(['slug' => $this->uniqueSlug('photos'), 'name' => 'Fotos', 'access' => 'public', 'fields' => [
      ['name' => 'photo', 'type' => 'media'],
    ]], $admin);
    // 400 × 200, blue
    $photo = $this->upload('/media', self::png(400, 200, [0, 0, 255]), 'foto.png', $admin)['body']['data'];
    $this->createRecord($entity['slug'], ['photo' => $photo['id']], $admin);
    $file = $this->api('GET', "/main/content/{$entity['slug']}")['body']['data'][0]['photo'];
    $this->assertStringNotContainsString('signature=', $file['transform_url']);
    $url = $file['transform_url'];

    // cover: exactly w × h, cut; format webp
    $cover = $this->fetch($url.'?w=100&h=100&format=webp');
    $this->assertSame([200, 'image/webp'], [$cover['status'], $cover['headers']['Content-Type']], $cover['content']);
    $this->assertSame([100, 100], array_slice(getimagesizefromstring($cover['content']) ?: [], 0, 2));
    $this->assertStringContainsString('foto.webp', $cover['headers']['Content-Disposition']);
    $this->assertStringStartsWith('public', $cover['headers']['Cache-Control']);
    // Made once: the second answer is the cached file, a known ETag gets a 304
    $this->assertSame($cover['content'], $this->fetch($url.'?w=100&h=100&format=webp')['content']);
    $this->assertSame(304, $this->fetch($url.'?format=webp&h=100&w=100', ['If-None-Match' => $cover['headers']['ETag']])['status']);

    // contain: w × h with whitespace in the color of bg
    $contain = $this->fetch($url.'?w=100&h=100&fit=contain&bg=f00&format=png');
    $image = imagecreatefromstring($contain['content']);
    $this->assertSame([100, 100], [imagesx($image), imagesy($image)]);
    $this->assertSame(['red' => 255, 'green' => 0, 'blue' => 0], array_slice(imagecolorsforindex($image, imagecolorat($image, 0, 0)), 0, 3), 'whitespace');
    $this->assertSame(['red' => 0, 'green' => 0, 'blue' => 255], array_slice(imagecolorsforindex($image, imagecolorat($image, 50, 50)), 0, 3), 'image');

    // inside: proportions kept, never enlarged; without format the original one
    $inside = $this->fetch($url.'?w=100');
    $this->assertSame([100, 50, 'image/png'], [...array_slice(getimagesizefromstring($inside['content']) ?: [], 0, 2), $inside['headers']['Content-Type']]);
    $this->assertSame([400, 200], array_slice(getimagesizefromstring($this->fetch($url.'?w=1000')['content']) ?: [], 0, 2));
    $this->assertSame([150, 150], array_slice(getimagesizefromstring($this->fetch($url.'?w=150&h=150&pos=left&format=jpg&q=60')['content']) ?: [], 0, 2));

    // Wrong parameters
    foreach (['w=0', 'w=99999', 'w=100&fit=cover', 'fit=stretch', 'format=bmp', 'q=101', 'bg=red', 'pos=middle'] as $query) {
      $this->assertSame(400, $this->fetch($url.'?'.$query)['status'], $query);
    }

    // Protected files: the signed address works with the parameters too
    $loose = $this->upload('/media', self::png(40, 40), 'lose.png', $admin)['body']['data'];
    $this->assertMatchesRegularExpression('#signature=#', $loose['transform_url']);
    $this->assertSame(403, $this->fetch(strtok($loose['transform_url'], '?').'?w=10')['status']);
    $this->assertSame(200, $this->fetch($loose['transform_url'].'&w=10')['status']);
    // The variants go with their file
    $folder = dirname(__DIR__, 3).'/runtime/media-variants/'.$loose['id'];
    $this->assertDirectoryExists($folder);
    $this->assertSame(200, $this->api('DELETE', "/media/{$loose['id']}", token: $admin)['status']);
    $this->assertDirectoryDoesNotExist($folder);
  }

  public function testFocalPointKeepsTheImportantPartInView(): void
  {
    $admin = $this->login();
    // 400 × 100: left half red, right half blue
    $image = imagecreatetruecolor(400, 100);
    imagefilledrectangle($image, 0, 0, 199, 99, (int)imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 200, 0, 399, 99, (int)imagecolorallocate($image, 0, 0, 255));
    ob_start();
    imagepng($image);
    $file = $this->upload('/media', (string)ob_get_clean(), 'streifen.png', $admin)['body']['data'];
    $this->assertNull($file['focal_point']);
    $colorOf = function (string $url): array {
      $variant = imagecreatefromstring($this->fetch($url)['content']);
      return array_slice(imagecolorsforindex($variant, imagecolorat($variant, 5, 25)), 0, 3);
    };
    $blue = ['red' => 0, 'green' => 0, 'blue' => 255];

    // Square from the middle: the left edge of it is red
    $this->assertSame(['red' => 255, 'green' => 0, 'blue' => 0], $colorOf($file['transform_url'].'&w=50&h=50&format=png'));

    // Focal point on the right: the square moves there, and the address changes with it
    $saved = $this->api('PATCH', "/media/{$file['id']}", ['focal_point' => ['x' => 0.9, 'y' => 0.5]], $admin);
    $this->assertSame(200, $saved['status'], json_encode($saved['body'], JSON_UNESCAPED_UNICODE));
    $moved = $saved['body']['data'];
    $this->assertSame(['x' => 0.9, 'y' => 0.5], $moved['focal_point']);
    $this->assertStringContainsString('fp=0.9,0.5', $moved['transform_url']);
    $this->assertSame($blue, $colorOf($moved['transform_url'].'&w=50&h=50&format=png'));
    // fp alone serves the original
    $this->assertSame('image/png', $this->fetch($moved['transform_url'])['headers']['Content-Type']);

    $this->assertSame(422, $this->api('PATCH', "/media/{$file['id']}", ['focal_point' => ['x' => 2, 'y' => 0]], $admin)['status']);
    $this->assertSame(400, $this->fetch(strtok($moved['transform_url'], '?').'?'.parse_url($moved['transform_url'], PHP_URL_QUERY).'&w=10&fp=1.5,0')['status']);
    $this->assertNull($this->api('PATCH', "/media/{$file['id']}", ['focal_point' => null], $admin)['body']['data']['focal_point']);
    // Other tests count the unused files
    $this->api('DELETE', "/media/{$file['id']}", token: $admin);
  }

  /**
   * @param array{int, int, int} $color
   */
  private static function png(int $width = 4, int $height = 4, array $color = [0, 0, 0]): string
  {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int)imagecolorallocate($image, ...$color));
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
