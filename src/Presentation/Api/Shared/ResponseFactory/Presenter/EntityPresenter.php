<?php

declare(strict_types=1);

namespace App\Presentation\Api\Shared\ResponseFactory\Presenter;

/**
 * Objects present themselves via toArray(); arrays (rows) are passed through.
 *
 * @implements PresenterInterface<mixed>
 */
final readonly class EntityPresenter implements PresenterInterface
{
  public function present(mixed $value): mixed
  {
    if (is_object($value) && method_exists($value, 'toArray')) {
      return $value->toArray();
    }
    return $value;
  }
}
