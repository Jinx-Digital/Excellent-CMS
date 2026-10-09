<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApiPrefixMiddleware implements MiddlewareInterface
{
  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $uri = $request->getUri();
    $path = $uri->getPath();

    if (str_starts_with($path, '/api/')) {
      $trimmedPath = substr($path, 4);
      while (str_starts_with($trimmedPath, '/api/')) {
        $trimmedPath = substr($trimmedPath, 4);
      }
      $request = $request->withUri($uri->withPath($trimmedPath));
    }

    return $handler->handle($request);
  }
}
