<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Infrastructure\Webhook\WebhookSender;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FakeWebhookSender;
use Yiisoft\Db\Connection\ConnectionInterface;
use ZipArchive;

/**
 * Plugins as ZIP: upload, install (migrations), activate, settings, event steps, panels,
 * deactivate, uninstall (migrations back, files gone) - and safe mode.
 */
class PluginTest extends ApiTestCase
{
  private const DIR = __DIR__.'/../../../runtime/test-plugins';

  protected function _before(): void
  {
    static::$overrides = [WebhookSender::class => FakeWebhookSender::class];
    FakeWebhookSender::$calls = [];
    FakeWebhookSender::$status = 200;
  }

  protected function _after(): void
  {
    static::$overrides = [];
  }

  public function testLifecycleOfThePlugin(): void
  {
    $admin = $this->login();
    // A clean start: the plugin from earlier runs is gone
    if ($this->api('GET', '/admin/plugins/notifier', token: $admin)['status'] === 200 && ($this->api('GET', '/admin/plugins/notifier', token: $admin)['body']['data']['installed'] ?? false)) {
      $this->api('DELETE', '/admin/plugins/notifier', token: $admin);
    }
    $this->assertSame(403, $this->api('GET', '/admin/plugins', token: $this->editorToken())['status']);

    // Not a plugin / paths that leave the folder / files that are not allowed
    $this->assertSame(422, $this->upload('/admin/plugins/upload', 'no zip', 'x.zip', $admin)['status']);
    $this->assertSame(422, $this->upload('/admin/plugins/upload', self::zip(['plugin.json' => '{"name":"evil","version":"1","class":"Evil\\\\P","autoload":{"Evil\\\\":"src/"}}', '../escape.php' => '<?php']), 'evil.zip', $admin)['status']);
    $this->assertSame(422, $this->upload('/admin/plugins/upload', self::zip(['plugin.json' => '{"name":"evil","version":"1","class":"Evil\\\\P","autoload":{"Evil\\\\":"src/"}}', 'tool.phar' => 'x']), 'evil.zip', $admin)['status']);
    $this->assertSame(422, $this->upload('/admin/plugins/upload', self::zip(['plugin.json' => '{"name":"evil","version":"1","class":"App\\\\Evil","autoload":{"App\\\\":"src/"}}']), 'evil.zip', $admin)['status'], 'the namespace of the CMS is taken');

    // Upload: in the folder, not installed yet
    $uploaded = $this->upload('/admin/plugins/upload', $this->zipOf('notifier'), 'notifier.zip', $admin);
    $this->assertSame(200, $uploaded['status'], json_encode($uploaded['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['notifier', '1.0.0', false, false], [$uploaded['body']['data']['name'], $uploaded['body']['data']['version'], $uploaded['body']['data']['installed'], $uploaded['body']['data']['active']]);
    $this->assertFileExists(self::DIR.'/notifier/src/Plugin.php');
    $this->assertSame(409, $this->api('POST', '/admin/plugins/notifier/activate', token: $admin)['status'], 'install first');

    // Install: its migration creates its table
    $installed = $this->api('POST', '/admin/plugins/notifier/install', token: $admin)['body']['data'];
    $this->assertTrue($installed['installed']);
    $this->assertSame(['2026_10_08_000001_log'], $installed['migrations']);
    $this->assertNotNull($this->db()->getTableSchema('plugin_notifier_log', true));

    // Settings: the secret is stored encrypted and never returned
    $this->assertSame(422, $this->api('PUT', '/admin/plugins/notifier/settings', ['service' => 'icq'], $admin)['status']);
    $settings = $this->api('PUT', '/admin/plugins/notifier/settings', ['webhook_url' => 'https://hooks.slack.com/services/T/B/secret', 'service' => 'discord', 'username' => 'CMS'], $admin);
    $this->assertSame(200, $settings['status'], json_encode($settings['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['env' => null, 'stored' => true, 'set' => true], $settings['body']['data']['secrets']['webhook_url']);
    $this->assertStringNotContainsString('hooks.slack.com', json_encode($this->api('GET', '/admin/plugins', token: $admin)['body']));

    // Inactive: its step does not exist
    $slug = $this->uniqueSlug('news');
    $this->createEntity(['slug' => $slug, 'name' => 'News', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin);
    $event = ['name' => 'Melden', 'entity' => $slug, 'actions' => ['create'], 'steps' => [['type' => 'notifier.message', 'text' => 'Neu: {{record.title}} ({{event.name}})']]];
    $this->assertSame(422, $this->api('POST', '/admin/events', $event, $admin)['status']);

    // Active: the step is offered, checked and run
    $this->assertTrue($this->api('POST', '/admin/plugins/notifier/activate', token: $admin)['body']['data']['active']);
    $steps = $this->api('GET', '/admin/plugins/steps', token: $admin)['body']['data'];
    $this->assertSame(['notifier.message'], array_column($steps, 'type'));
    $this->assertSame('text', $steps[0]['fields'][0]['key']);
    $this->assertSame(422, $this->api('POST', '/admin/events', ['steps' => [['type' => 'notifier.message']]] + $event, $admin)['status'], 'the message is required');
    $created = $this->api('POST', '/admin/events', $event, $admin);
    $this->assertSame(200, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

    $this->createRecord($slug, ['title' => 'Sommerfest'], $admin);
    $this->assertSame('https://hooks.slack.com/services/T/B/secret', FakeWebhookSender::$calls[0]['url'] ?? null);
    $this->assertSame(['content' => 'Neu: Sommerfest (Melden)', 'username' => 'CMS'], FakeWebhookSender::$calls[0]['body']);

    // Its panel shows the log
    $plugin = $this->api('GET', '/admin/plugins/notifier', token: $admin)['body']['data'];
    $this->assertSame('Last messages', $plugin['panels'][0]['title']);
    $this->assertSame('Neu: Sommerfest (Melden)', $plugin['panels'][0]['rows'][0]['message']);

    // Deactivated: the data stays, the step is gone
    $this->api('POST', '/admin/plugins/notifier/deactivate', token: $admin);
    $this->assertSame([], $this->api('GET', '/admin/plugins/steps', token: $admin)['body']['data']);
    $this->assertNotNull($this->db()->getTableSchema('plugin_notifier_log', true));

    // Uninstalled: the table is dropped, the files are removed
    $this->assertSame(200, $this->api('DELETE', '/admin/plugins/notifier', token: $admin)['status']);
    $this->assertNull($this->db()->getTableSchema('plugin_notifier_log', true));
    $this->assertDirectoryDoesNotExist(self::DIR.'/notifier');
    $this->assertSame(404, $this->api('GET', '/admin/plugins/notifier', token: $admin)['status']);
  }

  public function testABrokenPluginIsNotSwitchedOn(): void
  {
    $admin = $this->login();
    $zip = self::zip([
      'broken/plugin.json' => '{"name":"broken","label":"Kaputt","version":"0.1","class":"Broken\\\\Plugin","autoload":{"Broken\\\\":"src/"}}',
      'broken/src/Plugin.php' => '<?php namespace Broken; final class Plugin implements \App\Plugin\PluginInterface { public function register(\App\Plugin\PluginRegistry $r, \App\Plugin\PluginContext $c): void { throw new \RuntimeException("kaputt"); } }',
    ]);
    $this->assertSame(200, $this->upload('/admin/plugins/upload', $zip, 'broken.zip', $admin)['status']);
    $this->api('POST', '/admin/plugins/broken/install', token: $admin);
    $activated = $this->api('POST', '/admin/plugins/broken/activate', token: $admin);
    $this->assertSame(422, $activated['status']);
    $this->assertStringContainsString('kaputt', $activated['body']['error']);
    $plugin = $this->api('GET', '/admin/plugins/broken', token: $admin)['body']['data'];
    $this->assertFalse($plugin['active']);
    $this->assertStringContainsString('kaputt', (string)$plugin['error']);
    $this->api('DELETE', '/admin/plugins/broken', token: $admin);
  }

  public function testPluginsAddStorageTypes(): void
  {
    $admin = $this->login();
    $zip = self::zip([
      'plugin.json' => '{"name":"folder-store","label":"Folder","version":"1.0","scope":"global","class":"FolderStore\\\\Plugin","autoload":{"FolderStore\\\\":"src/"}}',
      'src/Plugin.php' => '<?php namespace FolderStore; final class Plugin implements \App\Plugin\PluginInterface { public function register(\App\Plugin\PluginRegistry $r, \App\Plugin\PluginContext $c): void {
        $r->storageType("folder", "Another folder", [["key" => "path", "kind" => "text", "required" => true], ["key" => "token", "kind" => "secret"]], static fn(string $name, array $o) => new \League\Flysystem\Local\LocalFilesystemAdapter(dirname(__DIR__, 4)."/".$o["path"], lazyRootCreation: true));
      } }',
    ]);
    $this->assertSame(200, $this->upload('/admin/plugins/upload', $zip, 'folder.zip', $admin)['status']);
    $this->api('POST', '/admin/plugins/folder-store/install', token: $admin);
    $this->assertNotContains('folder', array_column($this->api('GET', '/admin/storages/types', token: $admin)['body']['data']['types'], 'type'), 'inactive: no type');
    // An admin plugin (scope "global"): active in every project at once
    $global = $this->api('POST', '/admin/plugins/folder-store/activate', token: $admin)['body']['data'];
    $this->assertSame(['global', true, []], [$global['scope'], $global['active'], $global['projects']]);
    $type = array_column($this->api('GET', '/admin/storages/types', token: $admin)['body']['data']['types'], null, 'type')['folder'] ?? null;
    $this->assertSame(['Another folder', 'server', 'folder-store'], [$type['label'] ?? null, $type['group'] ?? null, $type['plugin'] ?? null]);

    // A storage of the type: settings checked, the secret encrypted, the test writes through the plugin
    $name = 'p'.bin2hex(random_bytes(4));
    $this->assertSame(422, $this->api('POST', '/admin/storages', ['name' => $name, 'type' => 'folder', 'settings' => []], $admin)['status']);
    $storage = $this->api('POST', '/admin/storages', ['name' => $name, 'type' => 'folder', 'settings' => ['path' => 'runtime/test-plugin-store'], 'secrets' => ['token' => 'geheim']], $admin)['body']['data'];
    $this->assertTrue($storage['secrets']['token']['stored']);
    $this->assertSame(['ok' => true, 'error' => null], $this->api('POST', "/admin/storages/{$storage['id']}/test", token: $admin)['body']['data']);

    // Deactivated: the type is gone - the test says so
    $this->api('POST', '/admin/plugins/folder-store/deactivate', token: $admin);
    $this->assertFalse($this->api('POST', "/admin/storages/{$storage['id']}/test", token: $admin)['body']['data']['ok']);
    $this->api('DELETE', "/admin/storages/{$storage['id']}", token: $admin);
    $this->api('DELETE', '/admin/plugins/folder-store', token: $admin);
  }

  public function testPluginsThatNeedOthers(): void
  {
    $admin = $this->login();
    foreach (['dep-child', 'dep-base'] as $left) {
      if (($this->api('GET', "/admin/plugins/{$left}", token: $admin)['body']['data']['installed'] ?? false) === true) {
        $this->api('POST', "/admin/plugins/{$left}/deactivate", token: $admin);
        $this->api('DELETE', "/admin/plugins/{$left}", token: $admin);
      }
    }
    $plugin = static fn(string $name, string $class, string $version, array $requires = [], string $body = ''): string => self::zip([
      'plugin.json' => json_encode(['name' => $name, 'version' => $version, 'class' => $class.'\\Plugin', 'autoload' => [$class.'\\' => 'src/'], 'requires' => (object)$requires]),
      'src/Plugin.php' => '<?php namespace '.$class.'; use App\\Plugin\\{PluginInterface, PluginRegistry, PluginContext}; final class Plugin implements PluginInterface { public function register(PluginRegistry $r, PluginContext $c): void { '.$body.' } }',
    ]);
    $this->upload('/admin/plugins/upload', $plugin('dep-base', 'DepBase', '1.2.0', [], '$r->route("GET", "ping", static fn() => ["plugin" => "base"]);'), 'base.zip', $admin);
    $this->upload('/admin/plugins/upload', $plugin('dep-child', 'DepChild', '1.0.0', ['dep-base' => '^1.0'], '$r->route("GET", "ping", static fn() => ["plugin" => "child"]);'), 'child.zip', $admin);
    $child = $this->api('GET', '/admin/plugins/dep-child', token: $admin)['body']['data'];
    $this->assertSame(['dep-base' => '^1.0'], $child['requires']);

    // Install and activate: the plugin it needs first
    $this->assertSame(409, $this->api('POST', '/admin/plugins/dep-child/install', token: $admin)['status']);
    $this->api('POST', '/admin/plugins/dep-base/install', token: $admin);
    $this->assertSame(200, $this->api('POST', '/admin/plugins/dep-child/install', token: $admin)['status']);
    $this->assertSame(['dep-child'], $this->api('GET', '/admin/plugins/dep-base', token: $admin)['body']['data']['required_by']);
    $this->assertSame(409, $this->api('POST', '/admin/plugins/dep-child/activate', token: $admin)['status'], 'dep-base is not active in "main"');
    $this->api('POST', '/admin/plugins/dep-base/activate', token: $admin);
    $this->assertSame(200, $this->api('POST', '/admin/plugins/dep-child/activate', token: $admin)['status']);
    $ping = $this->api('GET', '/plugins/dep-child/api/ping', token: $admin);
    $this->assertSame(['plugin' => 'child'], $ping['body']['data'] ?? null, json_encode($ping['body'], JSON_UNESCAPED_UNICODE));

    // Switching it off or removing it while it is needed: refused
    $this->assertSame(409, $this->api('POST', '/admin/plugins/dep-base/deactivate', token: $admin)['status']);
    $this->assertSame(409, $this->api('DELETE', '/admin/plugins/dep-base', token: $admin)['status']);
    $this->api('POST', '/admin/plugins/dep-child/deactivate', token: $admin);
    $this->assertSame(200, $this->api('POST', '/admin/plugins/dep-base/deactivate', token: $admin)['status']);

    // A version that does not fit
    $this->api('DELETE', '/admin/plugins/dep-child', token: $admin);
    $this->upload('/admin/plugins/upload', $plugin('dep-child', 'DepChild', '1.0.1', ['dep-base' => '^2.0']), 'child.zip', $admin);
    $refused = $this->api('POST', '/admin/plugins/dep-child/install', token: $admin);
    $this->assertSame(409, $refused['status']);
    $this->assertStringContainsString('dep-base ^2.0', $refused['body']['error_message']);
    $this->api('DELETE', '/admin/plugins/dep-base', token: $admin);
  }

  /**
   * ZIP of a plugin of the plugins project (as `make plugin-zip`: in its folder).
   */
  private function zipOf(string $name): string
  {
    $files = [];
    $base = $this->pluginSource($name);
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $files[$name.'/'.substr($file->getPathname(), strlen($base) + 1)] = (string)file_get_contents($file->getPathname());
    }
    return self::zip($files);
  }

  /**
   * @param array<string, string> $files path => content
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

  private function db(): ConnectionInterface
  {
    return $this->container()->get(ConnectionInterface::class);
  }
}
