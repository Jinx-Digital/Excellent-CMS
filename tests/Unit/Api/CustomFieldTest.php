<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;
use ZipArchive;

/**
 * Field types of plugins (type "custom"): checked and converted by the plugin, delivered with its
 * present(), searched by its text, files counted as used - in entities and blocks. Plugin routes
 * and assets. And fields that exist only once per entity.
 */
class CustomFieldTest extends ApiTestCase
{
  private const PLUGIN = <<<'PHP'
<?php
namespace PickType;
use App\Plugin\{PluginInterface, PluginRegistry, PluginContext, PluginRequest};
final class Plugin implements PluginInterface {
  public function register(PluginRegistry $r, PluginContext $c): void {
    $r->fieldType('pick', 'Pick', component: ['tag' => 'pick-input', 'script' => 'assets/pick.js'],
      toStorage: static function (mixed $v): ?string {
        if (!is_array($v) || 1 !== preg_match('/^[a-z]{3}$/', (string)($v['code'] ?? ''))) { throw new \InvalidArgumentException('Three small letters, please.'); }
        return json_encode(['code' => $v['code'], 'file' => $v['file'] ?? null]);
      },
      present: static fn(array $v, array $files): array => ['code' => $v['code'], 'file' => isset($v['file']) ? ($files[$v['file']]['url'] ?? null) : null],
      text: static fn(array $v): string => 'code'.$v['code'],
      mediaIds: static fn(array $v): array => array_filter([$v['file'] ?? null]),
      config: static fn(): array => ['greeting' => $c->setting('greeting', 'hi')],
    );
    $r->route('GET', 'hello', static fn(PluginRequest $q): array => ['project' => $q->projectId, 'user' => $q->userId, 'q' => $q->query['q'] ?? null]);
    $r->route('POST', 'secret', static fn(PluginRequest $q): array => ['ok' => true], admin: true);
  }
}
PHP;

  public function testFieldTypesOfPlugins(): void
  {
    $admin = $this->login();
    // Left over from an earlier run
    if (($this->api('GET', '/admin/plugins/pick-type', token: $admin)['body']['data']['installed'] ?? false) === true) {
      $this->api('DELETE', '/admin/plugins/pick-type', token: $admin);
    }
    $zip = self::zip([
      'plugin.json' => json_encode(['name' => 'pick-type', 'version' => '1', 'class' => 'PickType\\Plugin', 'autoload' => ['PickType\\' => 'src/'], 'settings' => [['key' => 'greeting', 'kind' => 'text']]]),
      'src/Plugin.php' => self::PLUGIN,
      'assets/pick.js' => 'customElements.define("pick-input", class extends HTMLElement {})',
    ]);
    $this->assertSame(200, $this->upload('/admin/plugins/upload', $zip, 'pick.zip', $admin)['status']);
    $this->api('POST', '/admin/plugins/pick-type/install', token: $admin);
    $slug = $this->uniqueSlug('picks');
    $fields = [['name' => 'title', 'type' => 'string'], ['name' => 'pick', 'type' => 'pick-type.pick']];
    $this->assertSame(422, $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Picks', 'fields' => $fields], $admin)['status'], 'inactive: no such type');

    $this->api('POST', '/admin/plugins/pick-type/activate', token: $admin);
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Picks', 'access' => 'public', 'fields' => $fields], $admin);
    $field = array_column($entity['fields'], null, 'name')['pick'];
    $this->assertSame('pick-type.pick', $field['type']);
    $this->assertSame(['tag' => 'pick-input', 'script' => '/api/v1/plugins/pick-type/assets/pick.js'], $field['custom']['component']);
    $this->assertSame(['greeting' => 'hi'], $field['custom']['config']);

    // Checked by the plugin, delivered with its present(), the file counts as used
    $file = $this->upload('/media', self::png(), 'p.png', $admin)['body']['data'];
    $bad = $this->api('POST', "/entities/{$slug}/records", ['pick' => ['code' => 'TOOLONG']], $admin);
    $this->assertSame(['Three small letters, please.'], $bad['body']['error_data']['pick'] ?? null);
    $record = $this->createRecord($slug, ['title' => 'Eins', 'pick' => ['code' => 'abc', 'file' => $file['id']]], $admin);
    $this->assertSame('abc', $record['pick']['code']);
    $this->assertStringContainsString('/media/', (string)$record['pick']['file']);
    $live = $this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data'];
    $this->assertSame('abc', $live['pick']['code']);
    $this->assertSame(['Eins'], array_column($this->api('GET', "/main/content/{$slug}?s=codeabc")['body']['data'], 'title'));
    $this->assertSame(409, $this->api('DELETE', "/media/{$file['id']}", token: $admin)['status']);
    // The public schema has no component
    $schema = array_column(array_column($this->api('GET', '/main/content')['body']['data'], null, 'slug')[$slug]['fields'], null, 'name')['pick'];
    $this->assertSame(['type' => 'pick-type.pick', 'plugin' => 'pick-type', 'label' => 'Pick'], $schema['custom']);

    // Inside blocks
    $group = $this->api('POST', '/admin/groups', ['name' => 'pickblock_'.bin2hex(random_bytes(3)), 'label' => 'Pick-Block', 'kind' => 'block', 'fields' => [['name' => 'pick', 'type' => 'pick-type.pick']]], $admin)['body']['data'];
    $pages = $this->uniqueSlug('pickpages');
    $this->createEntity(['slug' => $pages, 'name' => 'Seiten', 'fields' => [['name' => 'content', 'type' => 'group', 'blocks' => [$group['id']]]]], $admin);
    $this->assertSame(422, $this->api('POST', "/entities/{$pages}/records", ['content' => [['_type' => $group['name'], 'pick' => ['code' => 'x']]]], $admin)['status']);
    $page = $this->createRecord($pages, ['content' => [['_type' => $group['name'], 'pick' => ['code' => 'xyz', 'file' => $file['id']]]]], $admin);
    $this->assertSame('xyz', $page['content'][0]['pick']['code']);
    $this->assertStringContainsString('/media/', (string)$page['content'][0]['pick']['file']);

    // Routes and assets of the plugin
    $hello = $this->api('GET', '/plugins/pick-type/api/hello?q=1', token: $admin)['body']['data'];
    $this->assertSame('1', $hello['q']);
    $this->assertNotNull($hello['user']);
    $this->assertSame(401, $this->api('GET', '/plugins/pick-type/api/hello')['status'], 'signed in only');
    $this->assertSame(403, $this->api('POST', '/plugins/pick-type/api/secret', [], $this->editorToken())['status'], 'admins only');
    $this->assertSame(404, $this->api('GET', '/plugins/pick-type/api/nope', token: $admin)['status']);
    $asset = $this->fetch('http://localhost/api/v1/plugins/pick-type/assets/pick.js');
    $this->assertSame(200, $asset['status']);
    $this->assertStringStartsWith('text/javascript', $asset['headers']['Content-Type'] ?? '');
    $this->assertSame(404, $this->fetch('http://localhost/api/v1/plugins/pick-type/assets/../plugin.json')['status']);

    // Deactivated: the values stay and come as they are stored
    $this->api('POST', '/admin/plugins/pick-type/deactivate', token: $admin);
    $raw = $this->api('GET', "/main/content/{$slug}/{$record['id']}")['body']['data']['pick'];
    $this->assertSame(['code' => 'abc', 'file' => $file['id']], $raw);
    $this->api('DELETE', "/entities/{$pages}/records/{$page['id']}", token: $admin);
    $this->api('DELETE', "/entities/{$slug}/records/{$record['id']}", token: $admin);
    $this->api('DELETE', '/admin/plugins/pick-type', token: $admin);
  }

  public function testFieldsThatExistOnlyOnce(): void
  {
    $admin = $this->login();
    $fields = [['name' => 'title', 'type' => 'string']];
    foreach (['uuid', 'autoincrement', 'order'] as $type) {
      $slug = $this->uniqueSlug('once');
      $twice = $this->api('POST', '/admin/entities', ['slug' => $slug, 'name' => 'Einmal', 'fields' => [...$fields, ['name' => 'a', 'type' => $type], ['name' => 'b', 'type' => $type]]], $admin);
      $this->assertSame(422, $twice['status'], $type.' twice when creating');
      $entity = $this->createEntity(['slug' => $slug, 'name' => 'Einmal', 'fields' => [...$fields, ['name' => 'a', 'type' => $type]]], $admin);
      $this->assertSame(422, $this->api('POST', "/admin/entities/{$entity['id']}/fields", ['name' => 'b', 'type' => $type], $admin)['status'], $type.' added a second time');
    }
  }

  /**
   * @param array<string, string> $files
   */
  private static function zip(array $files): string
  {
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) {
      $zip->addFromString($name, $content);
    }
    $zip->close();
    $content = (string)file_get_contents($path);
    unlink($path);
    return $content;
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(3, 3);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }
}
