<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Domain\Schema\CustomFieldTypes;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\InvalidValueException;
use App\Domain\Schema\ValueConverter;
use App\Repository\EntityRepository;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Closure;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\Query\QueryInterface;

/**
 * Search, filters and sorting for record lists - the same rules for the admin app and the
 * content API:
 *
 *   ?s=berlin                              text search in all text fields
 *   ?filter[country]=<id>                  equals (references: id of the record)
 *   ?filter[price][gte]=10&filter[price][lt]=20
 *   ?filter[name][like]=cup                contains
 *   ?filter[status][in]=a,b                one of
 *   ?filter[logo][null]=true               empty / not empty
 *   ?filter[slug]=world/europe             trees: a slug path as the content API returns it
 *   ?filter[country][name]=Deutschland     fields of the referenced record (up to 3 levels:
 *   ?filter[country][region][name][like]=eu   filter[a][b][c]), with the same comparisons
 *   ?sort=-created_at,name                 "-" = descending
 *   ?sort=country                          references: by the display field of the referenced record
 *   ?sort=country[name]                    ... or by another of its fields (sort=country[region][name])
 */
final class RecordQuery
{
  private const SYSTEM_COLUMNS = ['id', 'created_at', 'updated_at', 'created_by', 'updated_by'];
  private const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'null'];
  /** Levels of references in one filter or sort path: country[region][name] */
  private const MAX_DEPTH = 3;

  public function __construct(
    private ConnectionInterface $db,
    private EntityRepository $entityRepository,
    /** Fields limited to roles cannot be searched, filtered or sorted by whoever may not read them */
    private ?\App\Application\Access\FieldAccess $fieldAccess = null,
    /** Text search in the index (see SearchIndex) - without it, in the columns */
    private ?\App\Application\Search\SearchIndex $searchIndex = null,
  ) {
  }

  private function readable(?\App\Domain\Schema\FieldDefinition $field): ?\App\Domain\Schema\FieldDefinition
  {
    return null !== $field && null !== $this->fieldAccess && !$this->fieldAccess->canRead($field) ? null : $field;
  }

  /**
   * @param string|null $language translatable fields are searched, filtered and sorted in this language
   * @param (Closure(EntityDefinition): bool)|null $canRead may fields of this referenced entity be used?
   */
  public function apply(QueryInterface $query, EntityDefinition $entity, string $search = '', array $filter = [], ?string $sort = null, string $defaultSort = '-created_at', ?string $language = null, ?Closure $canRead = null, bool $schemaLimits = true): QueryInterface
  {
    $context = new RecordQueryContext($language, $canRead, $schemaLimits);
    $hits = $this->search($query, $entity, $search, $language);
    $this->filter($query, $entity, $filter, $context);
    // Searched in the index without an order of its own: the best matches first
    if (null !== $hits && null === $sort) {
      $table = $this->db->getQuoter()->quoteTableName($entity->tableName());
      $query->leftJoin(['search_hits' => $hits], "[[search_hits]].[[search_record]] = {$table}.[[id]]");
      $query->orderBy(['search_hits.search_score' => SORT_DESC, 'created_at' => SORT_DESC]);
      return $query;
    }
    // Entities with an order field are in that order unless asked otherwise
    $order = $entity->orderField();
    $this->sort($query, $entity, $sort ?? (null !== $order ? $order->name.',created_at' : $defaultSort), $context);
    return $query;
  }

  /**
   * Text search: in the search index if it is ready (the matches are returned for the order of
   * relevance), otherwise in the columns of the searchable fields.
   */
  private function search(QueryInterface $query, EntityDefinition $entity, string $search, ?string $language): ?QueryInterface
  {
    $search = trim($search);
    if ('' === $search) {
      return null;
    }
    // Fields the schema leaves out of the search, or the user may not read
    $fields = array_values(array_filter($entity->fields, fn($field): bool => $field->isSearchable() && null !== $this->readable($field)));
    // Group fields and blocks: their texts are in the index (the column fallback searches the JSON)
    $textual = array_values(array_filter($fields, static fn($field): bool => $field->type->isTextual() || FieldType::Group === $field->type || FieldType::Custom === $field->type));
    $hits = $this->searchIndex?->match($entity, $search, $language, array_map(static fn($field): string => $field->id, $textual));
    if (null !== $hits) {
      $query->andWhere(['or', ['id' => $search], ['in', 'id', (clone $hits)->select('record_id')]]);
      return $hits;
    }
    $conditions = ['or', ['id' => $search]];
    foreach ($fields as $field) {
      if ($field->type->isTextual() || FieldType::Group === $field->type || FieldType::Custom === $field->type) {
        $conditions[] = ['like', $entity->column($field, $language), $search];
      } elseif ($field->type->isInteger() && preg_match('/^-?\d{1,18}$/', $search)) {
        $conditions[] = [$field->name => (int)$search];
      }
    }
    $query->andWhere($conditions);
    return null;
  }

  private function filter(QueryInterface $query, EntityDefinition $entity, array $filter, RecordQueryContext $context): void
  {
    $errors = [];
    foreach ($this->conditions($entity, null, $filter, 'filter', 0, $context, $errors) as $condition) {
      $query->andWhere($condition);
    }
    if ([] !== $errors) {
      throw new ValidationException($errors, I18n::t('The filter does not fit the fields.'));
    }
  }

  /**
   * Conditions of one level. Keys besides the comparisons are fields of the referenced record:
   * they become a subquery on its table (alias ref1, ref2 ...).
   *
   * @param string|null $alias table alias of this level (null: the main table, columns stay unqualified)
   * @param array<string, list<string>> $errors
   * @return list<array>
   */
  private function conditions(EntityDefinition $entity, ?string $alias, array $filter, string $path, int $depth, RecordQueryContext $context, array &$errors): array
  {
    $result = [];
    foreach ($filter as $name => $condition) {
      $name = (string)$name;
      $key = "{$path}.{$name}";
      $field = $this->readable($entity->field($name));
      if (null !== $field && !$field->filterable && $context->schemaLimits) {
        $errors[$key][] = I18n::t('Cannot filter by "{field}" - the field is not filterable.', ['field' => $name]);
        continue;
      }
      if (null === $field && !in_array($name, self::SYSTEM_COLUMNS, true) && !($entity->drafts && EntityDefinition::DRAFT === $name)) {
        $errors[$key][] = 0 === $depth
          ? I18n::t('Cannot filter by "{field}" - the field does not exist.', ['field' => $name])
          : I18n::t('Cannot filter by "{field}" - "{entity}" has no such field.', ['field' => $name, 'entity' => $entity->name]);
        continue;
      }
      $column = self::qualify($alias, null !== $field ? $entity->column($field, $context->language) : $name);
      $nested = [];
      foreach (is_array($condition) ? $condition : ['eq' => $condition] as $operator => $value) {
        $operator = (string)$operator;
        if (in_array($operator, self::OPERATORS, true)) {
          // Trees: a slug path as the content API returns it ("world/europe/western-europe")
          if ('eq' === $operator && null !== $field && FieldType::Slug === $field->type && is_string($value) && str_contains($value, '/') && null !== $entity->treeField()) {
            $result[] = [self::qualify($alias, 'id') => $this->recordAtPath($entity, $field, $value, $context->language)];
            continue;
          }
          // The draft column is a boolean (BIT): compare with true/false, not with "1"
          if (null === $field && EntityDefinition::DRAFT === $name && 'null' !== $operator) {
            $value = (bool)filter_var($value, FILTER_VALIDATE_BOOL);
          }
          try {
            $result[] = self::condition($column, $field, $operator, $value);
          } catch (InvalidValueException $e) {
            $errors[$key][] = $e->getMessage();
          }
        } elseif (null !== $field && FieldType::Reference === $field->type) {
          $nested[$operator] = $value;
        } else {
          $errors[$key][] = I18n::t('Unknown comparison "{operator}" (allowed: {allowed}).', ['operator' => $operator, 'allowed' => implode(', ', self::OPERATORS)]);
        }
      }
      if ([] === $nested || null === $field) {
        continue;
      }

      $target = $this->target($entity, $field, $depth, $context, $key, $errors);
      if (null === $target) {
        continue;
      }
      $targetAlias = $context->nextAlias();
      $subquery = (new Query($this->db))->from([$targetAlias => $target->tableName()]);
      // Records in the trash are gone for the API and lists - they do not match either
      if ($target->trash) {
        $subquery->andWhere([$targetAlias.'.'.EntityDefinition::DELETED_AT => null]);
      }
      foreach ($this->conditions($target, $targetAlias, $nested, $key, $depth + 1, $context, $errors) as $inner) {
        $subquery->andWhere($inner);
      }
      if ($field->repeatable) {
        // A list of ids (JSON): one of them is a matching record
        $quoter = $this->db->getQuoter();
        $result[] = ['exists', $subquery->select(new Expression('1'))->andWhere(new Expression(sprintf(
          "%s LIKE CONCAT('%%\"', %s, '\"%%')",
          $quoter->quoteColumnName(self::qualify($alias ?? $entity->tableName(), $entity->column($field, null))),
          $quoter->quoteColumnName($targetAlias.'.id'),
        )))];
      } else {
        $result[] = ['in', $column, $subquery->select($targetAlias.'.id')];
      }
    }
    return $result;
  }

  /**
   * Id of the record at a slug path, level by level from the top (null: there is none).
   */
  private function recordAtPath(EntityDefinition $entity, FieldDefinition $field, string $path, ?string $language): ?string
  {
    $tree = $entity->treeField();
    $column = $entity->column($field, $language);
    $parent = null;
    foreach (explode('/', trim($path, '/')) as $segment) {
      $query = (new Query($this->db))->select('id')->from($entity->tableName())->where([(string)$tree?->name => $parent]);
      // Translations: an empty one stands for the slug of the default language
      $query->andWhere($column === $field->name ? [$column => $segment] : ['or', [$column => $segment], ['and', [$column => null], [$field->name => $segment]]]);
      $id = $query->scalar();
      if (false === $id || null === $id) {
        return null;
      }
      $parent = (string)$id;
    }
    return $parent;
  }

  /**
   * Entity a reference field points to - if its fields may be used here.
   *
   * @param array<string, list<string>> $errors
   */
  private function target(EntityDefinition $entity, FieldDefinition $field, int $depth, RecordQueryContext $context, string $key, array &$errors): ?EntityDefinition
  {
    if ($depth + 1 >= self::MAX_DEPTH) {
      $errors[$key][] = I18n::t('At most {count} levels of references in a filter or sort order.', ['count' => self::MAX_DEPTH]);
      return null;
    }
    $target = null === $field->referenceEntityId ? null
      : ($field->referenceEntityId === $entity->id ? $entity : $this->entityRepository->findById($field->referenceEntityId));
    if (null === $target) {
      $errors[$key][] = I18n::t('"{field}" does not reference an entity.', ['field' => $field->name]);
      return null;
    }
    if (null !== $context->canRead && !($context->canRead)($target)) {
      $errors[$key][] = I18n::t('Fields of "{entity}" cannot be used to filter or sort here - no read access.', ['entity' => $target->name]);
      return null;
    }
    return $target;
  }

  private static function condition(string $column, ?FieldDefinition $field, string $operator, mixed $value): array
  {
    if ('null' === $operator) {
      return filter_var($value, FILTER_VALIDATE_BOOL) ? [$column => null] : ['not', [$column => null]];
    }
    if ('like' === $operator) {
      return ['like', $column, (string)$value];
    }
    // Lists (repeatable fields): eq / in = contains the value(s), ne = does not contain it
    if (null !== $field && $field->repeatable && in_array($operator, ['eq', 'ne', 'in'], true)) {
      $values = 'in' === $operator ? (is_array($value) ? $value : explode(',', (string)$value)) : [$value];
      $contains = array_merge(['or'], array_map(static fn($v): array => ['like', $column, '"'.addcslashes((string)self::value($field, $v), '"\\').'"'], array_values($values)));
      return 'ne' === $operator ? ['or', ['not', $contains], [$column => null]] : $contains;
    }
    if ('in' === $operator) {
      $values = is_array($value) ? $value : explode(',', (string)$value);
      return [$column => array_map(static fn($v) => self::value($field, $v), array_values($values))];
    }

    $converted = self::value($field, $value);
    if (null === $converted) {
      return 'ne' === $operator ? ['not', [$column => null]] : [$column => null];
    }
    return match ($operator) {
      'eq' => [$column => $converted],
      'ne' => ['<>', $column, $converted],
      'gt' => ['>', $column, $converted],
      'gte' => ['>=', $column, $converted],
      'lt' => ['<', $column, $converted],
      'lte' => ['<=', $column, $converted],
    };
  }

  private static function value(?FieldDefinition $field, mixed $value): string|int|bool|null
  {
    if (null === $field) {
      return null === $value || is_bool($value) ? $value : (string)$value;
    }
    // Field types of plugins convert the value as when saving (user field: "user:<id>" is the id); one
    // they refuse matches nothing
    if (FieldType::Custom === $field->type) {
      try {
        return CustomFieldTypes::toStorage($field, $value);
      } catch (InvalidValueException) {
        return is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
    }
    // Filters on "required" values may compare to anything the type accepts
    return ValueConverter::convert($field->type, $value, max($field->length ?? 0, 1000), $field->scale);
  }

  private function sort(QueryInterface $query, EntityDefinition $entity, string $sort, RecordQueryContext $context): void
  {
    $order = [];
    $errors = [];
    foreach (array_filter(array_map('trim', explode(',', $sort))) as $part) {
      $descending = str_starts_with($part, '-');
      // name or country[name] or country[region][name]
      if (!preg_match('/^([A-Za-z0-9_]+)((?:\[[A-Za-z0-9_]+\])*)$/', ltrim($part, '-'), $match)) {
        $errors['sort'][] = I18n::t('"{sort}" is no valid sort order (e.g. name, -created_at or country[name]).', ['sort' => $part]);
        continue;
      }
      $path = [$match[1], ...('' === $match[2] ? [] : explode('][', trim($match[2], '[]')))];
      $expression = $this->sortColumn($entity, null, $path, 0, $context, $errors);
      if (is_string($expression)) {
        $order[$expression] = $descending ? SORT_DESC : SORT_ASC;
      } elseif (null !== $expression) {
        $order[] = new Expression(sprintf('(%s)%s', $expression, $descending ? ' DESC' : ''));
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors, I18n::t('The sort order does not fit the fields.'));
    }
    // Stable pages: equal values are ordered by id
    $order['id'] ??= SORT_ASC;
    $query->orderBy($order);
  }

  /**
   * Column to sort by: a plain column (string), or the SQL of a subquery that reads the value from
   * the referenced record (an object with the SQL in `sql`) - without a path its display field.
   *
   * @param list<string> $path
   * @param array<string, list<string>> $errors
   */
  private function sortColumn(EntityDefinition $entity, ?string $alias, array $path, int $depth, RecordQueryContext $context, array &$errors): string|SortSubquery|null
  {
    $name = array_shift($path);
    $field = $this->readable($entity->field($name));
    if (null === $field && !in_array($name, self::SYSTEM_COLUMNS, true) && !($entity->drafts && EntityDefinition::DRAFT === $name)) {
      $errors['sort'][] = 0 === $depth
        ? I18n::t('Cannot sort by "{field}" - the field does not exist.', ['field' => $name])
        : I18n::t('Cannot sort by "{field}" - "{entity}" has no such field.', ['field' => $name, 'entity' => $entity->name]);
      return null;
    }
    $column = self::qualify($alias, null !== $field ? $entity->column($field, $context->language) : $name);
    $isReference = null !== $field && FieldType::Reference === $field->type;
    if ([] !== $path && (!$isReference || $field->repeatable)) {
      $errors['sort'][] = I18n::t('"{field}" is not a single reference - "{field}[…]" is not possible here.', ['field' => $name]);
      return null;
    }
    if (!$isReference || $field->repeatable) {
      return $column;
    }

    // References: by the display field of the referenced record - it has none, or the path is
    // too deep for a default: by the id as before
    if ([] === $path) {
      $target = $field->referenceEntityId === $entity->id ? $entity : $this->entityRepository->findById((string)$field->referenceEntityId);
      $display = $target?->displayField();
      if (null === $display || $depth + 1 >= self::MAX_DEPTH || (null !== $context->canRead && !($context->canRead)($target))) {
        return $column;
      }
      $path = [$display];
    } else {
      $target = $this->target($entity, $field, $depth, $context, 'sort', $errors);
      if (null === $target) {
        return null;
      }
    }

    $targetAlias = $context->nextAlias();
    $inner = $this->sortColumn($target, $targetAlias, $path, $depth + 1, $context, $errors);
    if (null === $inner) {
      return null;
    }
    $quoter = $this->db->getQuoter();
    // Correlated subquery without parameters: its SQL can be placed into ORDER BY as it is
    return new SortSubquery(sprintf(
      'SELECT %s FROM %s %s WHERE %s = %s LIMIT 1',
      $inner instanceof SortSubquery ? '('.$inner.')' : $quoter->quoteColumnName($inner),
      $quoter->quoteTableName($target->tableName()),
      $quoter->quoteTableName($targetAlias),
      $quoter->quoteColumnName($targetAlias.'.id'),
      $quoter->quoteColumnName(self::qualify($alias ?? $entity->tableName(), $entity->column($field, null))),
    ));
  }

  private static function qualify(?string $alias, string $column): string
  {
    return null === $alias ? $column : $alias.'.'.$column;
  }
}
