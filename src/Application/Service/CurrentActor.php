<?php

declare(strict_types=1);

namespace App\Application\Service;

/**
 * Who does something in this request, as stored in created_by / updated_by / deleted_by:
 * "client:<id>" for the content API, "user:<id>" for the admin app (and console commands acting as
 * a user), "event:<id>" while an event runs its steps, null otherwise.
 */
final class CurrentActor
{
  public function __construct(
    private CurrentUser $currentUser,
    private CurrentClient $currentClient,
  ) {
  }

  /** Set while an event runs its steps: changes are made by the event, not by the user who caused it */
  private ?string $override = null;

  /**
   * Runs $callback as another actor ("event:<id>").
   *
   * @template T
   * @param callable(): T $callback
   * @return T
   */
  public function as(string $actor, callable $callback): mixed
  {
    $previous = $this->override;
    $this->override = $actor;
    try {
      return $callback();
    } finally {
      $this->override = $previous;
    }
  }

  /**
   * The CMS itself is acting (an event, a schedule) - not the user or client of the request.
   */
  public function isOverridden(): bool
  {
    return null !== $this->override;
  }

  public function id(): ?string
  {
    if (null !== $this->override) {
      return $this->override;
    }
    if (null !== ($client = $this->currentClient->getClient())) {
      return 'client:'.$client->id;
    }
    $userId = $this->currentUser->getId();
    return null !== $userId ? 'user:'.$userId : null;
  }
}
