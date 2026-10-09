<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Infrastructure\Webhook\WebhookSender;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FakeWebhookSender;
use Yiisoft\Queue\Provider\QueueConsumerProviderInterface;

/**
 * Events: trigger + condition + steps, one run per request, chains, queue, progress and retries.
 */
class EventTest extends ApiTestCase
{
  private const MAIL_LOG = __DIR__.'/../../../runtime/test-mail.log';

  protected function _before(): void
  {
    static::$overrides = [WebhookSender::class => FakeWebhookSender::class];
    FakeWebhookSender::$calls = [];
    FakeWebhookSender::$status = 200;
    @unlink(self::MAIL_LOG);
  }

  protected function _after(): void
  {
    static::$overrides = [];
  }

  public function testProductEventCreatesVariantAndCallsWebhook(): void
  {
    $admin = $this->login();
    $project = 'e'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Shop', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $here = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'products', 'name' => 'Produkte', 'drafts' => true, 'fields' => [
      ['name' => 'title', 'type' => 'string'], ['name' => 'price', 'type' => 'decimal'],
    ]], $admin, $here);
    $this->api('POST', '/admin/entities', ['slug' => 'variants', 'name' => 'Varianten', 'fields' => [
      ['name' => 'product', 'type' => 'reference', 'reference' => 'products'], ['name' => 'title', 'type' => 'string', 'required' => true], ['name' => 'price', 'type' => 'decimal'],
    ]], $admin, $here);

    $invalid = $this->api('POST', '/admin/events', ['name' => 'X', 'entity' => 'products', 'actions' => ['boom'], 'condition' => '{"nope": 1}', 'steps' => '[{"type": "fly"}]'], $admin, $here);
    $this->assertSame(422, $invalid['status'], json_encode($invalid['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(['actions', 'condition', 'steps'], array_keys($invalid['body']['error_data']));

    $event = $this->api('POST', '/admin/events', [
      'name' => 'Variante zu neuem Produkt',
      'entity' => 'products',
      'actions' => ['create', 'publish'],
      'condition' => '{"draft": false, "price": {"gte": 10}}',
      'steps' => [
        ['type' => 'create', 'entity' => 'variants', 'data' => ['product' => '{{record.id}}', 'title' => '{{record.title}} – Standard', 'price' => '{{record.price}}']],
        ['type' => 'webhook', 'url' => 'https://shop.example.com/hook', 'secret' => 'geheim'],
        ['type' => 'email', 'to' => 'team@example.com', 'subject' => 'Neu: {{record.title}} ({{count}})', 'body' => '{{record.title}}: {{record.price}} €', 'digest' => true],
      ],
    ], $admin, $here);
    $this->assertSame(200, $event['status'], json_encode($event['body'], JSON_UNESCAPED_UNICODE));
    $event = $event['body']['data'];
    $this->assertSame(403, $this->api('GET', '/admin/events', token: $this->editorToken(), headers: $here)['status']);

    // Draft or too cheap: nothing
    $draft = $this->api('POST', '/entities/products/records', ['title' => 'Entwurf', 'price' => 50, 'draft' => true], $admin, $here)['body']['data'];
    $this->api('POST', '/entities/products/records', ['title' => 'Billig', 'price' => 5], $admin, $here);
    $this->assertSame([], FakeWebhookSender::$calls);

    // Published and from 10: variant, webhook (signed), mail
    $shoe = $this->api('POST', '/entities/products/records', ['title' => 'Schuh', 'price' => 20], $admin, $here)['body']['data'];
    $variants = $this->api('GET', '/entities/variants/records', token: $admin, headers: $here)['body']['data'];
    $this->assertSame([['Schuh – Standard', 20.0, $shoe['id']]], array_map(static fn(array $v): array => [$v['title'], (float)$v['price'], $v['product']], $variants));
    $this->assertSame('event:'.$event['id'], $variants[0]['created_by']['type'].':'.$variants[0]['created_by']['id']);
    $this->assertCount(1, FakeWebhookSender::$calls);
    $call = FakeWebhookSender::$calls[0];
    $this->assertSame([1, 'Schuh', 'create'], [$call['body']['count'], $call['body']['records'][0]['data']['title'], $call['body']['records'][0]['action']]);
    $this->assertSame('sha256='.hash_hmac('sha256', $call['raw'], 'geheim'), $call['headers']['X-Excellent-Signature']);
    // The entity comes along: slug, name and fields
    $this->assertSame(['products', 'Produkte'], [$call['body']['entity']['slug'], $call['body']['entity']['name']]);
    $this->assertSame(['title', 'price'], array_column($call['body']['entity']['fields'], 'name'));
    $this->assertStringContainsString('Neu: Schuh (1)', (string)file_get_contents(self::MAIL_LOG));

    // Publishing the draft starts it too ("publish")
    $this->api('PUT', "/entities/products/records/{$draft['id']}", ['draft' => false], $admin, $here);
    $this->assertCount(2, FakeWebhookSender::$calls);

    // An import of 3 products: ONE run with 3 records - one webhook call, 3 variants
    $upload = $this->upload('/imports', "title,price\nA,11\nB,12\nC,13\n", 'p.csv', $admin, headers: $here)['body']['data'];
    $this->api('POST', "/imports/{$upload['import_id']}/run", ['delimiter' => $upload['delimiter'], 'mode' => 'existing', 'target' => 'products', 'columns' => [['column' => 'title', 'field' => 'title'], ['column' => 'price', 'field' => 'price']]], $admin, $here);
    $this->assertCount(3, FakeWebhookSender::$calls);
    $this->assertSame(3, FakeWebhookSender::$calls[2]['body']['count']);
    $this->assertSame(5, count($this->api('GET', '/entities/variants/records', token: $admin, headers: $here)['body']['data']));
    $runs = $this->api('GET', "/admin/events/{$event['id']}/runs", token: $admin, headers: $here)['body']['data'];
    $this->assertSame(['done', 3, ['done', 'done', 'done'], [3, 1, 1]], [$runs[0]['status'], $runs[0]['count'], array_column($runs[0]['steps'], 'status'), array_column($runs[0]['steps'], 'done')]);

    // A failing step stops the run; the retry skips what is done (no second variant)
    FakeWebhookSender::$status = 500;
    $this->api('POST', '/entities/products/records', ['title' => 'Hut', 'price' => 30], $admin, $here);
    $failed = $this->api('GET', "/admin/events/{$event['id']}/runs", token: $admin, headers: $here)['body']['data'][0];
    $this->assertSame(['failed', ['done', 'failed', 'waiting']], [$failed['status'], array_column($failed['steps'], 'status')]);
    $this->assertStringContainsString('HTTP 500', $failed['error']);
    FakeWebhookSender::$status = 200;
    $retried = $this->api('POST', "/admin/event-runs/{$failed['id']}/retry", [], $admin, $here)['body']['data'];
    $this->assertSame(['done', ['done', 'done', 'done']], [$retried['status'], array_column($retried['steps'], 'status')]);
    $this->assertSame(1, count(array_filter($this->api('GET', '/entities/variants/records', token: $admin, headers: $here)['body']['data'], static fn(array $v): bool => 'Hut – Standard' === $v['title'])));

    // Test run: condition and what the steps would do - nothing is sent
    $calls = count(FakeWebhookSender::$calls);
    $test = $this->api('POST', "/admin/events/{$event['id']}/test", ['record' => $shoe['id']], $admin, $here)['body']['data'];
    $this->assertTrue($test['matches']);
    $this->assertSame('Schuh – Standard', $test['steps'][0]['preview'][0]['data']['title']);
    // Without an id: the record changed last
    $latest = $this->api('POST', "/admin/events/{$event['id']}/test", ['record' => ''], $admin, $here);
    $this->assertSame(200, $latest['status'], json_encode($latest['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame('Hut – Standard', $latest['body']['data']['steps'][0]['preview'][0]['data']['title']);
    $this->assertCount($calls, FakeWebhookSender::$calls);
  }

  public function testContinueOnError(): void
  {
    $admin = $this->login();
    $project = 'c'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Shop', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $here = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'orders', 'name' => 'Orders', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin, $here);
    $this->api('POST', '/admin/entities', ['slug' => 'logs', 'name' => 'Logs', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin, $here);
    $event = $this->api('POST', '/admin/events', ['name' => 'Order', 'entity' => 'orders', 'actions' => ['create'], 'steps' => [
      ['type' => 'webhook', 'url' => 'https://shop.example.com/hook', 'continue_on_error' => true],
      ['type' => 'create', 'entity' => 'logs', 'data' => ['title' => 'Log {{record.title}}']],
      ['type' => 'webhook', 'url' => 'https://shop.example.com/second'],
      ['type' => 'create', 'entity' => 'logs', 'data' => ['title' => 'Never']],
    ]], $admin, $here)['body']['data'];

    // The first webhook fails but may: the next steps run; the third fails and stops the run
    FakeWebhookSender::$status = 500;
    $this->api('POST', '/entities/orders/records', ['title' => 'A'], $admin, $here);
    $run = $this->api('GET', "/admin/events/{$event['id']}/runs", token: $admin, headers: $here)['body']['data'][0];
    $this->assertSame(['failed', ['failed', 'done', 'failed', 'waiting']], [$run['status'], array_column($run['steps'], 'status')]);
    $this->assertSame(['Log A'], array_column($this->api('GET', '/entities/logs/records', token: $admin, headers: $here)['body']['data'], 'title'));

    // A retry repeats only what is not done
    FakeWebhookSender::$status = 200;
    $retried = $this->api('POST', "/admin/event-runs/{$run['id']}/retry", [], $admin, $here)['body']['data'];
    $this->assertSame(['done', ['done', 'done', 'done', 'done']], [$retried['status'], array_column($retried['steps'], 'status')]);
    $this->assertEqualsCanonicalizing(['Log A', 'Never'], array_column($this->api('GET', '/entities/logs/records', token: $admin, headers: $here)['body']['data'], 'title'));
  }

  public function testChainsQueueAndLoops(): void
  {
    $admin = $this->login();
    $project = 'q'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Kette', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $here = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'orders', 'name' => 'Bestellungen', 'fields' => [['name' => 'title', 'type' => 'string'], ['name' => 'count', 'type' => 'integer']]], $admin, $here);
    $this->api('POST', '/admin/entities', ['slug' => 'logs', 'name' => 'Protokoll', 'fields' => [['name' => 'text', 'type' => 'string']]], $admin, $here);

    // orders -> logs (queue), logs -> webhook (chain), orders update -> update orders (loop guard)
    $toLog = $this->api('POST', '/admin/events', ['name' => 'Protokollieren', 'entity' => 'orders', 'actions' => ['create'], 'mode' => 'queue',
      'steps' => [['type' => 'create', 'entity' => 'logs', 'data' => ['text' => 'Neu: {{record.title}}']]]], $admin, $here)['body']['data'];
    $this->api('POST', '/admin/events', ['name' => 'Melden', 'entity' => 'logs', 'actions' => ['create'],
      'steps' => [['type' => 'webhook', 'url' => 'https://example.com/log']]], $admin, $here);
    $this->api('POST', '/admin/events', ['name' => 'Zählen', 'entity' => 'orders', 'actions' => ['update'], 'condition' => ['_changed' => ['title']],
      'steps' => [['type' => 'update', 'entity' => 'orders', 'where' => ['id' => '{{record.id}}'], 'data' => ['count' => 1]]]], $admin, $here);

    // Queue mode: waits for the worker
    $order = $this->api('POST', '/entities/orders/records', ['title' => 'A'], $admin, $here)['body']['data'];
    $this->assertSame('queued', $this->api('GET', "/admin/events/{$toLog['id']}/runs", token: $admin, headers: $here)['body']['data'][0]['status']);
    $this->assertSame([], $this->api('GET', '/entities/logs/records', token: $admin, headers: $here)['body']['data']);
    $this->container()->get(QueueConsumerProviderInterface::class)->getConsumer('events')->run();
    $this->assertSame(['Neu: A'], array_column($this->api('GET', '/entities/logs/records', token: $admin, headers: $here)['body']['data'], 'text'));
    $this->assertSame('done', $this->api('GET', "/admin/events/{$toLog['id']}/runs", token: $admin, headers: $here)['body']['data'][0]['status']);
    // ... and the log started its own event (one level deeper)
    $this->assertSame('https://example.com/log', FakeWebhookSender::$calls[0]['url'] ?? null);

    // An event that changes its own entity does not start itself again
    $this->api('PUT', "/entities/orders/records/{$order['id']}", ['title' => 'B'], $admin, $here);
    $this->assertSame(1, $this->api('GET', "/entities/orders/records/{$order['id']}", token: $admin, headers: $here)['body']['data']['count']);
  }

  public function testWebhookPerRecord(): void
  {
    $admin = $this->login();
    $project = 'd'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Einzeln', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $here = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'items', 'name' => 'Artikel', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin, $here);
    $this->api('POST', '/admin/events', ['name' => 'Einzeln', 'entity' => 'items', 'actions' => ['create'],
      'steps' => [['type' => 'webhook', 'url' => 'https://example.com/items/{{record.title}}', 'digest' => false]]], $admin, $here);

    $upload = $this->upload('/imports', "title\nA\nB\n", 'i.csv', $admin, headers: $here)['body']['data'];
    $this->api('POST', "/imports/{$upload['import_id']}/run", ['delimiter' => $upload['delimiter'], 'mode' => 'existing', 'target' => 'items', 'columns' => [['column' => 'title', 'field' => 'title']]], $admin, $here);
    // Two calls, one record each, the URL with placeholders (in any order)
    $this->assertEqualsCanonicalizing(['https://example.com/items/A', 'https://example.com/items/B'], array_column(FakeWebhookSender::$calls, 'url'));
    $this->assertSame([1, 1], array_map(static fn(array $c): int => $c['body']['count'], FakeWebhookSender::$calls));
  }

  public function testWebhookAuthWithEnvSecrets(): void
  {
    $_ENV['EVENT_TEST_TOKEN'] = 'token-123';
    $_ENV['EVENT_TEST_SECRET'] = 'signatur';
    $_ENV['EVENT_TEST_USER'] = 'shop';
    $_ENV['EVENT_TEST_QUERY'] = 'a b&c';
    $admin = $this->login();
    $project = 'a'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Auth', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $here = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'orders', 'name' => 'Bestellungen', 'fields' => [['name' => 'title', 'type' => 'string']]], $admin, $here);

    // The admin app gets the names of the usable variables, never their values
    $vars = $this->api('GET', '/admin/env-vars', token: $admin, headers: $here)['body']['data'];
    $names = array_column($vars, 'set', 'name');
    $this->assertTrue($names['EVENT_TEST_TOKEN']);
    $this->assertArrayHasKey('APP_ENV', $names, 'every variable');
    $this->assertStringNotContainsString('token-123', json_encode($vars));
    $this->assertSame(403, $this->api('GET', '/admin/env-vars', token: $this->editorToken(), headers: $here)['status'], 'admins only');

    // Variables that are not set are refused
    $step = ['type' => 'webhook', 'url' => 'https://example.com/orders'];
    $refused = $this->api('POST', '/admin/events', ['name' => 'X', 'entity' => 'orders', 'actions' => ['create'], 'steps' => [$step + ['auth' => ['type' => 'bearer', 'token' => '$EVENT_NOT_THERE']]]], $admin, $here);
    $this->assertSame(422, $refused['status']);
    $this->assertStringContainsString('EVENT_NOT_THERE', json_encode($refused['body']));
    $this->assertSame(422, $this->api('POST', '/admin/events', ['name' => 'X', 'entity' => 'orders', 'actions' => ['create'], 'steps' => [['type' => 'webhook', 'url' => 'https://example.com/?key=$EVENT_NOT_THERE']]], $admin, $here)['status'], 'also in the URL');

    $event = $this->api('POST', '/admin/events', ['name' => 'Shop', 'entity' => 'orders', 'actions' => ['create'], 'steps' => [
      ['type' => 'webhook', 'url' => 'https://example.com/orders?token=$EVENT_TEST_TOKEN&q=$EVENT_TEST_QUERY&title={{record.title}}&price=$$5', 'secret' => '$EVENT_TEST_SECRET', 'auth' => ['type' => 'bearer', 'token' => '$EVENT_TEST_TOKEN']],
      $step + ['auth' => ['type' => 'basic', 'username' => '$EVENT_TEST_USER', 'password' => '$$geheim']],
    ]], $admin, $here);
    $this->assertSame(200, $event['status'], json_encode($event['body'], JSON_UNESCAPED_UNICODE));
    // Stored as references, not as values
    $this->assertSame('$EVENT_TEST_TOKEN', $event['body']['data']['steps'][0]['auth']['token']);

    $this->api('POST', '/entities/orders/records', ['title' => 'Bestellung 1'], $admin, $here);
    [$bearer, $basic] = FakeWebhookSender::$calls;
    // Resolved when the step runs - in the URL encoded, after the placeholders of the record
    $this->assertSame('https://example.com/orders?token=token-123&q=a%20b%26c&title=Bestellung 1&price=$5', $bearer['url']);
    $this->assertSame('Bearer token-123', $bearer['headers']['Authorization']);
    $this->assertSame('sha256='.hash_hmac('sha256', $bearer['raw'], 'signatur'), $bearer['headers']['X-Excellent-Signature']);
    $this->assertSame('Basic '.base64_encode('shop:$geheim'), $basic['headers']['Authorization'], '$$ is a value starting with $');
    unset($_ENV['EVENT_TEST_TOKEN'], $_ENV['EVENT_TEST_SECRET'], $_ENV['EVENT_TEST_USER'], $_ENV['EVENT_TEST_QUERY']);
  }

  public function testMediaAndVariables(): void
  {
    $admin = $this->login();
    $project = 'm'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Quellen', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $here = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'logs', 'name' => 'Protokoll', 'fields' => [['name' => 'text', 'type' => 'string']]], $admin, $here);

    $this->assertSame(422, $this->api('POST', '/admin/events', ['name' => 'X', 'source' => 'media', 'actions' => ['publish'], 'steps' => [['type' => 'webhook', 'url' => 'https://example.com']]], $admin, $here)['status'], 'media cannot be published');
    $this->api('POST', '/admin/events', ['name' => 'Medien', 'source' => 'media', 'actions' => ['create', 'update', 'delete'], 'condition' => ['mime_type' => ['like' => 'image']],
      'steps' => [['type' => 'create', 'entity' => 'logs', 'data' => ['text' => '{{event.action}}: {{record.name}}']]]], $admin, $here);
    $variables = $this->api('POST', '/admin/events', ['name' => 'URL', 'source' => 'variables', 'actions' => ['update'], 'condition' => ['name' => 'url', '_changed' => ['value']],
      'steps' => [['type' => 'webhook', 'url' => 'https://example.com/url', 'digest' => true]]], $admin, $here);
    $this->assertSame(['variables', null], [$variables['body']['data']['source'], $variables['body']['data']['entity']]);

    // Media: upload (an image), rename, delete - a text file does not match the condition
    $image = $this->upload('/media', self::png(), 'logo.png', $admin, headers: $here)['body']['data'];
    $text = $this->upload('/media', "Hallo\n", 'notiz.txt', $admin, headers: $here)['body']['data'];
    $this->api('PATCH', "/media/{$image['id']}", ['name' => 'firmenlogo.png'], $admin, $here);
    $this->api('DELETE', "/media/{$image['id']}", token: $admin, headers: $here);
    $this->api('DELETE', "/media/{$text['id']}", token: $admin, headers: $here);
    $logs = array_column($this->api('GET', '/entities/logs/records?sort=created_at', token: $admin, headers: $here)['body']['data'], 'text');
    // Written within one second: created_at does not order them
    $this->assertEqualsCanonicalizing(['create: logo.png', 'update: firmenlogo.png', 'delete: firmenlogo.png'], $logs);

    // Variables: added, then changed - only the change of the url's value calls the webhook
    $this->api('PUT', '/admin/variables', ['variables' => [['name' => 'url', 'value' => 'https://alt.example'], ['name' => 'firma', 'value' => 'A']]], $admin, $here);
    $this->assertSame([], FakeWebhookSender::$calls);
    $this->api('PUT', '/admin/variables', ['variables' => [['name' => 'url', 'value' => 'https://neu.example'], ['name' => 'firma', 'value' => 'B']]], $admin, $here);
    $this->assertCount(1, FakeWebhookSender::$calls);
    $body = FakeWebhookSender::$calls[0]['body'];
    $this->assertSame(['variables', 1, 'https://neu.example', 'https://alt.example'], [$body['source'], $body['count'], $body['records'][0]['data']['value'], $body['records'][0]['old']['value']]);
  }

  private static function png(): string
  {
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    return (string)ob_get_clean();
  }

  public function testConditionOnTheAuthor(): void
  {
    // Author profiles: whatever user X creates gets the profile of X
    $admin = $this->login();
    $adminId = $this->api('GET', '/auth/me', token: $admin)['body']['data']['user']['id'];
    $project = 'a'.bin2hex(random_bytes(4));
    $this->api('POST', '/admin/projects', ['name' => 'Blog', 'slug' => $project, 'table_prefix' => $project.'_'], $admin);
    $here = ['X-Project' => $project];
    $this->api('POST', '/admin/entities', ['slug' => 'posts', 'name' => 'Beiträge', 'fields' => [['name' => 'title', 'type' => 'string'], ['name' => 'author', 'type' => 'string']]], $admin, $here);
    $event = $this->api('POST', '/admin/events', ['name' => 'Autor', 'entity' => 'posts', 'actions' => ['create'], 'condition' => ['created_by' => 'user:'.$adminId], 'steps' => [
      ['type' => 'update', 'entity' => 'posts', 'where' => ['id' => '{{record.id}}'], 'data' => ['author' => 'Profil Admin']],
    ]], $admin, $here);
    $this->assertSame(200, $event['status'], json_encode($event['body'], JSON_UNESCAPED_UNICODE));

    $mine = $this->api('POST', '/entities/posts/records', ['title' => 'Von mir'], $admin, $here)['body']['data'];
    $this->assertSame('Profil Admin', $this->api('GET', "/entities/posts/records/{$mine['id']}", token: $admin, headers: $here)['body']['data']['author']);
  }
}
