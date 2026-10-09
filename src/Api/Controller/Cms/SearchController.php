<?php

declare(strict_types=1);

namespace App\Api\Controller\Cms;

use App\Application\Search\GlobalSearch;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/search?s=… - search across the entities of the project the user may read (admin app);
 * "in:<entity> …" searches only that one.
 */
final class SearchController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private GlobalSearch $search,
  ) {
  }

  public function search(ServerRequestInterface $request): ResponseInterface
  {
    return $this->responseFactory->success($this->search->search((string)($request->getQueryParams()['s'] ?? '')));
  }
}
