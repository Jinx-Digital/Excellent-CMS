<?php

declare(strict_types=1);

namespace App\Api\Controller\Auth;

use App\Api\Middleware\AuthMiddleware;
use App\Application\Service\OAuthException;
use App\Application\Service\OAuthService;
use App\Application\Service\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\DataResponse\DataResponseFactoryInterface;

/**
 * Token endpoint of the content API (RFC 6749 section 4.4 / 5). Answers in the OAuth format,
 * not in the {"status": …} format of the rest of the API, so standard OAuth clients work.
 */
final class OAuthController
{
  private const MAX_FAILURES = 20;
  private const FAILURE_WINDOW = 600;

  public function __construct(
    private DataResponseFactoryInterface $responseFactory,
    private OAuthService $oauth,
    private RateLimiter $rateLimiter,
  ) {
  }

  /**
   * POST /v1/oauth/token
   */
  public function token(ServerRequestInterface $request): ResponseInterface
  {
    $body = (array)($request->getParsedBody() ?? []);
    [$clientId, $secret] = [trim((string)($body['client_id'] ?? '')), (string)($body['client_secret'] ?? '')];

    // client_secret_basic: Authorization: Basic base64(client_id:client_secret)
    $header = $request->getHeaderLine('Authorization');
    if (str_starts_with($header, 'Basic ')) {
      $decoded = (string)base64_decode(substr($header, 6), true);
      if (str_contains($decoded, ':')) {
        [$clientId, $secret] = array_map('urldecode', explode(':', $decoded, 2));
      }
    }

    $failureKey = 'oauth-ip:'.(AuthMiddleware::clientIp($request) ?? '-');
    try {
      if ($this->rateLimiter->current($failureKey, self::FAILURE_WINDOW) >= self::MAX_FAILURES) {
        throw new OAuthException('slow_down', 'Too many failed attempts, try again later.', 429);
      }
      $token = $this->oauth->issueToken((string)($body['grant_type'] ?? ''), $clientId, $secret, isset($body['scope']) ? (string)$body['scope'] : null);
    } catch (OAuthException $e) {
      if ('invalid_client' === $e->error) {
        $this->rateLimiter->hit($failureKey, self::MAX_FAILURES, self::FAILURE_WINDOW);
      }
      $response = $this->responseFactory
        ->createResponse(['error' => $e->error, 'error_description' => $e->getMessage()])
        ->withStatus($e->status)
        ->withHeader('Cache-Control', 'no-store');
      return 401 === $e->status ? $response->withHeader('WWW-Authenticate', 'Basic realm="content-api"') : $response;
    }

    return $this->responseFactory->createResponse($token)
      ->withHeader('Cache-Control', 'no-store')
      ->withHeader('Pragma', 'no-cache');
  }
}
