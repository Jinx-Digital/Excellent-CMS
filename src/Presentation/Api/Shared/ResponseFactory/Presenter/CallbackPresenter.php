<?php

declare(strict_types=1);

namespace App\Presentation\Api\Shared\ResponseFactory\Presenter;

use Closure;

/**
 * Presents each item with a callback, e.g. a database row of a content table.
 *
 * @implements PresenterInterface<mixed>
 */
final readonly class CallbackPresenter implements PresenterInterface
{
  private Closure $callback;

  public function __construct(callable $callback)
  {
    $this->callback = Closure::fromCallable($callback);
  }

  public function present(mixed $value): mixed
  {
    return ($this->callback)($value);
  }
}
