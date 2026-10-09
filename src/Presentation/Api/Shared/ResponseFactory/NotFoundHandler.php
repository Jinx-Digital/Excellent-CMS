<?php

declare(strict_types=1);

namespace App\Presentation\Api\Shared\ResponseFactory;

use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class NotFoundHandler implements RequestHandlerInterface
{
  public function __construct(
    private ResponseFactory $responseFactory,
  ) {}

  public function handle(ServerRequestInterface $request): ResponseInterface
  {
    return $this->responseFactory->notFound(I18n::t('This URL does not exist.'));
  }
}
