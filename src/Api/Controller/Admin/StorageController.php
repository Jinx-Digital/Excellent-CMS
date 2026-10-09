<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Media\StorageService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Storages for uploads (see StorageService) - for all projects; each project picks one.
 */
final class StorageController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private StorageService $storages,
  ) {
  }

  public function list(): ResponseInterface
  {
    return $this->responseFactory->success($this->storages->list());
  }

  public function types(): ResponseInterface
  {
    return $this->responseFactory->success($this->storages->types());
  }

  public function get(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->storages->get($id));
  }

  public function create(JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->storages->create($input->toArray()));
  }

  public function update(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->storages->update($id, $input->toArray()));
  }

  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->storages->delete($id);
    return $this->responseFactory->success(['deleted' => true]);
  }

  public function test(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->storages->test($id));
  }
}
