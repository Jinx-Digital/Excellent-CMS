<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\User;
use App\Infrastructure\Mail\Mailer;
use App\Repository\UserRepository;
use App\Repository\UserTokenRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Yiisoft\Http\Status;
use Yiisoft\RequestProvider\RequestNotSetException;
use Yiisoft\RequestProvider\RequestProviderInterface;

/**
 * Own account of a CMS user: forgotten password and a new e-mail address, both with one-time
 * links by mail. Only hashes of the tokens are stored.
 */
final class AccountService
{
  public const PASSWORD_RESET = 'password_reset';
  public const EMAIL_CHANGE = 'email_change';
  private const PASSWORD_RESET_TTL = 3600;
  private const EMAIL_CHANGE_TTL = 86400;
  /** Reset mails per address / IP and hour - nobody floods an inbox through the CMS */
  private const MAX_RESET_MAILS = 5;

  public function __construct(
    private UserRepository $users,
    private UserTokenRepository $tokens,
    private Mailer $mailer,
    private RateLimiter $rateLimiter,
    private JwtService $jwt,
    private string $appUrl = '',
    private string $appName = 'Excellent CMS',
    private ?RequestProviderInterface $requestProvider = null,
  ) {
  }

  /**
   * Sends a reset link if there is an active user with this address. The answer is the same
   * either way, so nobody can find out which addresses have an account.
   */
  public function forgotPassword(string $email, ?string $ip): void
  {
    $email = mb_strtolower(trim($email));
    if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw ValidationException::field('email', I18n::t('Please enter a valid e-mail address.'));
    }
    foreach (['password-reset:'.$email, 'password-reset-ip:'.($ip ?? '-')] as $key) {
      if (!$this->rateLimiter->hit($key, self::MAX_RESET_MAILS, 3600)['allowed']) {
        throw new UserFacingException(I18n::t('Too many requests. Please try again in an hour.'), Status::TOO_MANY_REQUESTS, 'too_many_attempts');
      }
    }

    $user = $this->users->findByEmail($email);
    if (null === $user || !$user->isActive()) {
      return;
    }
    $token = $this->newToken($user, self::PASSWORD_RESET, self::PASSWORD_RESET_TTL);
    $this->mailer->send($user->getEmail(), I18n::t('{app}: Reset your password', ['app' => $this->appName]), implode("\n", [
      I18n::t('Hello {name},', ['name' => $user->getName()]),
      '',
      I18n::t('a new password was requested for your account at {app}. You can set it with this link:', ['app' => $this->appName]),
      '',
      $this->link('/reset-password', $token),
      '',
      I18n::t('The link is valid for one hour and only once. If this was not you, you can ignore this mail - your password stays as it is.'),
    ]));
  }

  /**
   * New password from the link. All sessions end (the token version changes); the answer is a
   * new login.
   *
   * @return array{token: string, user: User}
   */
  public function resetPassword(string $token, string $password, string $confirmation): array
  {
    $user = $this->userFor($token, self::PASSWORD_RESET, I18n::t('The link is invalid or has expired. Please request a new one.'));
    if (!$user->isActive()) {
      throw new UserFacingException(I18n::t('Your account is deactivated. Please contact an administrator.'), Status::FORBIDDEN, 'user_inactive');
    }
    self::assertNewPassword($password, $confirmation, 'password', 'password_confirmation');

    $user->setPassword($password);
    $this->users->save($user);
    $this->tokens->deleteFor($user->getId(), self::PASSWORD_RESET);
    $this->rateLimiter->clear('login:'.mb_strtolower($user->getEmail()));
    return ['token' => $this->jwt->generateForUser($user), 'user' => $user];
  }

  /**
   * New e-mail address: needs the current password and is only taken over when the link in the
   * mail to the new address is opened. The old address gets a notice.
   */
  public function requestEmailChange(User $user, string $email, string $password): string
  {
    $email = mb_strtolower(trim($email));
    $errors = [];
    if (!$user->verifyPassword($password)) {
      $errors['password'][] = I18n::t('The password is wrong.');
    }
    if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors['email'][] = I18n::t('Please enter a valid e-mail address.');
    } elseif ($email === mb_strtolower($user->getEmail())) {
      $errors['email'][] = I18n::t('This is your e-mail address already.');
    } elseif ($this->users->emailExists($email, $user->getId())) {
      $errors['email'][] = I18n::t('This e-mail address is already in use.');
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }

    $token = $this->newToken($user, self::EMAIL_CHANGE, self::EMAIL_CHANGE_TTL, $email);
    $this->mailer->send($email, I18n::t('{app}: Confirm your new e-mail address', ['app' => $this->appName]), implode("\n", [
      I18n::t('Hello {name},', ['name' => $user->getName()]),
      '',
      I18n::t('please confirm that you will sign in to {app} with this address from now on:', ['app' => $this->appName]),
      '',
      $this->link('/confirm-email', $token),
      '',
      I18n::t('The link is valid for 24 hours. Until then your current address stays valid.'),
    ]));
    $this->mailer->send($user->getEmail(), I18n::t('{app}: Your e-mail address is about to change', ['app' => $this->appName]), implode("\n", [
      I18n::t('Hello {name},', ['name' => $user->getName()]),
      '',
      I18n::t('the new address {email} was entered for your account at {app}. It only takes effect once the link in the mail to that address is opened.', ['app' => $this->appName, 'email' => $email]),
      '',
      I18n::t('If this was not you, please change your password.'),
    ]));
    return $email;
  }

  /**
   * Opens the link to the new address - works without being logged in (other browser, phone).
   */
  public function confirmEmail(string $token): User
  {
    $hash = self::hash($token);
    $found = $this->tokens->findValid($hash, self::EMAIL_CHANGE);
    $user = null !== $found ? $this->users->get($found['user_id']) : null;
    if (null === $found || null === $user || null === $found['data']) {
      throw new UserFacingException(I18n::t('The link is invalid or has expired.'), Status::BAD_REQUEST, 'invalid_token');
    }
    if ($this->users->emailExists($found['data'], $user->getId())) {
      $this->tokens->deleteFor($user->getId(), self::EMAIL_CHANGE);
      throw new UserFacingException(I18n::t('This e-mail address is used by another account by now.'), Status::CONFLICT, 'email_taken');
    }
    $user->setEmail($found['data']);
    $this->users->save($user);
    $this->tokens->deleteFor($user->getId(), self::EMAIL_CHANGE);
    return $user;
  }

  /**
   * Address waiting for confirmation (shown on the account page).
   */
  public function pendingEmail(User $user): ?string
  {
    return $this->tokens->pendingData($user->getId(), self::EMAIL_CHANGE);
  }

  public function cancelEmailChange(User $user): void
  {
    $this->tokens->deleteFor($user->getId(), self::EMAIL_CHANGE);
  }

  /**
   * New password twice the same and long enough.
   */
  public static function assertNewPassword(string $password, string $confirmation, string $field, string $confirmationField): void
  {
    AuthService::assertPassword($password, $field);
    if ($password !== $confirmation) {
      throw ValidationException::field($confirmationField, I18n::t('The passwords do not match.'));
    }
  }

  private function userFor(string $token, string $type, string $message): User
  {
    $found = '' !== $token ? $this->tokens->findValid(self::hash($token), $type) : null;
    $user = null !== $found ? $this->users->get($found['user_id']) : null;
    return $user ?? throw new UserFacingException($message, Status::BAD_REQUEST, 'invalid_token');
  }

  private function newToken(User $user, string $type, int $ttl, ?string $data = null): string
  {
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $this->tokens->replace($user->getId(), $type, self::hash($token), $ttl, $data);
    return $token;
  }

  private static function hash(string $token): string
  {
    return hash('sha256', $token);
  }

  /**
   * Link into the admin app: APP_URL, otherwise scheme and host of this request.
   */
  private function link(string $path, string $token): string
  {
    $base = $this->appUrl;
    if ('' === $base && null !== $this->requestProvider) {
      try {
        $uri = $this->requestProvider->get()->getUri();
        $base = sprintf('%s://%s%s', $uri->getScheme() ?: 'https', $uri->getHost(), null !== $uri->getPort() ? ':'.$uri->getPort() : '');
      } catch (RequestNotSetException) {
      }
    }
    return rtrim($base, '/').$path.'?token='.rawurlencode($token);
  }
}
