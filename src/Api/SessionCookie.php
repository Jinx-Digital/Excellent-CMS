<?php

declare(strict_types=1);

namespace App\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The login of the admin app: the JWT in an httpOnly cookie - scripts in the page (an XSS) cannot read
 * it. SameSite=Strict keeps other sites from sending it; in addition, it only counts for requests with
 * the header X-Requested-With, which only the admin app itself can send (from other origins it needs a
 * CORS preflight, and the admin routes answer none - see CorsMiddleware). Scripts and other clients
 * send the token of the login response as "Authorization: Bearer" instead.
 */
final class SessionCookie
{
  public const NAME = 'cms_session';
  public const HEADER = 'X-Requested-With';

  /**
   * The token of the request's cookie - null without one, or without the header of the admin app.
   */
  public static function read(ServerRequestInterface $request): ?string
  {
    $token = $request->getCookieParams()[self::NAME] ?? null;
    if (!is_string($token) || '' === $token) {
      $token = self::fromHeader($request->getHeaderLine('Cookie'));
    }
    return null !== $token && '' !== $token && '' !== $request->getHeaderLine(self::HEADER) ? $token : null;
  }

  public static function set(ResponseInterface $response, ServerRequestInterface $request, string $token): ResponseInterface
  {
    $ttl = (int)($_ENV['JWT_TTL'] ?? 60 * 60 * 24 * 7);
    return $response->withAddedHeader('Set-Cookie', self::cookie($request, $token, $ttl));
  }

  public static function clear(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
  {
    return $response->withAddedHeader('Set-Cookie', self::cookie($request, '', 0));
  }

  private static function cookie(ServerRequestInterface $request, string $value, int $ttl): string
  {
    $secure = 'https' === $request->getUri()->getScheme() || 'https' === strtolower($request->getHeaderLine('X-Forwarded-Proto'));
    return self::NAME.'='.rawurlencode($value).'; Path=/; Max-Age='.max(0, $ttl).'; HttpOnly; SameSite=Strict'.($secure ? '; Secure' : '');
  }

  private static function fromHeader(string $header): ?string
  {
    foreach (explode(';', $header) as $part) {
      [$name, $value] = array_map('trim', explode('=', $part, 2)) + [1 => ''];
      if (self::NAME === $name) {
        return rawurldecode($value);
      }
    }
    return null;
  }
}
