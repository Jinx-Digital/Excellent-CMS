<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Fields can be left out of the text search (?s=) and out of filters - by default they are in both.
 */
class SearchFilterFieldTest extends ApiTestCase
{
  public function testFieldsOutOfSearchAndFilters(): void
  {
    $admin = $this->login();
    $slug = $this->uniqueSlug('articles');
    $entity = $this->createEntity(['slug' => $slug, 'name' => 'Artikel', 'access' => 'public', 'fields' => [
      ['name' => 'title', 'type' => 'string'],
      // Not searched, but filters work
      ['name' => 'internal', 'type' => 'string', 'searchable' => false],
      // Neither filtered nor searched
      ['name' => 'secret', 'type' => 'string', 'filterable' => false],
    ]], $admin);
    $fields = array_column($entity['fields'], null, 'name');
    $this->assertSame([true, true], [$fields['title']['searchable'], $fields['title']['filterable']], 'searchable and filterable by default');
    $this->assertSame([false, true], [$fields['internal']['searchable'], $fields['internal']['filterable']]);
    $this->assertSame([false, false], [$fields['secret']['searchable'], $fields['secret']['filterable']], 'not filterable = not searched either');

    $this->createRecord($slug, ['title' => 'Sommerfest', 'internal' => 'notiz-blau', 'secret' => 'code-rot'], $admin);
    $titles = fn(string $query, ?string $token = null): array => array_column($this->api('GET', "/main/content/{$slug}?{$query}", token: $token)['body']['data'] ?? [], 'title');

    // Content API
    $this->assertSame(['Sommerfest'], $titles('s=sommer'));
    $this->assertSame([], $titles('s=notiz-blau'), 'not searchable');
    $this->assertSame(['Sommerfest'], $titles('filter[internal]=notiz-blau'), 'but filterable');
    $this->assertSame([], $titles('s=code-rot'));
    $refused = $this->api('GET', "/main/content/{$slug}?filter[secret]=code-rot");
    $this->assertSame(422, $refused['status']);
    $this->assertStringContainsString('not filterable', json_encode($refused['body']));
    // Admin app: the same
    $this->assertSame(422, $this->api('GET', "/entities/{$slug}/records?filter[secret]=code-rot", token: $admin)['status']);
    $this->assertSame([], $this->api('GET', "/entities/{$slug}/records?s=notiz-blau", token: $admin)['body']['data']);

    // Event conditions are no API filters: they may use every field
    $event = $this->api('POST', '/admin/events', ['name' => 'Geheim', 'entity' => $slug, 'actions' => ['update'], 'condition' => '{"secret": "code-rot"}', 'steps' => [['type' => 'webhook', 'url' => 'https://example.com/hook']]], $admin);
    $this->assertSame(200, $event['status'], json_encode($event['body'], JSON_UNESCAPED_UNICODE));

    // Switched back on
    $this->api('PUT', "/admin/entities/{$entity['id']}/fields/{$fields['secret']['id']}", ['filterable' => true], $admin);
    $this->assertSame(['Sommerfest'], $titles('filter[secret]=code-rot'));
    $this->assertSame(['Sommerfest'], $titles('s=code-rot'));
  }
}
