<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Infrastructure\Media\StorageDefinitions;
use App\Infrastructure\Media\StorageTypes;
use App\Tests\Support\ApiTestCase;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Storages of the admin app: settings as values or $NAME .env variables, secrets encrypted and
 * never returned, picked by projects like the storages of the configuration.
 */
class StorageTest extends ApiTestCase
{
  private const ROOT = __DIR__.'/../../../runtime';

  public function testStoragesOfTheAdminApp(): void
  {
    $admin = $this->login();
    $_ENV['STORAGE_TEST_FOLDER'] = 'runtime/test-media-admin';
    $_ENV['STORAGE_TEST_SECRET'] = 'geheim-aus-env';

    $types = $this->api('GET', '/admin/storages/types', token: $admin)['body']['data'];
    $this->assertTrue($types['encryption']);
    $this->assertContains('hetzner', array_column($types['types'], 'type'));
    $this->assertNotContains('sftp', array_column($types['types'], 'type'), 'FTP & Co. come from a plugin');
    $this->assertSame(403, $this->api('GET', '/admin/storages', token: $this->editorToken())['status']);

    // The names of the configuration are taken, the folder may come from the .env
    $this->assertSame(422, $this->api('POST', '/admin/storages', ['name' => 'local', 'type' => 'local', 'settings' => ['path' => 'x']], $admin)['status']);
    $this->assertSame(422, $this->api('POST', '/admin/storages', ['name' => 'archiv', 'type' => 'local', 'settings' => ['path' => '$NOT_IN_THE_ENV']], $admin)['status']);
    $name = 'a'.bin2hex(random_bytes(4));
    $created = $this->api('POST', '/admin/storages', ['name' => $name, 'label' => 'Archiv', 'type' => 'local', 'settings' => ['path' => '$STORAGE_TEST_FOLDER']], $admin);
    $this->assertSame(200, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
    $storage = $created['body']['data'];
    $this->assertSame(['path' => '$STORAGE_TEST_FOLDER'], $storage['settings']);
    $this->assertSame(['ok' => true, 'error' => null], $this->api('POST', "/admin/storages/{$storage['id']}/test", token: $admin)['body']['data']);
    $this->assertSame(422, $this->api('PUT', "/admin/storages/{$storage['id']}", ['name' => 'anders'], $admin)['status']);

    $options = array_column($this->api('GET', '/admin/media-storages', token: $admin)['body']['data'], null, 'name');
    $this->assertSame(['name' => $name, 'label' => 'Archiv', 'type' => 'local', 'private' => true, 'default' => false, 'source' => 'admin'], $options[$name]);

    // A project picks it: uploads land in the folder of the .env variable
    $slug = 's'.bin2hex(random_bytes(4));
    $project = $this->api('POST', '/admin/projects', ['name' => 'Archiv', 'slug' => $slug, 'table_prefix' => $slug.'_', 'media_storage' => $name], $admin)['body']['data'];
    $file = $this->upload('/media', 'Hallo', 'hallo.txt', $admin, headers: ['X-Project' => $slug]);
    $this->assertSame(200, $file['status'], json_encode($file['body'], JSON_UNESCAPED_UNICODE));
    $this->assertFileExists(self::ROOT.'/test-media-admin'.substr((string)parse_url($file['body']['data']['url'], PHP_URL_PATH), strlen('/media')));
    $this->assertSame(200, $this->fetch($file['body']['data']['url'])['status']);

    // In use: not deleted
    $this->assertSame(409, $this->api('DELETE', "/admin/storages/{$storage['id']}", token: $admin)['status']);
    $this->api('DELETE', "/media/{$file['body']['data']['id']}", token: $admin, headers: ['X-Project' => $slug]);
    $this->api('PUT', "/admin/projects/{$project['id']}", ['media_storage' => ''], $admin);
    $this->db()->createCommand()->delete('media', ['disk' => $name])->execute();
    $this->assertSame(200, $this->api('DELETE', "/admin/storages/{$storage['id']}", token: $admin)['status']);
    $this->assertArrayNotHasKey($name, array_column($this->api('GET', '/admin/media-storages', token: $admin)['body']['data'], null, 'name'));
  }

  public function testSecretsAreEncryptedAndNeverReturned(): void
  {
    $admin = $this->login();
    $_ENV['STORAGE_TEST_KEY'] = 'AKIA-aus-env';
    $name = 'h'.bin2hex(random_bytes(4));
    $body = ['name' => $name, 'type' => 'hetzner', 'private' => true, 'settings' => ['region' => 'fsn1', 'bucket' => 'kunde'], 'secrets' => ['key' => '$STORAGE_TEST_KEY', 'secret' => 'sehr-geheim']];
    $this->assertSame(422, $this->api('POST', '/admin/storages', ['secrets' => ['key' => '$STORAGE_TEST_KEY']] + $body, $admin)['status'], 'the secret is required');
    $created = $this->api('POST', '/admin/storages', $body, $admin);
    $this->assertSame(200, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
    $storage = $created['body']['data'];
    $this->assertSame(['env' => 'STORAGE_TEST_KEY', 'stored' => false, 'set' => true], $storage['secrets']['key']);
    $this->assertSame(['env' => null, 'stored' => true, 'set' => true], $storage['secrets']['secret']);
    $this->assertStringNotContainsString('sehr-geheim', json_encode($this->api('GET', '/admin/storages', token: $admin)['body']));
    $this->assertStringNotContainsString('sehr-geheim', json_encode($this->api('GET', "/admin/storages/{$storage['id']}", token: $admin)['body']));
    $stored = (string)$this->db()->createQuery()->from('storage')->select('settings')->where(['id' => $storage['id']])->scalar();
    $this->assertStringNotContainsString('sehr-geheim', $stored);
    $this->assertStringContainsString('"key":"$STORAGE_TEST_KEY"', $stored);

    // Empty keeps the stored secret, the other settings change; the config gets the plain values
    $updated = $this->api('PUT', "/admin/storages/{$storage['id']}", ['settings' => ['region' => 'nbg1', 'bucket' => 'kunde', 'prefix' => 'cms'], 'secrets' => ['secret' => '']], $admin)['body']['data'];
    $this->assertTrue($updated['secrets']['secret']['stored']);
    $row = $this->db()->createQuery()->from('storage')->where(['id' => $storage['id']])->one();
    $config = $this->container()->get(StorageDefinitions::class)->config($row);
    $this->assertSame(['kunde', 'nbg1', 'AKIA-aus-env', 'sehr-geheim', 'cms'], [$config['s3']['bucket'], $config['s3']['region'], $config['s3']['key'], $config['s3']['secret'], $config['s3']['prefix']]);
    $this->assertSame('https://nbg1.your-objectstorage.com', StorageTypes::endpoint('hetzner', $config['s3']));

    // null removes it - then it is missing
    $this->assertSame(422, $this->api('PUT', "/admin/storages/{$storage['id']}", ['secrets' => ['secret' => null]], $admin)['status']);
    // Public R2 buckets need their URL; FTP & Co. come from a plugin
    $this->assertSame(422, $this->api('POST', '/admin/storages', ['name' => 'r'.bin2hex(random_bytes(4)), 'type' => 'r2', 'private' => false, 'settings' => ['account_id' => 'abc', 'bucket' => 'b'], 'secrets' => ['key' => 'k', 'secret' => 's']], $admin)['status']);
    $this->assertSame(422, $this->api('POST', '/admin/storages', ['name' => 'f'.bin2hex(random_bytes(4)), 'type' => 'ftp', 'settings' => ['host' => 'ftp.example.com']], $admin)['status']);
    $this->assertSame(200, $this->api('DELETE', "/admin/storages/{$storage['id']}", token: $admin)['status']);
  }

  private function db(): ConnectionInterface
  {
    return $this->container()->get(ConnectionInterface::class);
  }
}
