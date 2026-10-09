<?php

declare(strict_types=1);

namespace App\Api\Controller\Cms;

use App\Api\Input\JsonInput;
use App\Application\Import\ImportService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * CSV/Excel import - see ImportService for the steps and the plan format.
 */
final class ImportController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private ImportService $imports,
  ) {
  }

  /**
   * POST /v1/imports (multipart, field "file")
   */
  public function upload(ServerRequestInterface $request): ResponseInterface
  {
    $file = $request->getUploadedFiles()['file'] ?? throw new UserFacingException(I18n::t('Please choose a CSV or Excel file.'));
    return $this->responseFactory->success($this->imports->upload($file));
  }

  /**
   * POST /v1/imports/{importId}/analyze - {sheet, delimiter}
   */
  public function analyze(#[RouteArgument('importId')] string $importId, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->imports->analyze($importId, $input->toArray()));
  }

  public function preview(#[RouteArgument('importId')] string $importId, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->imports->preview($importId, $input->toArray()));
  }

  public function run(#[RouteArgument('importId')] string $importId, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->imports->run($importId, $input->toArray()));
  }

  public function discard(#[RouteArgument('importId')] string $importId): ResponseInterface
  {
    $this->imports->discard($importId);
    return $this->responseFactory->success(['deleted' => true]);
  }
}
