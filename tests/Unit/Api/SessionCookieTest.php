<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use Fixtures\Fixture;
use App\Tests\Support\ApiTestCase;

/**
 * The login of the admin app is an httpOnly cookie: it counts only with the header of the admin app
 * (other sites cannot send it), scripts keep using the bearer token of the answer.
 */
class SessionCookieTest extends ApiTestCase
{
  public function testLoginCookieOnlyWithTheHeaderOfTheAdminApp(): void
  {
    $login = $this->api('POST', '/auth/login', ['email' => Fixture::ADMIN, 'password' => Fixture::ADMIN_PASSWORD]);
    $cookie = $login['headers']['Set-Cookie'] ?? '';
    $this->assertMatchesRegularExpression('/^cms_session=[^;]+; Path=\\/; Max-Age=\\d+; HttpOnly; SameSite=Strict$/', $cookie);
    $this->assertNotEmpty($login['body']['data']['token'], 'scripts still get the token');
    $session = explode(';', $cookie)[0];

    $this->assertSame(200, $this->api('GET', '/auth/me', headers: ['Cookie' => $session, 'X-Requested-With' => 'excellent-admin'])['status']);
    // Without the header (e.g. a form of another site) the cookie does not count
    $this->assertSame(401, $this->api('GET', '/auth/me', headers: ['Cookie' => $session])['status']);
    $this->assertSame(401, $this->api('GET', '/auth/me', headers: ['Cookie' => 'cms_session=nonsense', 'X-Requested-With' => 'excellent-admin'])['status']);

    // Over https: Secure
    $this->assertStringEndsWith('; Secure', $this->api('POST', '/auth/login', ['email' => Fixture::ADMIN, 'password' => Fixture::ADMIN_PASSWORD], headers: ['X-Forwarded-Proto' => 'https'])['headers']['Set-Cookie'] ?? '');

    // Signing out removes it
    $logout = $this->api('POST', '/auth/logout', [], headers: ['Cookie' => $session, 'X-Requested-With' => 'excellent-admin']);
    $this->assertStringStartsWith('cms_session=; Path=/; Max-Age=0', $logout['headers']['Set-Cookie'] ?? '');
  }
}
