<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Application\Service\CurrentClient;
use App\Application\Service\OAuthService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

/**
 * Content API: the bearer token is optional (public entities need none), but if one is sent it
 * must be valid - a wrong token is an error, not silently public access.
 */
final class ContentAuthMiddleware implements MiddlewareInterface
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private OAuthService $oauth,
    private CurrentClient $currentClient,
  ) {
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $this->currentClient->setIp(AuthMiddleware::clientIp($request));

    $header = $request->getHeaderLine('Authorization');
    if ('' !== $header) {
      if (!str_starts_with($header, 'Bearer ') || !$this->oauth->authenticate(trim(substr($header, 7)))) {
        return $this->responseFactory
          ->fail(I18n::t('The access token is invalid or has expired.'), httpCode: Status::UNAUTHORIZED, errorCode: 'invalid_token')
          ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
      }
    }

    try {
      return $handler->handle($request);
    } catch (UserFacingException $e) {
      if (Status::UNAUTHORIZED !== $e->getHttpStatus()) {
        throw $e;
      }
      // Protected entity without token: tell the client how to authenticate (RFC 6750)
      return $this->responseFactory
        ->fail($e->getMessage(), httpCode: Status::UNAUTHORIZED, errorCode: $e->getErrorCode())
        ->withHeader('WWW-Authenticate', 'Bearer');
    }
  }
}
