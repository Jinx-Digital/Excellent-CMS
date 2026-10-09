<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Content\RecordQuery;
use App\Domain\Schema\EntityDefinition;
use App\Repository\RecordRepository;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;

/**
 * Conditions of events, in the filter language of the content API - checked in the database
 * against the record itself, so every operator and filters on references work:
 *
 *   {"draft": false, "price": {"gte": 10}, "category.slug": "shoes"}
 *
 * plus two keys for changes:
 *   "_changed": ["price", "title"]       one of these fields changed (create: was filled in)
 *   "_old": {"price": {"lt": 10}}        the state before (eq, ne, gt, gte, lt, lte, in, like, null)
 */
final class ConditionMatcher
{
  public const OLD_OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'like', 'null'];

  public function __construct(
    private RecordQuery $recordQuery,
    private RecordRepository $records,
  ) {
  }

  /**
   * @param array|null $condition null/[] = always
   * @param array|null $data the record as presented now; $old as it was (null: new record)
   */
  public function matches(EntityDefinition $entity, ?array $condition, string $recordId, array $data, ?array $old): bool
  {
    if (null === $condition || [] === $condition) {
      return true;
    }
    $changed = $condition['_changed'] ?? null;
    if (is_array($changed) && [] === array_filter($changed, static fn($name): bool => self::differs($data[(string)$name] ?? null, $old[(string)$name] ?? null))) {
      return false;
    }
    $before = $condition['_old'] ?? null;
    if (is_array($before) && (null === $old || !self::matchesValues($before, $old))) {
      return false;
    }
    $filter = array_diff_key($condition, ['_changed' => true, '_old' => true]);
    if ([] === $filter) {
      return true;
    }
    $query = $this->recordQuery->apply($this->records->queryWithTrashed($entity), $entity, '', $filter, 'id', schemaLimits: false);
    return $query->andWhere(['id' => $recordId])->exists();
  }

  /**
   * Conditions of events on media and variables: no table to ask, the data is checked directly
   * (operators as for _old).
   */
  public function matchesData(?array $condition, array $data, ?array $old): bool
  {
    if (null === $condition || [] === $condition) {
      return true;
    }
    $changed = $condition['_changed'] ?? null;
    if (is_array($changed) && [] === array_filter($changed, static fn($name): bool => self::differs($data[(string)$name] ?? null, $old[(string)$name] ?? null))) {
      return false;
    }
    $before = $condition['_old'] ?? null;
    if (is_array($before) && (null === $old || !self::matchesValues($before, $old))) {
      return false;
    }
    return self::matchesValues(array_diff_key($condition, ['_changed' => true, '_old' => true]), $data);
  }

  /**
   * Conditions of events on media and variables: only the operators the data check knows.
   *
   * @throws ValidationException errors under "condition"
   */
  public function validateData(array $condition): void
  {
    $errors = [];
    foreach (array_merge(array_diff_key($condition, ['_changed' => true, '_old' => true]), (array)($condition['_old'] ?? [])) as $test) {
      foreach (is_array($test) && !array_is_list($test) ? array_keys($test) : [] as $operator) {
        if (!in_array($operator, self::OLD_OPERATORS, true)) {
          $errors['condition'][] = I18n::t('Unknown comparison "{operator}" (allowed: {allowed}).', ['operator' => (string)$operator, 'allowed' => implode(', ', self::OLD_OPERATORS)]);
        }
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
  }

  /**
   * Checks a condition when an event is saved: the filter must be valid for the entity.
   *
   * @throws ValidationException errors under "condition"
   */
  public function validate(EntityDefinition $entity, array $condition): void
  {
    $errors = [];
    foreach (['_changed' => 'is_array', '_old' => 'is_array'] as $key => $check) {
      if (array_key_exists($key, $condition) && !$check($condition[$key])) {
        $errors['condition'][] = I18n::t('"{key}" must be a list or an object.', ['key' => $key]);
      }
    }
    foreach ((array)($condition['_changed'] ?? []) as $name) {
      if (null === $entity->field((string)$name) && EntityDefinition::DRAFT !== $name) {
        $errors['condition'][] = I18n::t('Cannot filter by "{field}" - the field does not exist.', ['field' => (string)$name]);
      }
    }
    foreach ((array)($condition['_old'] ?? []) as $name => $test) {
      if (null === $entity->field((string)$name) && EntityDefinition::DRAFT !== $name) {
        $errors['condition'][] = I18n::t('Cannot filter by "{field}" - the field does not exist.', ['field' => (string)$name]);
      }
      foreach (is_array($test) && !array_is_list($test) ? array_keys($test) : [] as $operator) {
        if (!in_array($operator, self::OLD_OPERATORS, true)) {
          $errors['condition'][] = I18n::t('Unknown comparison "{operator}" (allowed: {allowed}).', ['operator' => (string)$operator, 'allowed' => implode(', ', self::OLD_OPERATORS)]);
        }
      }
    }
    $filter = array_diff_key($condition, ['_changed' => true, '_old' => true]);
    if ([] !== $filter) {
      try {
        $this->recordQuery->apply($this->records->queryWithTrashed($entity), $entity, '', $filter, 'id', schemaLimits: false);
      } catch (ValidationException $e) {
        foreach ($e->getErrors() as $messages) {
          $errors['condition'] = array_merge($errors['condition'] ?? [], (array)$messages);
        }
      }
    }
    if ([] !== ($errors['condition'] ?? [])) {
      throw new ValidationException($errors);
    }
  }

  private static function differs(mixed $a, mixed $b): bool
  {
    return json_encode($a) !== json_encode($b);
  }

  private static function matchesValues(array $filter, array $values): bool
  {
    foreach ($filter as $name => $test) {
      $value = $values[(string)$name] ?? null;
      foreach (is_array($test) && !array_is_list($test) ? $test : ['eq' => $test] as $operator => $expected) {
        $ok = match ((string)$operator) {
          'eq' => self::same($value, $expected),
          'ne' => !self::same($value, $expected),
          'gt' => null !== $value && $value > $expected,
          'gte' => null !== $value && $value >= $expected,
          'lt' => null !== $value && $value < $expected,
          'lte' => null !== $value && $value <= $expected,
          'in' => in_array((string)(is_scalar($value) ? $value : json_encode($value)), array_map('strval', is_array($expected) ? $expected : explode(',', (string)$expected)), true),
          'like' => is_scalar($value) && false !== mb_stripos((string)$value, (string)$expected),
          'null' => filter_var($expected, FILTER_VALIDATE_BOOL) === (null === $value || '' === $value),
          default => false,
        };
        if (!$ok) {
          return false;
        }
      }
    }
    return true;
  }

  private static function same(mixed $value, mixed $expected): bool
  {
    if (is_bool($value) || is_bool($expected)) {
      return (bool)$value === filter_var($expected, FILTER_VALIDATE_BOOL);
    }
    return (string)(is_scalar($value) ? $value : json_encode($value)) === (string)$expected;
  }
}
