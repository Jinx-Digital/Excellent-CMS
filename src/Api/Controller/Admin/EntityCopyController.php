<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Schema\EntityCopyService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final class EntityCopyController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private EntityCopyService $copies,
  ) {
  }

  /**
   * POST /v1/admin/entities/{id}/copy - {project, slug?, records?: bool}: into another project
   */
  public function copy(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    $data = $input->toArray();
    $result = $this->copies->copy(
      $id,
      (string)($data['project'] ?? ''),
      isset($data['slug']) ? (string)$data['slug'] : null,
      (bool)filter_var($data['records'] ?? false, FILTER_VALIDATE_BOOL),
    );
    return $this->responseFactory->success([
      'entity' => ['id' => $result['entity']->id, 'slug' => $result['entity']->slug, 'name' => $result['entity']->name],
      'project' => ['id' => $result['project']->id, 'slug' => $result['project']->slug, 'name' => $result['project']->name],
      'records' => $result['records'],
      'media' => $result['media'],
      // Field label => references/files the target project does not have (emptied)
      'cleared' => (object)$result['cleared'],
    ]);
  }
}
