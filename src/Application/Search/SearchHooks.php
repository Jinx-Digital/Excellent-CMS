<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Schema\EntityDefinition;
use Psr\Container\ContainerInterface;

/**
 * What RecordRepository tells the search index. SearchIndex is taken from the container on first
 * use: it needs RecordRepository itself (to rebuild).
 */
final class SearchHooks
{
  private ?SearchIndex $index = null;

  public function __construct(
    private ContainerInterface $container,
  ) {
  }

  /**
   * @param list<array<string, mixed>> $rows records that were saved
   */
  public function saved(EntityDefinition $entity, array $rows): void
  {
    $this->index()->index($entity, $rows);
  }

  public function deleted(EntityDefinition $entity, string $recordId): void
  {
    $this->index()->forget($entity, $recordId);
  }

  private function index(): SearchIndex
  {
    return $this->index ??= $this->container->get(SearchIndex::class);
  }
}
