<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class PreferenceTest extends ApiTestCase
{
  public function testEveryUserHasOwnSettings(): void
  {
    $admin = $this->login();
    $editor = $this->editorToken();
    $key = 'list:'.substr(md5(uniqid('', true)), 0, 10);

    $this->assertNull($this->api('GET', "/auth/preferences/{$key}", token: $admin)['body']['data']['value']);
    $saved = $this->api('PUT', "/auth/preferences/{$key}", ['value' => ['columns' => ['title', 'author'], 'limit' => 50]], $admin);
    $this->assertSame(200, $saved['status']);
    $this->assertSame(['columns' => ['title', 'author'], 'limit' => 50], $this->api('GET', "/auth/preferences/{$key}", token: $admin)['body']['data']['value']);
    // Not shared with other users
    $this->assertNull($this->api('GET', "/auth/preferences/{$key}", token: $editor)['body']['data']['value']);
    // Back to the defaults
    $this->api('PUT', "/auth/preferences/{$key}", ['value' => null], $admin);
    $this->assertNull($this->api('GET', "/auth/preferences/{$key}", token: $admin)['body']['data']['value']);

    $this->assertSame(422, $this->api('PUT', '/auth/preferences/'.rawurlencode('böse key'), ['value' => []], $admin)['status']);
    $this->assertSame(422, $this->api('PUT', "/auth/preferences/{$key}", ['value' => 'kein Objekt'], $admin)['status']);
    $this->assertSame(401, $this->api('GET', "/auth/preferences/{$key}")['status']);
  }
}
