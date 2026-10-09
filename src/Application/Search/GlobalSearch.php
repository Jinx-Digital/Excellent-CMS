<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Application\Content\RecordService;
use App\Application\Service\CurrentUser;
use App\Domain\Access\EntityPermission;
use App\Domain\Schema\EntityDefinition;
use App\Repository\EntityRepository;
use App\Repository\RecordRepository;

/**
 * Search across all entities of the project the user may read (admin app, ⌘K): the best records
 * per entity. "in:pages sommer" searches only "pages" and returns more of them.
 */
final class GlobalSearch
{
  public const PER_ENTITY = 5;
  public const IN_ENTITY = 50;

  public function __construct(
    private EntityRepository $entities,
    private RecordService $records,
    private CurrentUser $currentUser,
  ) {
  }

  /**
   * @return array{query: string, in: ?string, groups: list<array{entity: array{slug: string, name: string}, total: int, records: list<array{id: string, label: string, draft: bool}>}>}
   */
  public function search(string $input): array
  {
    [$slug, $text] = self::parse($input);
    $groups = [];
    if ('' !== $text) {
      foreach ($this->entities->all() as $entity) {
        if ((null !== $slug && $entity->slug !== $slug) || !$this->currentUser->can(EntityPermission::Read, $entity)) {
          continue;
        }
        $query = $this->records->search($entity, $text, [], null);
        $total = (int)(clone $query)->count();
        if (0 === $total) {
          continue;
        }
        $rows = $query->limit(null !== $slug ? self::IN_ENTITY : self::PER_ENTITY)->all();
        $groups[] = [
          'entity' => ['slug' => $entity->slug, 'name' => $entity->name],
          'total' => $total,
          'records' => array_map(static fn(array $row): array => [
            'id' => (string)$row['id'],
            'label' => self::label($entity, $row),
            'draft' => RecordRepository::isDraft($entity, $row),
          ], $rows),
        ];
      }
    }
    // Entities with the most matches first
    usort($groups, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
    return ['query' => $text, 'in' => $slug, 'groups' => $groups];
  }

  /**
   * "in:pages sommer fest" -> ["pages", "sommer fest"]
   *
   * @return array{0: ?string, 1: string}
   */
  public static function parse(string $input): array
  {
    $slug = null;
    $text = (string)preg_replace_callback('/(?:^|\s)in:([a-z][a-z0-9_]*)/i', static function (array $m) use (&$slug): string {
      $slug = strtolower($m[1]);
      return ' ';
    }, $input);
    return [$slug, trim((string)preg_replace('/\s+/', ' ', $text))];
  }

  private static function label(EntityDefinition $entity, array $row): string
  {
    $display = $entity->displayField();
    $value = null !== $display ? trim((string)($row[$display] ?? '')) : '';
    return '' !== $value ? mb_strimwidth($value, 0, 120, '…') : (string)$row['id'];
  }
}
