<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Project\ProjectService;
use App\Application\Service\CurrentProject;
use App\Infrastructure\Media\MediaStorages;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\ProjectRepository;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Projects (admins): each with its own entities, table prefix, API clients, media and languages.
 */
final class ProjectController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private ProjectService $service,
    private ProjectRepository $projects,
    private CurrentProject $currentProject,
    private MediaStorages $storages,
  ) {
  }

  /**
   * GET /v1/admin/media-storages - the storages a project can pick for its uploads (no credentials)
   */
  public function mediaStorages(): ResponseInterface
  {
    return $this->responseFactory->success($this->storages->options());
  }

  public function list(): ResponseInterface
  {
    return $this->responseFactory->success(array_map(fn($project): array => $project->toArray() + ['entity_count' => $this->projects->countEntities($project->id)], $this->projects->all()));
  }

  public function create(JsonInput $input, \App\Application\Schema\PluginSetup $blocks): ResponseInterface
  {
    $project = $this->service->create($input->toArray());
    // Blocks of active plugins (e.g. "form")
    $blocks->ensure($project->id);
    return $this->responseFactory->success($project->toArray());
  }

  public function update(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->service->update($id, $input->toArray())->toArray());
  }

  /**
   * GET /v1/admin/variables - variables of the current project (header X-Project)
   */
  public function variables(): ResponseInterface
  {
    return $this->responseFactory->success($this->currentProject->get()->variables);
  }

  /**
   * PUT /v1/admin/variables - {variables: [{name, label, translatable, value, translations: {en: ...}}]}
   */
  public function updateVariables(JsonInput $input): ResponseInterface
  {
    $data = $input->toArray();
    return $this->responseFactory->success($this->service->updateVariables($this->currentProject->get()->id, (array)($data['variables'] ?? []))->variables);
  }

  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->service->delete($id);
    return $this->responseFactory->success(['deleted' => true]);
  }
}
