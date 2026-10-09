<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Application\Project\ProjectService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\DataResponse\DataResponseFactoryInterface;

/**
 * CORS in two parts:
 *
 * - The public API of the projects (content, media of clients, public routes of plugins such as
 *   sending a form, variables, OAuth tokens) is called from any website: "*". Who may read or write is
 *   decided by the clients and their tokens, not by the origin.
 * - Everything else (the admin app: logins, schema, records …) answers other origins only if they are
 *   in ADMIN_ORIGINS (comma separated). The admin app itself is served from the same address as the API
 *   (and `make dev` forwards /api), so it needs none - by default no other site may call these routes
 *   from a browser.
 */
final class CorsMiddleware implements MiddlewareInterface
{
  private const METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
  private const HEADERS = 'Authorization, Content-Type, Accept, X-Project, X-Preview-Token';
  private const EXPOSE = 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After';

  /** @var list<string> */
  private array $adminOrigins;

  /**
   * @param list<string>|null $adminOrigins origins the admin routes answer (null: ADMIN_ORIGINS)
   */
  public function __construct(
    private DataResponseFactoryInterface $responseFactory,
    ?array $adminOrigins = null,
  ) {
    $this->adminOrigins = $adminOrigins ?? self::parse((string)($_ENV['ADMIN_ORIGINS'] ?? ''));
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $origin = $this->allowedOrigin($request);
    if ('OPTIONS' === $request->getMethod()) {
      $response = $this->responseFactory->createResponse()->withStatus(204);
      return null === $origin ? $response : $this->withCors($response, $origin)->withHeader('Access-Control-Max-Age', '86400');
    }
    $response = $handler->handle($request);
    return null === $origin ? $response : $this->withCors($response, $origin)->withHeader('Access-Control-Expose-Headers', self::EXPOSE);
  }

  /**
   * Is it a route of the public API (with or without the /api in front)?
   */
  public static function isPublic(string $path): bool
  {
    $reserved = implode('|', ProjectService::RESERVED);
    return 1 === preg_match('#^(?:/api)?/v1/(?:oauth/token|(?!(?:'.$reserved.')(?:/|$))[^/]+/(?:content|media|plugins|variables|oauth/token))(?:/|$)#', $path);
  }

  /**
   * The origin to allow: "*" for the public API, the caller's origin if it is one of the admin origins,
   * null: no CORS (same origin only).
   */
  private function allowedOrigin(ServerRequestInterface $request): ?string
  {
    if (self::isPublic($request->getUri()->getPath())) {
      return '*';
    }
    $origin = $request->getHeaderLine('Origin');
    if ('' === $origin) {
      return null;
    }
    return in_array('*', $this->adminOrigins, true) || in_array(rtrim($origin, '/'), $this->adminOrigins, true) ? $origin : null;
  }

  private function withCors(ResponseInterface $response, string $origin): ResponseInterface
  {
    $response = $response
      ->withHeader('Access-Control-Allow-Origin', $origin)
      ->withHeader('Access-Control-Allow-Methods', self::METHODS)
      ->withHeader('Access-Control-Allow-Headers', self::HEADERS);
    // The answer depends on the caller
    return '*' === $origin ? $response : $response->withAddedHeader('Vary', 'Origin');
  }

  /**
   * @return list<string>
   */
  private static function parse(string $value): array
  {
    return array_values(array_filter(array_map(static fn(string $origin): string => rtrim(trim($origin), '/'), explode(',', $value))));
  }
}
