<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Middleware\CorsMiddleware;
use App\Tests\Support\ApiTestCase;

/**
 * The public API answers every website ("*"); the routes of the admin app only the origins of
 * ADMIN_ORIGINS (by default none - the admin app runs on the same address).
 */
class CorsTest extends ApiTestCase
{
  protected function _after(): void
  {
    unset($_ENV['ADMIN_ORIGINS']);
  }

  public function testPublicApiForEveryoneAdminOnlyForItsOrigins(): void
  {
    foreach (['/api/v1/main/content', '/api/v1/main/content/books/1', '/v1/main/media/x', '/api/v1/main/plugins/forms/submit/contact', '/api/v1/main/variables', '/api/v1/oauth/token', '/api/v1/main/oauth/token'] as $path) {
      $this->assertTrue(CorsMiddleware::isPublic($path), $path);
    }
    // A project is never named like a route of the admin app
    foreach (['/api/v1/admin/plugins', '/api/v1/admin/content', '/api/v1/auth/me', '/api/v1/entities/books/records', '/api/v1/media', '/api/v1/plugins/forms/assets/x.js', '/api/v1/maincontent'] as $path) {
      $this->assertFalse(CorsMiddleware::isPublic($path), $path);
    }

    $origin = ['Origin' => 'https://www.example.com'];
    $content = $this->api('GET', '/main/content', headers: $origin);
    $this->assertSame('*', $content['headers']['Access-Control-Allow-Origin'] ?? null);
    $preflight = $this->api('OPTIONS', '/main/plugins/forms/submit/contact', headers: $origin + ['Access-Control-Request-Method' => 'POST']);
    $this->assertSame([204, '*'], [$preflight['status'], $preflight['headers']['Access-Control-Allow-Origin'] ?? null]);

    // Admin routes: no CORS for other sites - browsers refuse to call them from there
    $admin = $this->login();
    $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $this->api('GET', '/auth/me', token: $admin, headers: $origin)['headers']);
    $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $this->api('OPTIONS', '/auth/login', headers: $origin)['headers']);

    // Allowed origins (e.g. an admin app on another address) get their own origin back
    $_ENV['ADMIN_ORIGINS'] = 'https://admin.example.com, https://other.example.com/';
    $allowed = $this->api('GET', '/auth/me', token: $admin, headers: ['Origin' => 'https://other.example.com']);
    $this->assertSame('https://other.example.com', $allowed['headers']['Access-Control-Allow-Origin'] ?? null);
    $this->assertStringContainsString('Origin', $allowed['headers']['Vary'] ?? '');
    $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $this->api('GET', '/auth/me', token: $admin, headers: $origin)['headers']);
  }
}
