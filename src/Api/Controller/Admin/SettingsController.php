<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Service\SettingsService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

final class SettingsController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private SettingsService $settings,
  ) {
  }

  public function getMediaTypes(): ResponseInterface
  {
    return $this->responseFactory->success($this->settings->mediaTypes());
  }

  public function updateMediaTypes(JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->settings->updateMediaTypes($input->toArray()));
  }

  public function getRateLimit(): ResponseInterface
  {
    return $this->responseFactory->success($this->settings->rateLimit());
  }

  public function updateRateLimit(JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->settings->updateRateLimit($input->toArray()));
  }
}
