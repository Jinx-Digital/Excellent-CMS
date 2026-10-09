<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\ListRequest;
use App\Api\Input\UserInput;
use App\Application\Service\UserService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

final class UserController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private UserRepository $userRepository,
    private UserService $userService,
  ) {
  }

  public function list(ListRequest $request): ResponseInterface
  {
    return $this->responseFactory->paginated($request, $this->userRepository->search($request->s, $request->sort), defaultLimit: 50);
  }

  public function get(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->userService->get($id));
  }

  public function create(UserInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->userService->create($input));
  }

  public function update(#[RouteArgument('id')] string $id, UserInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->userService->update($id, $input));
  }

  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->userService->delete($id);
    return $this->responseFactory->success(['deleted' => true]);
  }
}
