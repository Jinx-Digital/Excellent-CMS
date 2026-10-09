<?php

declare(strict_types=1);

namespace App\Presentation\Api\Shared\ResponseFactory\Presenter;

/**
 * @implements PresenterInterface<mixed>
 */
final readonly class SuccessPresenter implements PresenterInterface
{
  public function __construct(
    private PresenterInterface $presenter = new AsIsPresenter(),
  ) {}

  public function present(mixed $value): array
  {
    $presented = $this->presenter->present($value);

    if (is_array($presented) && array_key_exists('data', $presented) && array_key_exists('meta', $presented)) {
      return array_merge([
        'status' => 'success',
        'success' => true,
      ], $presented);
    }

    return [
      'status' => 'success',
      'success' => true,
      'data' => $presented,
    ];
  }
}
