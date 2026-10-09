<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

class AccountTest extends ApiTestCase
{
  private const MAIL_LOG = __DIR__.'/../../../runtime/test-mail.log';

  protected function setUp(): void
  {
    parent::setUp();
    @unlink(self::MAIL_LOG);
  }

  public function testNewPasswordMustBeRepeated(): void
  {
    [, $token] = $this->newUser();
    $result = $this->api('POST', '/auth/change-password', ['current_password' => 'passwort123', 'new_password' => 'neuesPasswort1', 'new_password_confirmation' => 'neuesPasswort2'], $token);
    $this->assertSame(422, $result['status']);
    $this->assertArrayHasKey('new_password_confirmation', $result['body']['error_data']);
    $this->assertSame(200, $this->api('GET', '/auth/me', token: $token)['status'], 'nothing changed');
  }

  public function testForgottenPassword(): void
  {
    [$email, $session] = $this->newUser();

    // Unknown addresses get the same answer - and no mail
    $this->assertSame(200, $this->api('POST', '/auth/password/forgot', ['email' => 'niemand@example.com'])['status']);
    $this->assertSame('', $this->mails());
    $this->assertSame(422, $this->api('POST', '/auth/password/forgot', ['email' => 'keine-adresse'])['status']);

    $this->assertSame(200, $this->api('POST', '/auth/password/forgot', ['email' => strtoupper($email)])['status']);
    $mail = $this->mails();
    $this->assertStringContainsString("To: {$email}", $mail);
    $this->assertStringContainsString('Reset your password', $mail);
    $token = $this->linkToken($mail, 'http://cms.test/reset-password');

    $mismatch = $this->api('POST', '/auth/password/reset', ['token' => $token, 'password' => 'neuesPasswort1', 'password_confirmation' => 'anders123']);
    $this->assertSame(422, $mismatch['status']);
    $this->assertArrayHasKey('password_confirmation', $mismatch['body']['error_data']);
    $this->assertSame(422, $this->api('POST', '/auth/password/reset', ['token' => $token, 'password' => 'kurz', 'password_confirmation' => 'kurz'])['status']);

    $reset = $this->api('POST', '/auth/password/reset', ['token' => $token, 'password' => 'neuesPasswort1', 'password_confirmation' => 'neuesPasswort1']);
    $this->assertSame(200, $reset['status'], json_encode($reset['body'], JSON_UNESCAPED_UNICODE));
    $this->assertSame(200, $this->api('GET', '/auth/me', token: $reset['body']['data']['token'])['status'], 'logged in right away');
    $this->assertSame(401, $this->api('GET', '/auth/me', token: $session)['status'], 'other sessions end');
    $this->login($email, 'neuesPasswort1');

    // The link works only once
    $again = $this->api('POST', '/auth/password/reset', ['token' => $token, 'password' => 'nochEinmal1', 'password_confirmation' => 'nochEinmal1']);
    $this->assertSame([400, 'invalid_token'], [$again['status'], $again['body']['error_code']]);
  }

  public function testNewLinkReplacesTheOldOne(): void
  {
    [$email] = $this->newUser();
    $this->api('POST', '/auth/password/forgot', ['email' => $email]);
    $first = $this->linkToken($this->mails(), 'http://cms.test/reset-password');
    @unlink(self::MAIL_LOG);
    $this->api('POST', '/auth/password/forgot', ['email' => $email]);
    $second = $this->linkToken($this->mails(), 'http://cms.test/reset-password');

    $this->assertNotSame($first, $second);
    $this->assertSame(400, $this->api('POST', '/auth/password/reset', ['token' => $first, 'password' => 'neuesPasswort1', 'password_confirmation' => 'neuesPasswort1'])['status']);
    $this->assertSame(200, $this->api('POST', '/auth/password/reset', ['token' => $second, 'password' => 'neuesPasswort1', 'password_confirmation' => 'neuesPasswort1'])['status']);
  }

  public function testChangingTheEmailAddressNeedsConfirmation(): void
  {
    [$email, $token] = $this->newUser();
    $new = $this->uniqueSlug('neu').'@example.com';

    $wrong = $this->api('POST', '/auth/email', ['email' => 'admin@example.com', 'password' => 'falsch'], $token);
    $this->assertSame(422, $wrong['status']);
    $this->assertArrayHasKey('password', $wrong['body']['error_data']);
    $this->assertArrayHasKey('email', $wrong['body']['error_data'], 'address of another user');

    $requested = $this->api('POST', '/auth/email', ['email' => strtoupper($new), 'password' => 'passwort123'], $token);
    $this->assertSame([200, $new], [$requested['status'], $requested['body']['data']['pending_email']]);
    $me = $this->api('GET', '/auth/me', token: $token)['body']['data']['user'];
    $this->assertSame([$email, $new], [$me['email'], $me['pending_email']], 'the old address stays until confirmed');

    $mails = $this->mails();
    $this->assertStringContainsString("To: {$new}", $mails);
    $this->assertStringContainsString("To: {$email}", $mails, 'notice to the old address');
    $link = $this->linkToken($mails, 'http://cms.test/confirm-email');

    // Without login (other browser)
    $confirmed = $this->api('POST', '/auth/email/confirm', ['token' => $link]);
    $this->assertSame([200, $new], [$confirmed['status'], $confirmed['body']['data']['email']]);
    $me = $this->api('GET', '/auth/me', token: $token)['body']['data']['user'];
    $this->assertSame([$new, null], [$me['email'], $me['pending_email']], 'the session stays');
    $this->login($new, 'passwort123');
    $this->assertSame(400, $this->api('POST', '/auth/email/confirm', ['token' => $link])['status']);
  }

  public function testEmailChangeCanBeCancelledAndChecksTheAddressAgain(): void
  {
    [, $token] = $this->newUser();
    $new = $this->uniqueSlug('neu').'@example.com';
    $this->api('POST', '/auth/email', ['email' => $new, 'password' => 'passwort123'], $token);
    $link = $this->linkToken($this->mails(), 'http://cms.test/confirm-email');

    $this->assertSame(200, $this->api('DELETE', '/auth/email', token: $token)['status']);
    $this->assertNull($this->api('GET', '/auth/me', token: $token)['body']['data']['user']['pending_email']);
    $this->assertSame(400, $this->api('POST', '/auth/email/confirm', ['token' => $link])['status']);

    // Taken by somebody else in the meantime
    @unlink(self::MAIL_LOG);
    $this->api('POST', '/auth/email', ['email' => $new, 'password' => 'passwort123'], $token);
    $link = $this->linkToken($this->mails(), 'http://cms.test/confirm-email');
    $this->api('POST', '/admin/users', ['name' => 'Schneller', 'email' => $new, 'password' => 'passwort123'], $this->login());
    $taken = $this->api('POST', '/auth/email/confirm', ['token' => $link]);
    $this->assertSame([409, 'email_taken'], [$taken['status'], $taken['body']['error_code']]);
  }

  /**
   * @return array{0: string, 1: string} address and session token
   */
  private function newUser(): array
  {
    $email = $this->uniqueSlug('konto').'@example.com';
    $created = $this->api('POST', '/admin/users', ['name' => 'Konto', 'email' => $email, 'password' => 'passwort123'], $this->login());
    $this->assertSame(200, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
    return [$email, $this->login($email, 'passwort123')];
  }

  private function mails(): string
  {
    return is_file(self::MAIL_LOG) ? (string)file_get_contents(self::MAIL_LOG) : '';
  }

  private function linkToken(string $mails, string $url): string
  {
    $this->assertMatchesRegularExpression('#'.preg_quote($url, '#').'\?token=([A-Za-z0-9_-]{43})#', $mails);
    preg_match_all('#'.preg_quote($url, '#').'\?token=([A-Za-z0-9_-]{43})#', $mails, $matches);
    return (string)end($matches[1]);
  }
}
