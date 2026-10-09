<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Application\Service\EnvVariables;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /v1/admin/env-vars - names of the .env variables settings can reference as $NAME, with whether
 * they are set - never their values (admins)
 */
final class EnvController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private EnvVariables $env,
  ) {
  }

  public function names(): ResponseInterface
  {
    return $this->responseFactory->success($this->env->names());
  }
}
