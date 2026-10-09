<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Event\EventService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Events of the current project (header X-Project), admins only.
 */
final class EventController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private EventService $events,
  ) {
  }

  public function list(): ResponseInterface
  {
    return $this->responseFactory->success($this->events->all());
  }

  public function get(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->events->get($id));
  }

  public function create(JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->events->create($input->toArray()));
  }

  public function update(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->events->update($id, $input->toArray()));
  }

  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->events->delete($id);
    return $this->responseFactory->success(['deleted' => true]);
  }

  /**
   * GET /v1/admin/events/{id}/runs - newest first, with the progress of every step
   */
  public function runs(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->events->runs($id));
  }

  /**
   * POST /v1/admin/events/{id}/test - {record}: does the condition match, what would the steps do
   */
  public function test(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->events->test($id, (string)($input->toArray()['record'] ?? '')));
  }

  /**
   * POST /v1/admin/event-runs/{id}/retry - a failed run again, done steps are skipped
   */
  public function retry(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->events->retry($id));
  }
}
