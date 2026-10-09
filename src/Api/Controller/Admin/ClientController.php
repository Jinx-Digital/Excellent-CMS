<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Service\OAuthService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * API access (OAuth clients) for the content API. The secret is returned only when a client is
 * created or gets a new secret.
 */
final class ClientController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private OAuthService $oauth,
  ) {
  }

  public function list(): ResponseInterface
  {
    return $this->responseFactory->success(array_map(fn($client): array => $this->oauth->present($client), $this->oauth->all()));
  }

  public function get(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->oauth->present($this->oauth->get($id)));
  }

  public function create(JsonInput $input): ResponseInterface
  {
    $result = $this->oauth->createClient($input->toArray());
    return $this->responseFactory->success($this->oauth->present($result['client']) + ['client_secret' => $result['secret']]);
  }

  public function update(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->oauth->present($this->oauth->updateClient($id, $input->toArray())));
  }

  public function regenerateSecret(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $secret = $this->oauth->regenerateSecret($id);
    return $this->responseFactory->success($this->oauth->present($this->oauth->get($id)) + ['client_secret' => $secret]);
  }

  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->oauth->deleteClient($id);
    return $this->responseFactory->success(['deleted' => true]);
  }
}
