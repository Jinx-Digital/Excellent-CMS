<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Application\Service\CurrentProject;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\ProjectRepository;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

/**
 * /api/v1/<project>/...: the project of the path is the only one the request sees (content API,
 * token endpoint of the project).
 */
final class ProjectPathMiddleware implements MiddlewareInterface
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private CurrentProject $currentProject,
    private ProjectRepository $projects,
    private CurrentRoute $currentRoute,
  ) {
  }

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $slug = (string)$this->currentRoute->getArgument('project', '');
    $project = '' !== $slug ? $this->projects->find($slug) : null;
    if (null === $project || $project->slug !== $slug) {
      return $this->responseFactory->fail(I18n::t('The project "{project}" does not exist.', ['project' => $slug]), httpCode: Status::NOT_FOUND, errorCode: 'not_found');
    }
    $this->currentProject->set($project);
    return $handler->handle($request);
  }
}
