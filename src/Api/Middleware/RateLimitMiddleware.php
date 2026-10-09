<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Application\Service\CurrentClient;
use App\Application\Service\RateLimiter;
use App\Application\Service\SettingsService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

/**
 * Optional rate limit of the content API (runs after ContentAuthMiddleware). Counted per OAuth
 * client, without token per IP address. Which limit applies:
 *   - the client's own limit (0 = unlimited) if it has one,
 *   - otherwise the setting (Einstellungen → Rate-Limit), which can be switched off.
 * Answers with 429 and Retry-After; the X-RateLimit-* headers tell clients how much is left.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private RateLimiter $rateLimiter,
    private SettingsService $settings,
    private CurrentClient $currentClient,
  ) {
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $settings = $this->settings->rateLimit();
    $limit = $this->currentClient->getClient()?->rateLimit ?? ($settings['enabled'] ? $settings['requests'] : 0);
    if ($limit <= 0) {
      return $handler->handle($request);
    }

    $result = $this->rateLimiter->hit($this->currentClient->rateLimitKey(), $limit, $settings['window']);
    $headers = [
      'X-RateLimit-Limit' => (string)$result['limit'],
      'X-RateLimit-Remaining' => (string)$result['remaining'],
      'X-RateLimit-Reset' => (string)$result['reset'],
    ];

    if (!$result['allowed']) {
      $response = $this->responseFactory
        ->fail(I18n::t('Too many requests. Please try again a little later.'), httpCode: Status::TOO_MANY_REQUESTS, errorCode: 'rate_limited')
        ->withHeader('Retry-After', (string)max(1, $result['reset'] - time()));
    } else {
      $response = $handler->handle($request);
    }
    foreach ($headers as $name => $value) {
      $response = $response->withHeader($name, $value);
    }
    return $response;
  }
}
