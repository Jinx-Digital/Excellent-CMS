<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Entity\User;
use App\Repository\UserRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Yiisoft\Http\Status;

final class AuthService
{
  private const MAX_FAILED_LOGINS = 10;
  private const FAILED_LOGIN_WINDOW = 900;
  public const MIN_PASSWORD_LENGTH = 8;

  public function __construct(
    private UserRepository $userRepository,
    private JwtService $jwtService,
    private RateLimiter $rateLimiter,
  ) {
  }

  /**
   * @return array{token: string, user: User}
   */
  public function login(string $email, string $password, ?string $ip): array
  {
    if ('' === $email || '' === $password) {
      throw new UserFacingException(I18n::t('Please enter your e-mail address and password.'));
    }

    // Login brake: after 10 failures in 15 minutes for the address or the IP
    $keys = ['login:'.mb_strtolower($email), 'login-ip:'.($ip ?? '-')];
    foreach ($keys as $key) {
      if ($this->rateLimiter->current($key, self::FAILED_LOGIN_WINDOW) >= self::MAX_FAILED_LOGINS) {
        throw new UserFacingException(I18n::t('Too many failed attempts. Please wait 15 minutes and try again.'), Status::TOO_MANY_REQUESTS, 'too_many_attempts');
      }
    }

    $user = $this->userRepository->findByEmail($email);
    if (null === $user || !$user->verifyPassword($password)) {
      foreach ($keys as $key) {
        $this->rateLimiter->hit($key, self::MAX_FAILED_LOGINS, self::FAILED_LOGIN_WINDOW);
      }
      throw new UserFacingException(I18n::t('The e-mail address or password is wrong.'), Status::UNAUTHORIZED, 'invalid_credentials');
    }

    if (!$user->isActive()) {
      throw new UserFacingException(I18n::t('Your account is deactivated. Please contact an administrator.'), Status::FORBIDDEN, 'user_inactive');
    }

    $this->rateLimiter->clear($keys[0]);
    // Rehash silently without logging the user out elsewhere
    $this->userRepository->updateLogin($user, $user->needsRehash() ? password_hash($password, PASSWORD_DEFAULT) : null);
    $user->markLoggedIn();

    return ['token' => $this->jwtService->generateForUser($user), 'user' => $user];
  }

  /**
   * Returns a fresh token, since the password change invalidates all existing ones.
   */
  public function changePassword(User $user, string $currentPassword, string $newPassword, string $confirmation): string
  {
    if (!$user->verifyPassword($currentPassword)) {
      throw ValidationException::field('current_password', I18n::t('The current password is wrong.'));
    }
    AccountService::assertNewPassword($newPassword, $confirmation, 'new_password', 'new_password_confirmation');

    $user->setPassword($newPassword);
    $this->userRepository->save($user);

    return $this->jwtService->generateForUser($user);
  }

  public static function assertPassword(string $password, string $field = 'password'): void
  {
    if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
      throw ValidationException::field($field, I18n::t('The password must have at least {count} characters.', ['count' => self::MIN_PASSWORD_LENGTH]));
    }
  }
}
