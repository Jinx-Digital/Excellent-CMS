<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Repository\RecordRepository;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;

/**
 * Entities whose records form a tree: a reference field to the entity itself is the parent
 * (EntityDefinition::$treeField). Records without parent are the roots.
 *
 * The parent of a record may never be the record itself or one of its descendants - otherwise
 * the records would form a circle and have no root.
 */
final class TreeService
{
  /** Deeper trees are refused - a guard against loops in broken data */
  public const MAX_DEPTH = 100;
  /** Largest tree the content API returns at once (?tree=1) */
  public const MAX_NESTED = 10000;

  public function __construct(
    private ConnectionInterface $db,
    private RecordRepository $records,
    private RecordPresenter $presenter,
  ) {
  }

  /**
   * Content API: slugs of a tree as the whole path ("world/europe/western-europe") - for every
   * record of the entity, in one query. Translatable slugs per language, an empty translation
   * falls back to the default language (like the other values).
   *
   * @param list<string> $languages null = the default language
   * @return array<string, array<string, string>> id => language ('' = default) => path
   */
  public function slugPaths(EntityDefinition $entity, FieldDefinition $field, array $languages): array
  {
    $tree = $entity->treeField();
    if (null === $tree) {
      return [];
    }
    $columns = ['id', 'parent' => $tree->name, 'slug' => $field->name];
    foreach ($field->translatable ? $languages : [] as $language) {
      if ('' !== $language && $language !== $entity->defaultLanguage() && in_array($language, $entity->languages, true)) {
        $columns['slug_'.$language] = $field->translationColumn($language);
      }
    }
    $rows = [];
    foreach ($this->db->createQuery()->select($columns)->from($entity->tableName())->all() as $row) {
      $rows[(string)$row['id']] = $row;
    }

    $result = [];
    foreach ($languages as $language) {
      $key = isset($columns['slug_'.$language]) ? 'slug_'.$language : 'slug';
      $paths = [];
      $path = function (string $id, int $depth) use (&$path, &$paths, $rows, $key): ?string {
        if (array_key_exists($id, $paths)) {
          return $paths[$id];
        }
        $row = $rows[$id] ?? null;
        $segment = null !== $row ? ($row[$key] ?? $row['slug']) : null;
        if (null === $segment || '' === (string)$segment || $depth > self::MAX_DEPTH) {
          return $paths[$id] = null;
        }
        $parent = null !== $row['parent'] ? $path((string)$row['parent'], $depth + 1) : '';
        // A parent without slug: the path starts below it
        return $paths[$id] = ('' !== (string)$parent ? $parent.'/' : '').$segment;
      };
      foreach (array_keys($rows) as $id) {
        $value = $path((string)$id, 0);
        if (null !== $value) {
          $result[(string)$id][$language] = $value;
        }
      }
    }
    return $result;
  }

  /**
   * Admin app: adds `_children` (number of active children) to every record of a tree entity.
   *
   * @param list<array> $records presented records
   * @return list<array>
   */
  public function withChildCounts(EntityDefinition $entity, array $records): array
  {
    $field = $entity->treeField();
    if (null === $field || [] === $records) {
      return $records;
    }
    $rows = $this->records->query($entity)
      ->select([$field->name, 'children' => new Expression('COUNT(*)')])
      ->andWhere([$field->name => array_column($records, 'id')])
      ->groupBy($field->name)
      ->all();
    $counts = array_column($rows, 'children', $field->name);
    return array_map(static fn(array $record): array => $record + ['_children' => (int)($counts[$record['id']] ?? 0)], $records);
  }

  /**
   * Ancestors of a record, root first: [{id, label}, ...].
   *
   * @return list<array{id: string, label: string}>
   */
  public function path(EntityDefinition $entity, array $row): array
  {
    $field = $entity->treeField();
    $path = [];
    $parentId = null !== $field ? ($row[$field->name] ?? null) : null;
    while (null !== $parentId && count($path) < self::MAX_DEPTH) {
      $parent = $this->records->find($entity, (string)$parentId, withTrashed: true);
      if (null === $parent || isset($path[$parent['id']])) {
        break;
      }
      $path[(string)$parent['id']] = ['id' => (string)$parent['id'], 'label' => $this->presenter->label($entity, $parent)];
      $parentId = $parent[$field->name] ?? null;
    }
    return array_reverse(array_values($path));
  }

  /**
   * Why $parentId cannot be the parent of $id - or null if it can.
   */
  public function invalidParent(EntityDefinition $entity, string $id, string $parentId): ?string
  {
    $field = $entity->treeField();
    if (null === $field) {
      return null;
    }
    if ($parentId === $id) {
      return I18n::t('A record cannot be its own parent.');
    }
    $current = $parentId;
    for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
      $row = $this->records->find($entity, $current, withTrashed: true);
      $next = null !== $row ? ($row[$field->name] ?? null) : null;
      if (null === $next) {
        return null;
      }
      if ((string)$next === $id) {
        return I18n::t('A record cannot be placed below one of its own descendants.');
      }
      $current = (string)$next;
    }
    return I18n::t('The tree may be at most {count} levels deep.', ['count' => self::MAX_DEPTH]);
  }

  /**
   * Checks the whole table, e.g. after an import that changed parents of several records at once.
   *
   * @throws ValidationException naming the records of the first circle
   */
  public function assertNoCycles(EntityDefinition $entity): void
  {
    $field = $entity->treeField();
    if (null === $field) {
      return;
    }
    $parents = array_column($this->db->createQuery()->select(['id', $field->name])->from($entity->tableName())->all(), $field->name, 'id');
    $state = [];
    foreach (array_keys($parents) as $start) {
      $trail = [];
      $current = (string)$start;
      while (null !== $current && !isset($state[$current])) {
        if (isset($trail[$current])) {
          $circle = array_slice(array_keys($trail), (int)array_search($current, array_keys($trail), true));
          $labels = array_map(fn(string $id): string => $this->presenter->label($entity, $this->records->find($entity, $id, withTrashed: true) ?? ['id' => $id]), $circle);
          throw ValidationException::field('tree', I18n::t('These records reference each other in a circle: {records}.', ['records' => implode(' → ', $labels)]));
        }
        $trail[$current] = true;
        $current = isset($parents[$current]) ? (string)$parents[$current] : null;
      }
      foreach (array_keys($trail) as $id) {
        $state[$id] = true;
      }
    }
  }

  /**
   * Content API (?tree=1): records with `children`, roots first. A record whose parent is not in
   * the result (filtered out, in the trash) becomes a root, so nothing gets lost.
   *
   * @param list<array> $records presented records, already sorted (siblings keep that order)
   * @param array<string, ?string> $parents id => parent id
   * @return list<array>
   */
  public static function nest(array $records, array $parents): array
  {
    $byId = [];
    foreach ($records as $record) {
      $byId[$record['id']] = $record + ['children' => []];
    }
    $children = [];
    $roots = [];
    foreach (array_keys($byId) as $id) {
      $parent = $parents[$id] ?? null;
      null !== $parent && isset($byId[$parent]) && $parent !== $id ? $children[$parent][] = $id : $roots[] = $id;
    }
    $build = static function (string $id, int $depth) use (&$build, &$byId, $children): array {
      $node = $byId[$id];
      if ($depth < self::MAX_DEPTH) {
        $node['children'] = array_map(static fn(string $child): array => $build($child, $depth + 1), $children[$id] ?? []);
      }
      return $node;
    };
    return array_map(static fn(string $id): array => $build($id, 0), $roots);
  }
}
