<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Schema\CustomFieldTypes;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\GroupValues;
use App\Repository\EntityRepository;
use App\Repository\RecordRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\QueryInterface;

/**
 * Search index per project (tables search_term, search_posting, search_state):
 *
 *   term  "sommerfest"  ->  record 1Cf… of "pages", field "title", language "" (default), weight 3
 *
 * Indexed are the searchable text fields (FieldDefinition::isSearchable) in every language, as
 * SearchNormalizer turns them into terms - stop words of the project left out. The text search
 * (?s=) of the admin app and the content API uses it for every entity whose index is "ready"; a
 * "stale" one (after changes to stop words, languages or search settings, or a cleared index)
 * is searched in its columns as before until it is rebuilt.
 */
final class SearchIndex
{
  public const READY = 'ready';
  public const STALE = 'stale';
  /** Terms a prefix of the last search word may stand for */
  private const PREFIX_TERMS = 300;
  private const CHUNK = 500;

  /** @var array<string, list<string>> project id => normalized stop words */
  private array $stopwords = [];

  public function __construct(
    private ConnectionInterface $db,
    private RecordRepository $records,
    private EntityRepository $entities,
  ) {
  }

  // ---------------------------------------------------------------------------------------------
  // Searching

  /**
   * Records matching the search, as a query of (search_record, search_score) - null if the index
   * of the entity is not ready or the search has no terms (only stop words): search the columns then.
   * All terms have to occur, the last one also as the start of a word ("sommerf" -> "sommerfest").
   *
   * @param list<string> $fieldIds the fields that may be searched (searchable and readable)
   */
  public function match(EntityDefinition $entity, string $search, ?string $language, array $fieldIds): ?QueryInterface
  {
    if (!$this->isReady($entity)) {
      return null;
    }
    // The stop words of the language searched in (translations: also of the default one, like the postings)
    $language = null !== $language && $language !== $entity->defaultLanguage() ? $language : '';
    $terms = array_values(array_unique(SearchNormalizer::terms($search, $this->stopwords($entity->projectId, $language))));
    if ([] === $terms) {
      return null;
    }
    $groups = [];
    foreach ($terms as $index => $term) {
      $query = $this->db->createQuery()->from('search_term')->select('id')->where(['project_id' => $entity->projectId]);
      $index === count($terms) - 1
        ? $query->andWhere(new Expression('[[term]] LIKE :prefix', [':prefix' => $term.'%']))->limit(self::PREFIX_TERMS)
        : $query->andWhere(['term' => $term]);
      $groups[] = array_map('intval', $query->column());
    }
    // No match possible: the same columns, no rows
    $nothing = $this->db->createQuery()->select(['search_record' => 'record_id', 'search_score' => new Expression('0')])->from('search_posting')->where('1 = 0');
    if ([] === $fieldIds || in_array([], $groups, true)) {
      return $nothing;
    }
    $languages = array_values(array_unique(['', null !== $language && $language !== $entity->defaultLanguage() ? $language : '']));
    // Score: how often a term occurs times the weight of its field (set in the schema, counted now -
    // changing it needs no rebuild)
    $weights = [];
    $params = [];
    foreach ($entity->fields as $field) {
      if (1 !== $field->searchWeight && in_array($field->id, $fieldIds, true)) {
        $weights[] = 'WHEN :w'.count($params).' THEN '.$field->searchWeight;
        $params[':w'.count($params)] = $field->id;
      }
    }
    $score = [] !== $weights ? 'SUM([[weight]] * CASE [[field_id]] '.implode(' ', $weights).' ELSE 1 END)' : 'SUM([[weight]])';
    $query = $this->db->createQuery()
      ->select(['search_record' => 'record_id', 'search_score' => new Expression($score, $params)])
      ->from('search_posting')
      ->where(['entity_id' => $entity->id, 'term_id' => array_values(array_unique(array_merge(...$groups))), 'field_id' => $fieldIds, 'language' => $languages])
      ->groupBy('record_id');
    foreach ($groups as $ids) {
      $query->andHaving(new Expression('MAX([[term_id]] IN ('.implode(',', $ids).')) = 1'));
    }
    return $query;
  }

  public function isReady(EntityDefinition $entity): bool
  {
    $status = $this->db->createQuery()->from('search_state')->select('status')->where(['entity_id' => $entity->id])->scalar();
    // Entities created after the index was introduced have it from the start
    return false === $status || null === $status || self::READY === $status;
  }

  // ---------------------------------------------------------------------------------------------
  // Keeping it up to date

  /**
   * After a record was saved (or several, e.g. by an import).
   *
   * @param list<array<string, mixed>> $rows stored rows
   */
  public function index(EntityDefinition $entity, array $rows): void
  {
    if ([] === $rows || !$this->isReady($entity)) {
      return;
    }
    $this->db->createCommand()->delete('search_posting', ['entity_id' => $entity->id, 'record_id' => array_map(static fn(array $row): string => (string)$row['id'], $rows)])->execute();
    $this->write($entity, $rows);
  }

  public function forget(EntityDefinition $entity, string $recordId): void
  {
    $this->db->createCommand()->delete('search_posting', ['entity_id' => $entity->id, 'record_id' => $recordId])->execute();
  }

  /**
   * A field that was deleted
   */
  public function forgetField(string $fieldId): void
  {
    $this->db->createCommand()->delete('search_posting', ['field_id' => $fieldId])->execute();
  }

  /**
   * After changes that the index does not reflect any more (search settings of fields, languages,
   * stop words): searches go to the columns until it is rebuilt.
   */
  public function markStale(EntityDefinition $entity): void
  {
    $this->state($entity, self::STALE);
  }

  /**
   * A new entity: its index is ready (and empty).
   */
  public function entityCreated(EntityDefinition $entity): void
  {
    $this->state($entity, self::READY, 0, date('Y-m-d H:i:s'));
  }

  /**
   * Builds the index of an entity anew. Returns the number of records.
   */
  public function rebuild(EntityDefinition $entity): int
  {
    $this->clear($entity);
    $count = 0;
    $lastId = '';
    do {
      $rows = $this->records->queryWithTrashed($entity)->andWhere(['>', 'id', $lastId])->orderBy(['id' => SORT_ASC])->limit(self::CHUNK)->all();
      if ([] !== $rows) {
        $this->write($entity, $rows);
        $count += count($rows);
        $lastId = (string)end($rows)['id'];
      }
    } while (count($rows) === self::CHUNK);
    $this->state($entity, self::READY, $count, date('Y-m-d H:i:s'));
    return $count;
  }

  /**
   * Empties the index of an entity - searches go to the columns until it is rebuilt.
   */
  public function clear(EntityDefinition $entity): void
  {
    $this->db->createCommand()->delete('search_posting', ['entity_id' => $entity->id])->execute();
    $this->state($entity, self::STALE, 0);
  }

  /**
   * Terms no record uses any more.
   */
  public function pruneTerms(string $projectId): int
  {
    $used = $this->db->createQuery()->from('search_posting')->select('term_id')->distinct();
    return $this->db->createCommand()->delete('search_term', ['and', ['project_id' => $projectId], ['not in', 'id', $used]])->execute();
  }

  // ---------------------------------------------------------------------------------------------
  // Stop words and status

  /**
   * Stop words per language, as entered: "*" = for all languages (e.g. numbers), "" = the default
   * language of the project, else the code of a translation (the keys of search_posting.language).
   *
   * @return array<string, list<string>>
   */
  public function stopwordLists(string $projectId): array
  {
    $value = $this->db->createQuery()->from('project')->select('stopwords')->where(['id' => $projectId])->scalar();
    $lists = is_string($value) ? json_decode($value, true) : null;
    if (!is_array($lists)) {
      return [];
    }
    // One list for all (stored like that before): the default language
    if (array_is_list($lists)) {
      $lists = ['' => $lists];
    }
    return array_map(static fn(mixed $list): array => array_values(array_map('strval', (array)$list)), $lists);
  }

  /**
   * Sets the stop words of a project per language - every index of it has to be rebuilt then.
   *
   * @param array<string, list<string>> $lists language ("" = default) => words
   */
  public function setStopwords(string $projectId, array $lists): void
  {
    $clean = [];
    foreach ($lists as $language => $words) {
      $words = array_values(array_unique(array_filter(array_map(static fn($word): string => mb_strtolower(trim((string)$word)), (array)$words), static fn(string $word): bool => '' !== $word)));
      sort($words);
      if ([] !== $words) {
        $clean[(string)$language] = $words;
      }
    }
    $this->db->createCommand()->update('project', ['stopwords' => [] !== $clean ? json_encode((object)$clean, JSON_UNESCAPED_UNICODE) : null], ['id' => $projectId])->execute();
    unset($this->stopwords[$projectId]);
    $this->db->createCommand()->update('search_state', ['status' => self::STALE], ['project_id' => $projectId])->execute();
  }

  /**
   * Index of every entity of the list: status, records, last build.
   *
   * @param list<EntityDefinition> $entities
   * @return list<array{entity: string, slug: string, name: string, status: string, records: int, built_at: ?string}>
   */
  public function status(array $entities): array
  {
    $states = [];
    foreach ($this->db->createQuery()->from('search_state')->where(['entity_id' => array_map(static fn(EntityDefinition $e): string => $e->id, $entities)])->all() as $row) {
      $states[(string)$row['entity_id']] = $row;
    }
    return array_map(fn(EntityDefinition $entity): array => [
      'entity' => $entity->id,
      'slug' => $entity->slug,
      'name' => $entity->name,
      'status' => (string)($states[$entity->id]['status'] ?? self::READY),
      'records' => $this->records->count($entity),
      'indexed' => (int)$this->db->createQuery()->from('search_posting')->select('record_id')->distinct()->where(['entity_id' => $entity->id])->count(),
      'built_at' => isset($states[$entity->id]['built_at']) ? (string)$states[$entity->id]['built_at'] : null,
    ], $entities);
  }

  // ---------------------------------------------------------------------------------------------

  /**
   * Postings of the rows (their old ones are gone already).
   *
   * @param list<array<string, mixed>> $rows
   */
  private function write(EntityDefinition $entity, array $rows): void
  {
    // Group fields and blocks: the texts of their fields
    $fields = array_values(array_filter($entity->fields, static fn(FieldDefinition $f): bool => $f->isSearchable() && ($f->type->isTextual() || FieldType::Group === $f->type || FieldType::Custom === $f->type || FieldType::Code === $f->type)));
    if ([] === $fields) {
      return;
    }
    $postings = [];
    foreach ($rows as $row) {
      foreach ($fields as $field) {
        $columns = ['' => $field->name];
        foreach ($field->translatable ? $entity->otherLanguages() : [] as $code) {
          $columns[$code] = $field->translationColumn($code);
        }
        foreach ($columns as $language => $column) {
          $text = match (true) {
            FieldType::Group === $field->type => self::groupText($field, $row[$column] ?? null),
            // Fields of plugins: their text (e.g. rich text without the tags)
            FieldType::Custom === $field->type => CustomFieldTypes::text($field, CustomFieldTypes::fromStorage($field, $row[$column] ?? null)),
            FieldType::Code === $field->type => \App\Domain\Schema\CodeValue::text($row[$column] ?? null),
            default => self::text($row[$column] ?? null),
          };
          $counts = array_count_values(SearchNormalizer::terms($text, $this->stopwords($entity->projectId, (string)$language)));
          foreach ($counts as $term => $count) {
            // How often it occurs (at most 3) - the weight of the field counts when searching
            $postings[] = [(string)$term, (string)$row['id'], $field->id, (string)$language, min($count, 3)];
          }
        }
      }
    }
    if ([] === $postings) {
      return;
    }
    $ids = $this->termIds($entity->projectId, array_values(array_unique(array_column($postings, 0))));
    $rows = array_map(static fn(array $p): array => [$ids[$p[0]], $entity->id, $p[1], $p[2], $p[3], $p[4]], $postings);
    foreach (array_chunk($rows, 1000) as $chunk) {
      $this->db->createCommand()->insertBatch('search_posting', $chunk, ['term_id', 'entity_id', 'record_id', 'field_id', 'language', 'weight'])->execute();
    }
  }

  /**
   * Ids of the terms, new ones are added.
   *
   * @param list<string> $terms
   * @return array<string, int>
   */
  private function termIds(string $projectId, array $terms): array
  {
    $ids = [];
    foreach (array_chunk($terms, self::CHUNK) as $chunk) {
      $placeholders = [];
      $params = [':project' => $projectId];
      foreach ($chunk as $i => $term) {
        $placeholders[] = "(:project, :t{$i})";
        $params[":t{$i}"] = $term;
      }
      $this->db->createCommand('INSERT IGNORE INTO {{%search_term}} ([[project_id]], [[term]]) VALUES '.implode(', ', $placeholders), $params)->execute();
      foreach ($this->db->createQuery()->from('search_term')->select(['id', 'term'])->where(['project_id' => $projectId, 'term' => $chunk])->all() as $row) {
        $ids[(string)$row['term']] = (int)$row['id'];
      }
    }
    return $ids;
  }

  /**
   * The normalized stop words of a language ("" = default): its own and the ones for all.
   *
   * @return list<string>
   */
  private function stopwords(string $projectId, string $language): array
  {
    $lists = $this->stopwordLists($projectId);
    return $this->stopwords[$projectId][$language] ??= SearchNormalizer::stopwords([...($lists['*'] ?? []), ...($lists[$language] ?? [])]);
  }

  private function state(EntityDefinition $entity, string $status, ?int $records = null, ?string $builtAt = null): void
  {
    $values = ['status' => $status] + (null !== $records ? ['records' => $records] : []) + (null !== $builtAt ? ['built_at' => $builtAt] : []);
    if (0 === $this->db->createCommand()->update('search_state', $values, ['entity_id' => $entity->id])->execute()
      && !$this->db->createQuery()->from('search_state')->where(['entity_id' => $entity->id])->exists()) {
      $this->db->createCommand()->insert('search_state', $values + ['entity_id' => $entity->id, 'project_id' => $entity->projectId])->execute();
    }
  }

  /**
   * Texts inside groups and blocks: text fields, and the text of fields of plugins.
   */
  private static function groupText(FieldDefinition $groupField, mixed $value): string
  {
    $texts = [];
    GroupValues::each($groupField, $value, static function (FieldDefinition $field, mixed $inner) use (&$texts): void {
      if (!$field->searchable) {
        return;
      }
      if (FieldType::Custom === $field->type) {
        $texts[] = CustomFieldTypes::text($field, CustomFieldTypes::fromStorage($field, $inner));
      } elseif (FieldType::Code === $field->type) {
        $texts[] = \App\Domain\Schema\CodeValue::text($inner);
      } elseif ($field->type->isTextual()) {
        $texts[] = is_array($inner) ? implode(' ', array_filter($inner, 'is_scalar')) : (string)$inner;
      }
    });
    return implode(' ', $texts);
  }

  /**
   * Stored value as text: lists (repeatable fields) joined.
   */
  private static function text(mixed $value): string
  {
    if (null === $value) {
      return '';
    }
    $value = (string)$value;
    if (str_starts_with($value, '[')) {
      $list = json_decode($value, true);
      if (is_array($list)) {
        return implode(' ', array_map(static fn($item): string => is_scalar($item) ? (string)$item : (string)json_encode($item, JSON_UNESCAPED_UNICODE), $list));
      }
    }
    return $value;
  }
}
