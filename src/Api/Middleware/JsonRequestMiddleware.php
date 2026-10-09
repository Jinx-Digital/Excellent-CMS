<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Yiisoft\Http\Status;

class JsonRequestMiddleware implements MiddlewareInterface
{
  public function __construct(
    private ResponseFactory $responseFactory
  ) {
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $method = strtoupper($request->getMethod());

    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
      $contentType = $request->getHeaderLine('Content-Type');

      $isForm = str_contains($contentType, 'application/x-www-form-urlencoded');
      if ('' !== $contentType && !str_contains($contentType, 'application/json') && !str_contains($contentType, 'multipart/form-data') && !$isForm) {
        return $this->responseFactory->fail(
          I18n::t('The request has an unknown format.'),
          httpCode: Status::UNSUPPORTED_MEDIA_TYPE
        );
      }

      $contents = (string)$request->getBody();
      if ($isForm) {
        // OAuth token requests (RFC 6749) are form encoded
        if (!is_array($request->getParsedBody()) || [] === $request->getParsedBody()) {
          parse_str($contents, $form);
          $request = $request->withParsedBody($form);
        }
      } elseif ('' !== trim($contents) && ('' === $contentType || str_contains($contentType, 'application/json'))) {
        /** @var mixed $parsed */
        $parsed = json_decode($contents, true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($parsed)) {
          return $this->responseFactory->fail(I18n::t('The request could not be read.'));
        }
        $request = $request->withParsedBody($parsed);
      } elseif (null === $request->getParsedBody()) {
        $request = $request->withParsedBody([]);
      }
    }

    return $handler->handle($request);
  }
}
