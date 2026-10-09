<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Application\Event\EventDispatcher;
use App\Application\Event\EventRunner;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Events of a request start when it is done: queued runs go to the queue, the others run after
 * the answer went out where PHP-FPM allows it (slow webhooks never slow down the CMS). Failed
 * requests start nothing - their changes may have been rolled back.
 */
final class EventMiddleware implements MiddlewareInterface
{
  public function __construct(
    private EventDispatcher $dispatcher,
    private EventRunner $runner,
  ) {
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $response = $handler->handle($request);
    if (!$this->dispatcher->hasPending() || $response->getStatusCode() >= 400) {
      return $response;
    }
    $runs = $this->dispatcher->flush();
    if ([] === $runs) {
      return $response;
    }
    $runner = $this->runner;
    $execute = static function () use ($runner, $runs): void {
      foreach ($runs as $run) {
        $runner->run($run);
      }
    };
    if (function_exists('fastcgi_finish_request')) {
      register_shutdown_function(static function () use ($execute): void {
        fastcgi_finish_request();
        $execute();
      });
    } else {
      $execute();
    }
    return $response;
  }
}
