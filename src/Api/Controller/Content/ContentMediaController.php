<?php

declare(strict_types=1);

namespace App\Api\Controller\Content;

use App\Application\Content\ContentService;
use App\Application\Media\MediaLibrary;
use App\Application\Media\MediaService;
use App\Application\Service\CurrentClient;
use App\Domain\Schema\FieldType;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Media of a project through the content API - for clients with the media permissions
 * ("API-Zugänge"). Uploaded files are used in records by their id; unused ones are removed by
 * `./yii cleanup` after a day unless they were uploaded with keep=1 (media library).
 */
final class ContentMediaController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private MediaService $media,
    private MediaLibrary $library,
    private ContentService $content,
    private CurrentClient $currentClient,
  ) {
  }

  /**
   * POST /v1/<project>/media (multipart: "file", optional "keep"=1, optional "entity" + "field":
   * the media field the file is for - its allowed types are checked at once) - 201 with the file
   */
  public function upload(ServerRequestInterface $request): ResponseInterface
  {
    $this->assertCan('upload');
    $file = $request->getUploadedFiles()['file'] ?? throw ValidationException::field('file', I18n::t('Please send a file in the field "file" (multipart/form-data).'));
    $body = (array)$request->getParsedBody();
    $accept = null;
    if (isset($body['entity']) || isset($body['field'])) {
      $entity = $this->content->entity((string)($body['entity'] ?? ''));
      $field = $entity->field((string)($body['field'] ?? ''));
      if (null === $field || FieldType::Media !== $field->type) {
        throw ValidationException::field('field', I18n::t('"{field}" is no media field of "{entity}".', ['field' => (string)($body['field'] ?? ''), 'entity' => $entity->slug]));
      }
      $accept = $field->mediaAccept();
    }
    $file = $this->media->upload($file, $accept, (bool)filter_var($body['keep'] ?? false, FILTER_VALIDATE_BOOL));
    return $this->responseFactory->success($file)->withStatus(Status::CREATED);
  }

  /**
   * GET /v1/<project>/media/{id} - the file with `kept` and `usage_count` (which records use it is
   * not shown: they may be in entities the client cannot read)
   */
  public function get(#[RouteArgument('id')] string $id): ResponseInterface
  {
    if (!$this->currentClient->canMedia('upload') && !$this->currentClient->canMedia('delete')) {
      $this->assertCan('upload');
    }
    $file = $this->library->get($id);
    unset($file['usages'], $file['hidden_usages']);
    return $this->responseFactory->success($file);
  }

  /**
   * DELETE /v1/<project>/media/{id} - unused files only (409 media_in_use otherwise)
   */
  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->assertCan('delete');
    $this->library->deleteUnused($id);
    return $this->responseFactory->success(['deleted' => true]);
  }

  /**
   * @param 'upload'|'delete' $permission
   */
  private function assertCan(string $permission): void
  {
    if (!$this->currentClient->isAuthenticated()) {
      throw new UserFacingException(I18n::t('Media need an OAuth token.'), Status::UNAUTHORIZED, 'unauthorized');
    }
    if (!$this->currentClient->canMedia($permission)) {
      throw UserFacingException::forbidden('upload' === $permission
        ? I18n::t('This client may not upload media.')
        : I18n::t('This client may not delete media.'));
    }
  }
}
