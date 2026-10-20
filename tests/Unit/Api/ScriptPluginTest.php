<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Infrastructure\Webhook\WebhookSender;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FakeWebhookSender;
use ZipArchive;

/**
 * The plugin "script" (excellent-plugins/script): the step "Modify values" changes the record and sets
 * values of its own - the next steps get them (StepRun::pass()), also in the test run and after a
 * retry. Scripts with syntax errors are refused when the event is saved.
 */
class ScriptPluginTest extends ApiTestCase
{
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

  public function testModifiedValuesReachTheNextSteps(): void
  {
    $admin = $this->login();
    // Left over from an earlier run (installed or only uploaded): the files of this checkout
    $this->api('DELETE', '/admin/plugins/script', token: $admin);
    $this->assertSame(200, $this->upload('/admin/plugins/upload', $this->zipOf(), 'script.zip', $admin)['status']);
    $this->api('POST', '/admin/plugins/script/install', token: $admin);
    $project = 's'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Script', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $here = ['X-Project' => $project];
    $this->assertSame(200, $this->api('POST', '/admin/plugins/script/activate', token: $admin, headers: $here)['status']);
    $this->api('POST', '/admin/entities', ['slug' => 'people', 'name' => 'People', 'fields' => [['name' => 'name', 'type' => 'string']]], $admin, $here);
    $this->api('POST', '/admin/entities', ['slug' => 'logs', 'name' => 'Logs', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin, $here);

    $broken = $this->api('POST', '/admin/events', ['name' => 'X', 'entity' => 'people', 'actions' => ['create'], 'steps' => [['type' => 'script.modify', 'script' => "set a = 1\nset b = record.name.eval()"]]], $admin, $here);
    $this->assertSame(422, $broken['status']);
    $this->assertStringContainsString('Line 2', implode(' ', $broken['body']['error_data']['steps'] ?? []));

    $event = $this->api('POST', '/admin/events', ['name' => 'Names', 'entity' => 'people', 'actions' => ['create'], 'steps' => [
      ['type' => 'script.modify', 'script' => "set record.short = record.name.split().first().uppercase()\nset names = record.name.split()\nvar words = names.length()\nset n = words + 0"],
      ['type' => 'webhook', 'url' => 'https://example.com/hook'],
      ['type' => 'create', 'entity' => 'logs', 'data' => ['title' => '{{record.short}} / {{names[1]}} / {{n}}{{words}}']],
    ]], $admin, $here);
    $this->assertSame(200, $event['status'], json_encode($event['body'], JSON_UNESCAPED_UNICODE));
    $eventId = $event['body']['data']['id'];

    // The webhook fails and stops the run: the retry still has the values of the step that is done
    FakeWebhookSender::$status = 500;
    $person = $this->api('POST', '/entities/people/records', ['name' => 'jane doe'], $admin, $here)['body']['data'];
    $this->assertSame([], $this->api('GET', '/entities/logs/records', token: $admin, headers: $here)['body']['data']);
    $this->assertSame(['JANE', ['jane', 'doe']], [FakeWebhookSender::$calls[0]['body']['records'][0]['data']['short'] ?? null, FakeWebhookSender::$calls[0]['body']['records'][0]['vars']['names'] ?? null]);
    $run = $this->api('GET', "/admin/events/{$eventId}/runs", token: $admin, headers: $here)['body']['data'][0];
    FakeWebhookSender::$status = 200;
    $this->api('POST', "/admin/event-runs/{$run['id']}/retry", [], $admin, $here);
    $this->assertSame(['JANE / doe / 2'], array_column($this->api('GET', '/entities/logs/records', token: $admin, headers: $here)['body']['data'], 'title'));
    // The record itself is not changed
    $this->assertSame('jane doe', $this->api('GET', "/entities/people/records/{$person['id']}", token: $admin, headers: $here)['body']['data']['name']);

    // Test run: the step shows what it passes on, the next steps use it
    $test = $this->api('POST', "/admin/events/{$eventId}/test", ['record' => $person['id']], $admin, $here)['body']['data'];
    $this->assertSame(['jane', 'doe'], $test['steps'][0]['preview'][0]['names']);
    $this->assertSame('JANE / doe / 2', $test['steps'][2]['preview'][0]['data']['title']);

    // return <value>: the next step gets only that as record
    $updated = $this->api('PUT', "/admin/events/{$eventId}", ['steps' => [
      ['type' => 'script.modify', 'script' => "set names = record.name.split()\nreturn {name: record.name.uppercase(), parts: names.length()}"],
      ['type' => 'create', 'entity' => 'logs', 'data' => ['title' => '{{record.name}} {{record.parts}} {{names}}']],
    ]], $admin, $here);
    $this->assertSame(200, $updated['status'], json_encode($updated['body'], JSON_UNESCAPED_UNICODE));
    $test = $this->api('POST', "/admin/events/{$eventId}/test", ['record' => $person['id']], $admin, $here)['body']['data'];
    $this->assertSame([['record' => ['name' => 'JANE DOE', 'parts' => 2]]], $test['steps'][0]['preview']);
    $this->assertSame('JANE DOE 2 ', $test['steps'][1]['preview'][0]['data']['title']);

    // unset removes values of earlier steps too
    $updated = $this->api('PUT', "/admin/events/{$eventId}", ['steps' => [
      ['type' => 'find', 'entity' => 'people', 'where' => ['name' => ['like' => 'jane']], 'as' => 'author'],
      ['type' => 'script.modify', 'script' => "set seen = author.name\nunset author"],
      ['type' => 'create', 'entity' => 'logs', 'data' => ['title' => '{{seen}}/{{author.name}}']],
    ]], $admin, $here);
    $this->assertSame(200, $updated['status'], json_encode($updated['body'], JSON_UNESCAPED_UNICODE));
    $test = $this->api('POST', "/admin/events/{$eventId}/test", ['record' => $person['id']], $admin, $here)['body']['data'];
    $this->assertSame('jane doe/', $test['steps'][2]['preview'][0]['data']['title']);
  }

  private function zipOf(): string
  {
    $source = $this->pluginSource('script');
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $zip->addFromString('script/'.substr($file->getPathname(), strlen($source) + 1), (string)file_get_contents($file->getPathname()));
    }
    $zip->close();
    $content = (string)file_get_contents($path);
    unlink($path);
    return $content;
  }
}
