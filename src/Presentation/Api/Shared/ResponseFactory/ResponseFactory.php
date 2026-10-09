<?php

declare(strict_types=1);

namespace App\Presentation\Api\Shared\ResponseFactory;

use App\Api\Input\ListRequest;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\AsIsPresenter;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\CollectionPresenter;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\EntityPresenter;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\FailPresenter;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\OffsetPaginatorPresenter;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\PresenterInterface;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\SuccessPresenter;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\ValidationResultPresenter;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Data\Db\QueryDataReader;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\DataResponse\DataResponseFactoryInterface;
use Yiisoft\Db\Query\QueryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Validator\Result;

final readonly class ResponseFactory
{
  public function __construct(
    private DataResponseFactoryInterface $dataResponseFactory,
  ) {}

  public function createResponse(mixed $data = null): ResponseInterface
  {
    return $this->dataResponseFactory->createResponse($data);
  }

  public function success(
    array|object|null $data = null,
    ?PresenterInterface $presenter = null,
  ): ResponseInterface {
    return $this->dataResponseFactory->createResponse(
      new SuccessPresenter($presenter ?? $this->resolveAutoPresenter($data))->present($data),
    );
  }

  /**
   * Picks a presenter automatically when the caller didn't specify one:
   * a lone object is run through the EntityPresenter (which itself defers to the
   * object's own toArray() when defined, else the hydrator), and an array made up
   * entirely of objects is run through EntityPresenter for each item via a
   * CollectionPresenter. Anything else (scalars, associative/mixed arrays, empty
   * arrays) is left untouched, since it's ambiguous whether nested objects there
   * are meant to be presented entities or incidental values.
   */
  private function resolveAutoPresenter(mixed $data): PresenterInterface
  {
    if (is_object($data)) {
      return new EntityPresenter();
    }
    if (is_array($data) && [] !== $data && array_is_list($data) && $this->isListOfObjects($data)) {
      return new CollectionPresenter(new EntityPresenter());
    }
    return new AsIsPresenter();
  }

  private function isListOfObjects(array $items): bool
  {
    foreach ($items as $item) {
      if (!is_object($item)) {
        return false;
      }
    }
    return true;
  }

  public function paginated(
    ServerRequestInterface|ListRequest $request,
    QueryInterface $query,
    PresenterInterface $itemPresenter = new EntityPresenter(),
    int $defaultLimit = 50,
    ?callable $decorate = null,
  ): ResponseInterface {
    $listRequest = $request instanceof ListRequest ? $request : ListRequest::from($request, $defaultLimit);
    $page = $listRequest->page;
    $limit = $listRequest->limit;

    $dataReader = new QueryDataReader($query);
    $paginator = (new OffsetPaginator($dataReader))
      ->withPageSize($limit);

    if (1 !== $page && $page > $paginator->getTotalPages()) {
      return $this->notFound(I18n::t('This page does not exist.'));
    }

    $paginator = $paginator->withCurrentPage($page);

    return $this->success(
      $paginator,
      new OffsetPaginatorPresenter($itemPresenter, $decorate),
    );
  }

  public function fail(
    string $message,
    array|object|null $data = null,
    int $httpCode = Status::BAD_REQUEST,
    ?string $errorCode = null,
    PresenterInterface $presenter = new AsIsPresenter(),
  ): ResponseInterface {
    return $this->dataResponseFactory
      ->createResponse(
        new FailPresenter($message, $errorCode, $presenter)->present($data),
      )
      ->withStatus($httpCode);
  }

  public function notFound(?string $message = null): ResponseInterface
  {
    return $this->fail($message ?? I18n::t('This was not found.'), httpCode: Status::NOT_FOUND, errorCode: 'not_found');
  }

  public function failValidation(Result $result): ResponseInterface
  {
    return $this->fail(
      I18n::t('Please check the marked fields.'),
      $result,
      httpCode: Status::UNPROCESSABLE_ENTITY,
      errorCode: 'validation',
      presenter: new ValidationResultPresenter(),
    );
  }
}
