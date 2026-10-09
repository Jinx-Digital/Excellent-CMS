<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Access\AccessControl;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\EntityRepository;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Roles (admins only): {slug, name, roles, permissions: {entity id: {read, create, update, delete,
 * import}}, media_upload, media_delete, take_over}. Entity permissions are edited for the entities
 * of the current project - those of other projects stay.
 */
final class RoleController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private AccessControl $access,
    private EntityRepository $entities,
  ) {
  }

  public function list(): ResponseInterface
  {
    return $this->responseFactory->success($this->access->allRoles());
  }

  public function create(JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->access->saveRole(null, $input->toArray(), $this->entityIds()));
  }

  public function update(#[RouteArgument('slug')] string $slug, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->access->saveRole($slug, $input->toArray(), $this->entityIds()));
  }

  public function delete(#[RouteArgument('slug')] string $slug): ResponseInterface
  {
    $this->access->deleteRole($slug);
    return $this->responseFactory->success(['deleted' => true]);
  }

  /**
   * @return list<string>
   */
  private function entityIds(): array
  {
    return array_map(static fn($entity): string => $entity->id, $this->entities->all());
  }
}
