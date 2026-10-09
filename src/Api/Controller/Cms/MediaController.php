<?php

declare(strict_types=1);

namespace App\Api\Controller\Cms;

use App\Api\Input\JsonInput;
use App\Api\Input\ListRequest;
use App\Application\Media\MediaLibrary;
use App\Application\Media\MediaService;
use App\Application\Schema\SchemaService;
use App\Domain\Schema\FieldType;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\AsIsPresenter;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\ProjectRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Uploads of media fields. Any signed-in user may upload; a file only becomes part of the content
 * when a record that the user may create or update points to it.
 */
final class MediaController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private MediaService $media,
    private MediaLibrary $library,
    private SchemaService $schema,
    private ProjectRepository $projects,
  ) {
  }

  /**
   * GET /v1/media?s=&kind=image|file&usage=used|unused&accept=image/*,application/pdf&kept=1&page=&limit=
   * - library with usages; accept: only files a field allows (picker)
   */
  public function list(ServerRequestInterface $request): ResponseInterface
  {
    $list = ListRequest::from($request, 48);
    $params = $request->getQueryParams();
    $accept = array_values(array_filter(array_map('trim', explode(',', (string)($params['accept'] ?? '')))));
    return $this->responseFactory->paginated(
      $list,
      $this->library->search(
        $list->s,
        isset($params['kind']) ? (string)$params['kind'] : null,
        isset($params['usage']) ? (string)$params['usage'] : null,
        $accept,
        (bool)filter_var($params['kept'] ?? false, FILTER_VALIDATE_BOOL),
      ),
      new AsIsPresenter(),
      decorate: fn(array $rows): array => $this->library->present($rows),
    );
  }

  /**
   * GET /v1/media/{id}
   */
  public function get(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->library->get($id));
  }

  /**
   * DELETE /v1/media/{id} - admins, unused files only
   */
  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->library->delete($id);
    return $this->responseFactory->success(['deleted' => true]);
  }

  /**
   * POST /v1/media (multipart: "file", optional "entity" + "field" of the media field the file is
   * for - then its allowed types are checked at once) - {id, url, name, mime_type, size, width, height, is_image}
   */
  public function upload(ServerRequestInterface $request): ResponseInterface
  {
    $file = $request->getUploadedFiles()['file'] ?? throw new UserFacingException(I18n::t('Please choose a file.'));
    $body = (array)$request->getParsedBody();
    // Uploaded in the media library: kept even while nothing uses it
    $keep = (bool)filter_var($body['keep'] ?? false, FILTER_VALIDATE_BOOL);
    $accept = null;
    $project = null;
    if (isset($body['entity'], $body['field'])) {
      $entity = $this->schema->get((string)$body['entity']);
      $field = $entity->field((string)$body['field']);
      $accept = null !== $field && FieldType::Media === $field->type ? $field->mediaAccept() : null;
      // A field of a global entity: the file is shared like the record
      $project = $entity->global ? $this->projects->global() : null;
    }
    return $this->responseFactory->success($this->media->upload($file, $accept, $keep, $project));
  }

  /**
   * PATCH /v1/media/{id} - {kept?: bool, name?: string, focal_point?: {x, y}|null}
   */
  public function update(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->library->update($id, $input->toArray()));
  }
}
