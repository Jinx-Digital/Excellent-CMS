<?php

declare(strict_types=1);

namespace App\Presentation\Api\Shared\ResponseFactory\Presenter;

use Yiisoft\Data\Paginator\OffsetPaginator;

/**
 * @implements PresenterInterface<OffsetPaginator>
 */
final readonly class OffsetPaginatorPresenter implements PresenterInterface
{
  private CollectionPresenter $collectionPresenter;

  /** @var (\Closure(list<array>): list<array>)|null */
  private ?\Closure $decorate;

  /**
   * @param (callable(list<array>): list<array>)|null $decorate Enriches the whole page at once (e.g. tags) - avoids one query per row.
   */
  public function __construct(
    PresenterInterface $itemPresenter = new AsIsPresenter(),
    ?callable $decorate = null,
  ) {
    $this->collectionPresenter = new CollectionPresenter($itemPresenter);
    $this->decorate = null !== $decorate ? \Closure::fromCallable($decorate) : null;
  }

  public function present(mixed $value): array
  {
    $items = $this->collectionPresenter->present($value->read());
    if (null !== $this->decorate) {
      $items = ($this->decorate)(array_values($items));
    }

    return [
      'data' => array_values($items),
      'meta' => [
        'page_size' => $value->getPageSize(),
        'current_page' => $value->getCurrentPage(),
        'total_pages' => $value->getTotalPages(),
        'total_items' => $value->getTotalItems(),
      ],
    ];
  }
}
