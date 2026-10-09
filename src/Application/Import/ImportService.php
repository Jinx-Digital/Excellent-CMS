<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Schema\SchemaService;
use App\Application\Service\CurrentUser;
use App\Domain\Access\EntityPermission;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\FieldDefinition;
use App\Application\Content\RecordService;
use App\Application\Content\TreeService;
use App\Application\Project\ProjectVariables;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\Slug;
use App\Domain\Schema\InvalidValueException;
use App\Domain\Schema\ValueConverter;
use App\Infrastructure\Import\SpreadsheetReader;
use App\Repository\EntityRepository;
use App\Repository\RecordRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;
use App\Shared\Naming;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * CSV/Excel import in four steps, like the inventory import of Fixoo:
 *
 *  1. upload   - file is kept (2 hours), answer: columns, samples and suggestions
 *  2. analyze  - again for another worksheet / separator
 *  3. preview  - what would happen per row (nothing is written)
 *  4. run      - creates the entity (or new fields) and imports the valid rows
 *
 * The plan sent with preview/run decides the target:
 *   mode "new":      a new entity (admins); every mapped column becomes a field
 *   mode "existing": records of an existing entity; with `key` existing records are updated
 *
 *   {
 *     "sheet": "Tabelle1", "delimiter": ";",
 *     "mode": "new", "entity": {"slug": "authors", "name": "Autoren", "access": "public", "label_field": "name"},
 *     "mode": "existing", "target": "authors", "key": "name",
 *     "columns": [
 *       {"column": "name", "field": "name", "label": "Name", "type": "string", "required": true, "unique": true},
 *       {"column": "country", "field": "country", "type": "reference", "reference": "countries", "match": "alpha2code"},
 *       {"column": "land", "field": "land", "type": "reference", "reference": "countries", "match": "name", "create_missing": true},
 *       {"column": "genre", "field": "genre", "type": "reference", "new_reference": {"slug": "genres", "name": "Genres"}, "repeatable": true},
 *       {"column": "notes", "field": null}
 *     ]
 *   }
 *
 * In mode "existing" a column maps to an existing field by its name, or adds a field with "new": true.
 * Reference columns hold a value of the target's `match` field (default: its id) - e.g. the
 * country code - and are stored as the id of the found record. Values are found in every language
 * of the field, upper/lower case does not matter. `create_missing` adds the values that are not
 * found to the target; `new_reference` creates a new entity (field "name") with one record per
 * different value of the column (admins). Rows with errors are skipped and reported with their line
 * number; all others are imported.
 */
final class ImportService
{
  private const EXPIRES_AFTER = 7200;
  private const MAX_FILE_SIZE = 50 * 1024 * 1024;
  private const PREVIEW_ROWS = 50;
  private const MAX_REPORTED_ERRORS = 500;
  private const REFERENCE_SAMPLE = 50;
  private const LABEL_NAMES = ['name', 'title', 'titel', 'label', 'bezeichnung', 'land', 'country', 'firma', 'company', 'nachname', 'last_name', 'email', 'slug'];

  public function __construct(
    private SpreadsheetReader $reader,
    private SchemaService $schema,
    private EntityRepository $entityRepository,
    private RecordRepository $records,
    private CurrentUser $currentUser,
    private ConnectionInterface $db,
    private TreeService $tree,
    private ProjectVariables $variables,
    private string $storagePath,
    private int $maxRows = 50000,
    private ?RecordService $recordService = null,
    private ?\App\Application\Access\FieldAccess $fieldAccess = null,
  ) {
  }

  public function upload(UploadedFileInterface $file): array
  {
    $this->assertMayImport();
    if (UPLOAD_ERR_OK !== $file->getError()) {
      throw new UserFacingException(UPLOAD_ERR_INI_SIZE === $file->getError() || UPLOAD_ERR_FORM_SIZE === $file->getError()
        ? I18n::t('The file is too large.')
        : I18n::t('The file could not be uploaded.'));
    }
    if ((int)$file->getSize() > self::MAX_FILE_SIZE) {
      throw new UserFacingException(I18n::t('The file is too large (at most {size} MB).', ['size' => self::MAX_FILE_SIZE / 1024 / 1024]), 413, 'import_too_large');
    }
    $name = basename((string)$file->getClientFilename());
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, SpreadsheetReader::EXTENSIONS, true)) {
      throw new UserFacingException(I18n::t('Please upload a CSV or Excel file (.csv, .xlsx, .xls, .ods).'));
    }

    $this->removeExpired();
    $importId = Id::new();
    $directory = $this->directory($importId);
    mkdir($directory, 0770, true);
    file_put_contents($directory.'/source.'.$extension, (string)$file->getStream());
    file_put_contents($directory.'/meta.json', (string)json_encode([
      'file_name' => $name,
      'extension' => $extension,
      'user_id' => $this->currentUser->getId(),
      'created_at' => time(),
    ]));

    try {
      return $this->analyze($importId, []);
    } catch (Throwable $e) {
      $this->discard($importId);
      throw $e;
    }
  }

  /**
   * Columns with samples and suggested fields, plus existing entities the file fits.
   *
   * @param array{sheet?: ?string, delimiter?: ?string} $options
   */
  public function analyze(string $importId, array $options): array
  {
    $meta = $this->meta($importId);
    $table = $this->read($importId, $meta, $options);
    $columns = $table['columns'];
    $rows = $table['rows'];

    $existing = $this->matchingEntities($columns);
    // An entity the file seems to be a copy of is no reference target for its own columns
    $excluded = array_column(array_filter($existing, static fn(array $m): bool => $m['score'] >= 0.5), 'entity');

    $usedNames = [];
    $analysis = [];
    foreach ($columns as $index => $column) {
      $values = array_column($rows, $index);
      $detected = TypeDetector::detect($values, Naming::snake($column));
      $name = $this->fieldName($column, $index, $usedNames);
      $suggestion = [
        'field' => $name,
        'label' => self::labelOf($column),
        'type' => $detected['type'],
        'length' => $detected['length'],
        'scale' => $detected['scale'],
        'uuid_version' => $detected['uuid_version'],
        'required' => $detected['required'],
        'unique' => $detected['unique'],
        'reference' => null,
        'match' => null,
      ];
      // Entities the values could point to (e.g. "Land" -> countries by name), the best one is suggested
      $candidates = [];
      if (!$detected['unique'] && in_array($detected['type'], [FieldType::String->value, FieldType::Integer->value, FieldType::Uuid->value], true)) {
        $candidates = $this->referenceCandidates(Naming::snake($column), $values, $excluded);
        $reference = null !== ($candidates[0] ?? null) && $candidates[0]['found'] >= 0.9 * $candidates[0]['total'] ? $candidates[0] : null;
        if (null !== $reference) {
          $suggestion = ['type' => FieldType::Reference->value, 'length' => null, 'scale' => null, 'uuid_version' => null, 'reference' => $reference['entity'], 'match' => $reference['match']] + $suggestion;
        }
      }

      // Yes/no: which values of the file mean yes and which no - the user can change it
      $distinct = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string)$v), $values), static fn(string $v): bool => '' !== $v)));
      if (FieldType::Boolean->value === $suggestion['type']) {
        $suggestion += self::booleanSuggestion($distinct);
      }

      $samples = [];
      foreach ($values as $value) {
        if (null !== $value && !in_array($value, $samples, true)) {
          $samples[] = mb_strimwidth($value, 0, 80, '…');
          if (count($samples) >= 3) {
            break;
          }
        }
      }
      $analysis[] = [
        'column' => $column,
        'samples' => $samples,
        'empty' => $detected['empty'],
        'distinct' => $detected['distinct'],
        // The values themselves for small sets (e.g. to say what means yes and no)
        'values' => count($distinct) <= self::VALUE_LIST ? $distinct : [],
        'suggestion' => $suggestion,
        'references' => $candidates,
      ];
    }

    $slug = $this->suggestSlug((string)$meta['file_name']);
    return [
      'import_id' => $importId,
      'file_name' => $meta['file_name'],
      'format' => $meta['extension'],
      'sheets' => $this->reader->sheets($this->sourcePath($importId, $meta), (string)$meta['extension']),
      'sheet' => $table['sheet'],
      'delimiter' => $table['delimiter'],
      'row_count' => count($rows),
      'columns' => $analysis,
      'rows' => array_slice($rows, 0, 5),
      'entity' => [
        'slug' => $slug,
        'name' => Naming::label(str_replace('_', ' ', $slug)),
        'access' => 'public',
        'label_field' => $this->suggestLabelField($analysis),
      ],
      'existing' => $existing,
      'may_create' => $this->currentUser->isAdmin(),
    ];
  }

  public function preview(string $importId, array $plan): array
  {
    return $this->process($importId, $plan, false);
  }

  public function run(string $importId, array $plan): array
  {
    $result = $this->process($importId, $plan, true);
    $this->discard($importId);
    return $result;
  }

  public function discard(string $importId): void
  {
    if (!Id::isValid($importId)) {
      return;
    }
    $directory = $this->directory($importId);
    foreach (glob($directory.'/*') ?: [] as $file) {
      @unlink($file);
    }
    @rmdir($directory);
  }

  /**
   * Deletes uploads nobody finished (also called by `./yii cleanup`).
   */
  public function removeExpired(): int
  {
    $count = 0;
    foreach (glob($this->storagePath.'/*/meta.json') ?: [] as $metaFile) {
      if (filemtime($metaFile) < time() - self::EXPIRES_AFTER) {
        $this->discard(basename(dirname($metaFile)));
        $count++;
      }
    }
    return $count;
  }

  private function process(string $importId, array $plan, bool $write): array
  {
    $meta = $this->meta($importId);
    $table = $this->read($importId, $meta, $plan);
    $headers = array_flip($table['columns']);
    $mode = (string)($plan['mode'] ?? 'new');

    // Columns that become entities of their own ("genre" -> genres): created first when writing
    $newReferences = [];
    $createdEntities = [];
    $plan = $this->newReferences($plan, $headers, $table['rows'], $write, $newReferences, $createdEntities);
    try {
      $result = $this->processPlan($plan, $table, $headers, $mode, $write);
    } catch (Throwable $e) {
      foreach ($createdEntities as $id) {
        $this->schema->deleteEntity($id);
      }
      throw $e;
    }
    $result['new_references'] = $newReferences + $result['new_references'];
    return $result;
  }

  private function processPlan(array $plan, array $table, array $headers, string $mode, bool $write): array
  {
    [$entity, $mapping, $newFields, $entityData] = 'existing' === $mode
      ? $this->planExisting($plan, $headers)
      : $this->planNew($plan, $headers);

    $key = 'existing' === $mode && '' !== trim((string)($plan['key'] ?? '')) ? trim((string)$plan['key']) : null;
    if (null !== $key && !in_array($key, array_column($mapping, 'name'), true)) {
      throw ValidationException::field('key', I18n::t('The field that identifies existing records must be mapped to a column.'));
    }

    $created = [];
    $references = $this->resolveReferences($entity, $mapping, $table['rows'], $write, $created);
    $result = $this->checkRows($entity, $mapping, $newFields, $references, $table['rows'], $key, $write);
    $result['new_references'] = $created;

    if ($write) {
      $result['entity'] = $this->write($entity, $mode, $entityData, $newFields, $result['insert'], $result['update']);
    }
    unset($result['insert'], $result['update']);
    return $result;
  }

  /**
   * @param array<string, int> $headers
   * @return array{0: EntityDefinition, 1: list<array{index: int, column: string, name: string, field: FieldDefinition, match: ?string}>, 2: list<array>, 3: array}
   */
  private function planNew(array $plan, array $headers): array
  {
    $this->currentUser->assertAdmin();
    $fieldsData = [];
    $columnOf = [];
    $matches = [];
    $creates = [];
    $booleans = [];
    $errors = [];
    foreach ($this->planColumns($plan, $headers, $errors) as $column => $data) {
      $columnOf[count($fieldsData)] = $column;
      $matches[$data['field']] = $data['match'];
      $creates[$data['field']] = (bool)filter_var($data['create_missing'] ?? false, FILTER_VALIDATE_BOOL);
      $booleans[$data['field']] = self::booleanMap($data);
      $fieldsData[] = ['name' => $data['field']] + $data;
    }
    $entityData = (array)($plan['entity'] ?? []);
    foreach ($fieldsData as $index => $data) {
      $reference = (string)($data['reference'] ?? '');
      if (FieldType::Reference->value === ($data['type'] ?? null) && '' !== $reference && $reference === ($entityData['slug'] ?? null)) {
        $errors["columns.{$columnOf[$index]}.reference"][] = I18n::t('References to the new entity itself can only be added after the import.');
      }
      if (in_array($data['type'] ?? null, [FieldType::Media->value, FieldType::Group->value], true)) {
        $errors["columns.{$columnOf[$index]}.type"][] = I18n::t('Media and group fields cannot be imported - they are edited in the record.');
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }

    $entityData['fields'] = $fieldsData;
    try {
      $entity = $this->schema->prepareEntity($entityData);
    } catch (ValidationException $e) {
      // fields.<n>.<prop> -> columns.<column>.<prop>, the rest belongs to the entity form
      $mapped = [];
      foreach ($e->getErrors() as $field => $messages) {
        if (preg_match('/^fields\.(\d+)\.(.+)$/', $field, $m) && isset($columnOf[(int)$m[1]])) {
          $mapped["columns.{$columnOf[(int)$m[1]]}.{$m[2]}"] = $messages;
        } else {
          $mapped["entity.{$field}"] = $messages;
        }
      }
      throw new ValidationException($mapped);
    }

    $mapping = [];
    foreach ($entity->fields as $index => $field) {
      $column = $columnOf[$index];
      $mapping[] = ['index' => $headers[$column], 'column' => $column, 'name' => $field->name, 'field' => $field, 'match' => $matches[$field->name] ?? null, 'boolean' => $booleans[$field->name] ?? null, 'create_missing' => $creates[$field->name] ?? false];
    }
    return [$entity, $mapping, [], $entityData];
  }

  /**
   * @param array<string, int> $headers
   * @return array{0: EntityDefinition, 1: list<array>, 2: list<array>, 3: array}
   */
  private function planExisting(array $plan, array $headers): array
  {
    $entity = $this->schema->get((string)($plan['target'] ?? ''));
    $this->currentUser->assertCan(EntityPermission::Import, $entity);

    $errors = [];
    $mapping = [];
    $newFields = [];
    $used = [];
    foreach ($this->planColumns($plan, $headers, $errors) as $column => $data) {
      $name = $data['field'];
      if (isset($used[$name])) {
        $errors["columns.{$column}.field"][] = I18n::t('"{field}" is mapped to the column "{column}" already.', ['field' => $name, 'column' => $used[$name]]);
        continue;
      }
      $used[$name] = $column;

      if (in_array($data['type'] ?? null, [FieldType::Media->value, FieldType::Group->value], true) || in_array($entity->field($name)?->type, [FieldType::Media, FieldType::Group], true)) {
        $errors["columns.{$column}.field"][] = I18n::t('Media and group fields cannot be imported - they are edited in the record.');
        continue;
      }
      if (filter_var($data['new'] ?? false, FILTER_VALIDATE_BOOL)) {
        if (!$this->currentUser->isAdmin()) {
          $errors["columns.{$column}.field"][] = I18n::t('Only administrators may add new fields.');
          continue;
        }
        try {
          $field = $this->schema->prepareField($entity, ['name' => $name] + $data, "columns.{$column}");
        } catch (ValidationException $e) {
          $errors = array_merge($errors, $e->getErrors());
          continue;
        }
        $newFields[] = ['name' => $name] + $data;
      } else {
        $field = $entity->field($name);
        if (null === $field || (null !== $this->fieldAccess && !$this->fieldAccess->canRead($field))) {
          $errors["columns.{$column}.field"][] = I18n::t('"{entity}" has no field "{field}".', ['field' => $name, 'entity' => $entity->name]);
          continue;
        }
        if (null !== $this->fieldAccess && !$this->fieldAccess->canWrite($field)) {
          $errors["columns.{$column}.field"][] = I18n::t('You may not change this field.');
          continue;
        }
      }
      $mapping[] = ['index' => $headers[$column], 'column' => $column, 'name' => $name, 'field' => $field, 'match' => $data['match'], 'boolean' => self::booleanMap($data), 'create_missing' => (bool)filter_var($data['create_missing'] ?? false, FILTER_VALIDATE_BOOL)];
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    if ([] === $mapping) {
      throw ValidationException::field('columns', I18n::t('Please map at least one column to a field.'));
    }
    return [$entity, $mapping, $newFields, []];
  }

  /**
   * Mapped columns of the plan (column => field data); ignored columns are left out.
   *
   * @param array<string, int> $headers
   * @param array<string, string[]> $errors
   * @return array<string, array>
   */
  private function planColumns(array $plan, array $headers, array &$errors): array
  {
    $result = [];
    foreach ((array)($plan['columns'] ?? []) as $data) {
      if (!is_array($data)) {
        continue;
      }
      $column = (string)($data['column'] ?? '');
      $field = trim((string)($data['field'] ?? ''));
      if ('' === $field) {
        continue;
      }
      if (!isset($headers[$column])) {
        $errors['columns'][] = I18n::t('The file has no column "{column}".', ['column' => $column]);
        continue;
      }
      $result[$column] = ['field' => $field, 'match' => isset($data['match']) && '' !== (string)$data['match'] ? (string)$data['match'] : null] + $data;
    }
    if ([] === $result && [] === $errors) {
      $errors['columns'][] = I18n::t('Please map at least one column to a field.');
    }
    return $result;
  }

  /**
   * Looks up all values of the reference columns at once: column => raw value => record id.
   * With create_missing, the values that are not found are created in the target (only when
   * writing - the preview counts them).
   *
   * @param array<string, array> $created out: column => entity, count and some of the values created
   * @return array<string, array<string, string>>
   */
  private function resolveReferences(EntityDefinition $entity, array $mapping, array $rows, bool $write = false, array &$created = []): array
  {
    $result = [];
    $errors = [];
    foreach ($mapping as $map) {
      /** @var FieldDefinition $field */
      $field = $map['field'];
      if (FieldType::Reference !== $field->type) {
        continue;
      }
      $target = $this->entityRepository->findById((string)$field->referenceEntityId);
      if (null === $target) {
        $errors["columns.{$map['column']}.reference"][] = I18n::t('The referenced entity does not exist.');
        continue;
      }
      $match = $map['match'] ?? 'id';
      $matchField = 'id' === $match ? null : $target->field($match);
      if ('id' !== $match && null === $matchField) {
        $errors["columns.{$map['column']}.match"][] = I18n::t('"{entity}" has no field "{field}".', ['field' => $match, 'entity' => $target->name]);
        continue;
      }

      $converted = [];
      $cells = array_filter(array_column($rows, $map['index']), static fn($v): bool => null !== $v);
      // Lists: every value of "a | b | c" is looked up
      $raws = $field->repeatable ? array_merge(...array_map(self::splitList(...), $cells ?: [''])) : $cells;
      foreach (array_unique($raws) as $raw) {
        try {
          $value = null !== $matchField ? ValueConverter::toStorage($matchField, $raw) : trim($raw);
          if (null !== $value) {
            $converted[(string)$raw] = (string)$value;
          }
        } catch (InvalidValueException) {
          // stays unresolved -> row error
        }
      }
      $ids = $this->lookup($target, $match, array_values($converted));
      $missing = [];
      foreach ($converted as $raw => $value) {
        $id = $ids[self::lookupKey($match, $value)] ?? null;
        if (null !== $id) {
          $result[$map['column']][(string)$raw] = $id;
        } else {
          $missing[self::lookupKey($match, $value)] ??= (string)$raw;
        }
      }

      if (!($map['create_missing'] ?? false) || [] === $missing) {
        continue;
      }
      $problem = $this->cannotCreate($target, $match);
      if (null !== $problem) {
        $errors["columns.{$map['column']}.create_missing"][] = $problem;
        continue;
      }
      $created[$map['column']] = ['entity' => $target->name, 'slug' => $target->slug, 'new' => false, 'count' => count($missing), 'values' => array_slice(array_values($missing), 0, 10)];
      foreach ($missing as $key => $raw) {
        $id = $write ? $this->createReferenced($target, $match, $raw, $map['column'], $errors) : 'new';
        if (null === $id) {
          continue;
        }
        foreach ($converted as $otherRaw => $value) {
          if (self::lookupKey($match, $value) === $key) {
            $result[$map['column']][(string)$otherRaw] = $id;
          }
        }
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    return $result;
  }

  /**
   * Record ids by value of the match field - in every language of the field, upper/lower case
   * does not matter (ids do).
   *
   * @param list<string> $values
   * @return array<string, string> lookupKey(value) => id
   */
  private function lookup(EntityDefinition $target, string $match, array $values): array
  {
    $columns = [$match];
    $field = 'id' === $match ? null : $target->field($match);
    if (null !== $field && $field->translatable) {
      foreach ($target->otherLanguages() as $language) {
        $columns[] = $field->translationColumn($language);
      }
    }
    $result = [];
    foreach ($columns as $column) {
      foreach ($this->records->idsByValues($target, $column, $values) as $value => $id) {
        $result[self::lookupKey($match, (string)$value)] ??= $id;
      }
    }
    return $result;
  }

  private static function lookupKey(string $match, string $value): string
  {
    return 'id' === $match ? $value : mb_strtolower(trim($value));
  }

  /**
   * Why missing values cannot be added to the target - null if they can.
   */
  private function cannotCreate(EntityDefinition $target, string $match): ?string
  {
    if ('id' === $match) {
      return I18n::t('Records can only be added when the column is matched by a field, not by the id.');
    }
    if (!$this->currentUser->can(EntityPermission::Create, $target)) {
      return I18n::t('You may not create records in "{entity}".', ['entity' => $target->name]);
    }
    $blocking = array_filter($target->fields, static fn(FieldDefinition $f): bool => $f->required
      && $f->name !== $match
      && !in_array($f->type, [FieldType::AutoIncrement, FieldType::Uuid, FieldType::Order], true)
      && !(FieldType::Slug === $f->type && null !== $f->slugSource));
    if ([] !== $blocking) {
      return I18n::t('"{entity}" has more required fields ({fields}) - missing records cannot be added.', ['entity' => $target->name, 'fields' => implode(', ', array_map(static fn(FieldDefinition $f): string => $f->label, $blocking))]);
    }
    return null;
  }

  /**
   * @param array<string, string[]> $errors
   */
  private function createReferenced(EntityDefinition $target, string $match, string $value, string $column, array &$errors): ?string
  {
    try {
      return (string)($this->recordService ?? throw new \LogicException('RecordService missing'))->create($target, [$match => $value])['id'];
    } catch (ValidationException $e) {
      $errors["columns.{$column}.create_missing"][] = I18n::quote(mb_strimwidth($value, 0, 40, '…')).': '.implode(' ', array_merge(...array_values($e->getErrors())));
      return null;
    }
  }

  /**
   * Reference columns with "new_reference": the entity is checked (preview) or created with one
   * record per different value (run). Until it exists, the column is read as text.
   *
   * @param array<string, int> $headers
   * @param array<string, array> $info out: column => entity, count and some of the values
   * @param list<string> $createdIds out: ids of the created entities (deleted again if the import fails)
   */
  private function newReferences(array $plan, array $headers, array $rows, bool $write, array &$info, array &$createdIds): array
  {
    $errors = [];
    $access = 'existing' === ($plan['mode'] ?? 'new')
      ? ($this->entityRepository->findBySlug((string)($plan['target'] ?? ''))?->access->value ?? 'public')
      : (string)($plan['entity']['access'] ?? 'public');
    foreach ((array)($plan['columns'] ?? []) as $index => $data) {
      if (!is_array($data) || FieldType::Reference->value !== ($data['type'] ?? null) || !is_array($data['new_reference'] ?? null) || '' === trim((string)($data['field'] ?? ''))) {
        continue;
      }
      $column = (string)($data['column'] ?? '');
      if (!isset($headers[$column])) {
        continue;
      }
      if (!$this->currentUser->isAdmin()) {
        $errors["columns.{$column}.new_reference"][] = I18n::t('Only administrators may add new entities.');
        continue;
      }
      $entityData = [
        'slug' => trim((string)($data['new_reference']['slug'] ?? '')),
        'name' => trim((string)($data['new_reference']['name'] ?? '')),
        'access' => $access,
        'label_field' => 'name',
        'fields' => [['name' => 'name', 'label' => I18n::t('Name'), 'type' => FieldType::String->value, 'length' => 255, 'required' => true, 'unique' => true]],
      ];
      try {
        $this->schema->prepareEntity($entityData);
      } catch (ValidationException $e) {
        foreach ($e->getErrors() as $field => $messages) {
          $errors["columns.{$column}.new_reference.{$field}"] = $messages;
        }
        continue;
      }

      // The different values, the first spelling wins ("Krimi" and "krimi" are one)
      $repeatable = (bool)filter_var($data['repeatable'] ?? false, FILTER_VALIDATE_BOOL);
      $values = [];
      foreach (array_column($rows, $headers[$column]) as $cell) {
        foreach (null === $cell ? [] : ($repeatable ? self::splitList($cell) : [trim($cell)]) as $value) {
          if ('' !== $value) {
            $values[mb_strtolower($value)] ??= $value;
          }
        }
      }
      $long = array_filter($values, static fn(string $value): bool => mb_strlen($value) > 255);
      if ([] !== $long) {
        $errors["columns.{$column}.new_reference"][] = I18n::t('Values longer than 255 characters cannot become records: {value}', ['value' => I18n::quote(mb_strimwidth((string)reset($long), 0, 40, '…'))]);
        continue;
      }
      $info[$column] = ['entity' => $entityData['name'], 'slug' => $entityData['slug'], 'new' => true, 'count' => count($values), 'values' => array_slice(array_values($values), 0, 10)];

      if ($write) {
        $created = $this->schema->createEntity($entityData);
        $createdIds[] = $created->id;
        $this->records->insertBatch($created, array_map(static fn(string $value): array => ['name' => $value], array_values($values)));
        $plan['columns'][$index] = ['reference' => $created->slug, 'match' => 'name'] + array_diff_key($data, ['new_reference' => 1, 'create_missing' => 1]);
      } else {
        // Preview: the entity does not exist yet - the column is checked as text
        $plan['columns'][$index] = ['type' => FieldType::String->value, 'length' => 255, 'reference' => null, 'match' => null] + array_diff_key($data, ['new_reference' => 1, 'create_missing' => 1, 'type' => 1, 'reference' => 1, 'match' => 1, 'length' => 1]);
      }
    }
    if ([] !== $errors) {
      foreach ($createdIds as $id) {
        $this->schema->deleteEntity($id);
      }
      throw new ValidationException($errors);
    }
    return $plan;
  }

  /**
   * Converts and checks every row. Returns the report and the rows to write.
   */
  private function checkRows(EntityDefinition $entity, array $mapping, array $newFields, array $references, array $rows, ?string $key, bool $write): array
  {
    $fieldsByName = [];
    foreach ($mapping as $map) {
      $fieldsByName[$map['name']] = $map['field'];
    }
    // Required fields nobody maps: new records would always be incomplete
    // (UUID fields are filled automatically)
    $unmappedRequired = array_filter($entity->fields, static fn(FieldDefinition $f): bool => $f->required && FieldType::Uuid !== $f->type && !isset($fieldsByName[$f->name]));

    // A new entity (not created yet) has no records to compare with
    $isNew = null === $entity->createdAt;
    $keyIds = [];
    $trashed = [];
    $uniqueExisting = [];
    if (!$isNew) {
      $keyIds = null !== $key ? $this->existingIds($entity, $fieldsByName[$key], $mapping, $rows, $key) : [];
      $trashed = array_flip($this->records->trashedIds($entity, array_values($keyIds)));
      foreach ($mapping as $map) {
        if ($map['field']->unique && $map['name'] !== $key && null !== $entity->field($map['name'])) {
          $uniqueExisting[$map['name']] = $this->existingIds($entity, $map['field'], $mapping, $rows, $map['name']);
        }
      }
    }

    // Slugs (except the key) are made unique like in the admin app: existing ones once, then the file's
    $slugFields = array_filter($entity->fields, static fn(FieldDefinition $f): bool => FieldType::Slug === $f->type && $f->name !== $key);
    $slugOwners = [];
    foreach ($slugFields as $field) {
      $slugOwners[$field->name] = $isNew || null === $entity->field($field->name) ? [] : $this->records->valueIds($entity, $field->name);
    }

    $display = $entity->displayField();
    $summary = ['create' => 0, 'update' => 0, 'error' => 0];
    $report = [];
    $errorRows = [];
    $insert = [];
    $update = [];
    $seen = [];

    foreach ($rows as $index => $row) {
      $line = $index + 2;
      $values = [];
      $shown = [];
      $messages = [];

      foreach ($mapping as $map) {
        /** @var FieldDefinition $field */
        $field = $map['field'];
        $raw = $row[$map['index']] ?? null;
        if (FieldType::Reference === $field->type && $field->repeatable) {
          $shown[$field->name] = $raw;
          $ids = [];
          foreach (null !== $raw ? self::splitList($raw) : [] as $item) {
            isset($references[$map['column']][$item])
              ? $ids[] = $references[$map['column']][$item]
              : $messages[] = I18n::t('{field}: "{value}" was not found in "{entity}".', ['field' => $field->label, 'value' => mb_strimwidth($item, 0, 40, '…'), 'entity' => $field->referenceEntity]);
          }
          $values[$field->name] = $this->repeatValue($field, array_values(array_unique($ids)), $messages);
          continue;
        }
        if ($field->repeatable) {
          $items = [];
          foreach (null !== $raw ? self::splitList($raw) : [] as $item) {
            try {
              $items[] = (string)$this->variables->toStorage($field, $item);
            } catch (InvalidValueException $e) {
              $messages[] = $field->label.': '.I18n::quote(mb_strimwidth($item, 0, 40, '…')).': '.$e->getMessage();
            }
          }
          $values[$field->name] = $this->repeatValue($field, $items, $messages);
          continue;
        }
        if (FieldType::Reference === $field->type) {
          $shown[$field->name] = $raw;
          if (null === $raw) {
            $values[$field->name] = null;
          } elseif (isset($references[$map['column']][$raw])) {
            $values[$field->name] = $references[$map['column']][$raw];
          } else {
            $messages[] = I18n::t('{field}: "{value}" was not found in "{entity}".', ['field' => $field->label, 'value' => mb_strimwidth($raw, 0, 40, '…'), 'entity' => $field->referenceEntity]);
          }
          continue;
        }
        if (FieldType::Boolean === $field->type && null !== ($map['boolean'] ?? null)) {
          $values[$field->name] = self::mapBoolean($map['boolean'], $field, $raw, $messages);
          continue;
        }
        try {
          $values[$field->name] = $this->variables->toStorage($field, $raw);
        } catch (InvalidValueException $e) {
          $messages[] = sprintf('%s: %s', $field->label, $e->getMessage());
        }
      }

      $existingId = null;
      if (null !== $key && array_key_exists($key, $values)) {
        $keyValue = $values[$key];
        if (null === $keyValue) {
          $messages[] = I18n::t('{field} is missing - it identifies the record.', ['field' => $fieldsByName[$key]->label]);
        } elseif (isset($seen['__key'][(string)$keyValue])) {
          $messages[] = I18n::t('{field}: "{value}" is in the file more than once (row {row}).', ['field' => $fieldsByName[$key]->label, 'value' => mb_strimwidth((string)$keyValue, 0, 40, '…'), 'row' => $seen['__key'][(string)$keyValue]]);
        } elseif (isset($keyIds[(string)$keyValue], $trashed[$keyIds[(string)$keyValue]])) {
          $messages[] = I18n::t('{field}: "{value}" is in the trash - please restore it or delete it for good.', ['field' => $fieldsByName[$key]->label, 'value' => mb_strimwidth((string)$keyValue, 0, 40, '…')]);
        } else {
          $existingId = $keyIds[(string)$keyValue] ?? null;
        }
      }
      $action = null !== $existingId ? 'update' : 'create';

      foreach ($slugFields as $field) {
        $length = $field->length ?? FieldType::DEFAULT_LENGTH;
        $slug = $values[$field->name] ?? null;
        $fromSource = null === $slug && null !== $field->slugSource && ('create' === $action || array_key_exists($field->name, $values));
        if ($fromSource && is_scalar($values[$field->slugSource] ?? null)) {
          $slug = Slug::make((string)$values[$field->slugSource], $length) ?: null;
        }
        if (null === $slug) {
          continue;
        }
        $owners = $slugOwners[$field->name];
        $slug = Slug::unique((string)$slug, static fn(string $candidate): bool => isset($owners[$candidate]) && $owners[$candidate] !== $existingId, $length);
        $values[$field->name] = $slug;
        $slugOwners[$field->name][$slug] = $existingId ?? '__row'.$line;
      }

      foreach ($entity->fields as $field) {
        if (null !== ($values[$field->name] ?? null)) {
          continue;
        }
        if ('create' === $action && null !== ($generated = $field->generateValue())) {
          $values[$field->name] = $generated;
        } elseif ('update' === $action && $field->type->isGenerated()) {
          // An empty cell keeps the number; new records get theirs from the database (NULL = next value)
          unset($values[$field->name]);
        }
      }

      foreach ($mapping as $map) {
        $field = $map['field'];
        if ($field->required && array_key_exists($field->name, $values) && null === $values[$field->name]) {
          $messages[] = I18n::t('{field}: required field is empty.', ['field' => $field->label]);
        }
      }
      if ('create' === $action) {
        foreach ($unmappedRequired as $field) {
          $messages[] = I18n::t('{field}: required field is not mapped to a column.', ['field' => $field->label]);
        }
      }

      foreach ($mapping as $map) {
        $field = $map['field'];
        $value = $values[$field->name] ?? null;
        // The key is checked above: same key = same record; slugs were made unique
        if (!$field->unique || null === $value || $field->name === $key || FieldType::Slug === $field->type) {
          continue;
        }
        $value = (string)$value;
        if (isset($seen[$field->name][$value])) {
          $messages[] = I18n::t('{field}: "{value}" is in the file more than once (row {row}).', ['field' => $field->label, 'value' => mb_strimwidth($value, 0, 40, '…'), 'row' => $seen[$field->name][$value]]);
        } elseif (isset($uniqueExisting[$field->name][$value]) && $uniqueExisting[$field->name][$value] !== $existingId) {
          $messages[] = I18n::t('{field}: "{value}" exists already.', ['field' => $field->label, 'value' => mb_strimwidth($value, 0, 40, '…')]);
        }
      }

      foreach ($entity->uniqueTogether as $set) {
        if ([] !== array_diff($set, array_keys($values))) {
          continue;
        }
        $combination = array_map(static fn(string $n): ?string => null !== $values[$n] ? (string)$values[$n] : null, $set);
        if (in_array(null, $combination, true)) {
          continue;
        }
        $comboKey = implode("\x1F", $combination);
        $labels = implode(' + ', array_map(static fn(string $n): string => $fieldsByName[$n]->label ?? $n, $set));
        if (isset($seen['__set:'.implode(',', $set)][$comboKey])) {
          $messages[] = I18n::t('The combination {fields} is in the file more than once.', ['fields' => $labels]);
        } elseif (!$isNew && $this->records->exists($entity, array_combine($set, $combination), $existingId, withTrashed: true)) {
          $messages[] = I18n::t('The combination {fields} exists already.', ['fields' => $labels]);
        }
        $seen['__set:'.implode(',', $set)][$comboKey] = $line;
      }

      if ([] === $messages) {
        foreach ($mapping as $map) {
          $value = $values[$map['name']] ?? null;
          if ($map['field']->unique && null !== $value) {
            $seen[$map['name']][(string)$value] ??= $line;
          }
        }
        if (null !== $key && null !== ($values[$key] ?? null)) {
          $seen['__key'][(string)$values[$key]] = $line;
        }
      }

      $label = null !== $display && null !== ($values[$display] ?? null) ? (string)$values[$display] : (string)($row[$mapping[0]['index']] ?? '');
      $entry = ['line' => $line, 'action' => [] === $messages ? $action : 'error', 'label' => mb_strimwidth($label, 0, 80, '…'), 'message' => [] === $messages ? null : implode(' · ', $messages)];
      $summary[$entry['action']]++;

      if ([] !== $messages) {
        if (count($errorRows) < self::MAX_REPORTED_ERRORS) {
          $errorRows[] = $entry;
        }
      } elseif ($write) {
        'update' === $action ? $update[(string)$existingId] = $values : $insert[] = $values;
      }
      if (!$write && count($report) < self::PREVIEW_ROWS) {
        $report[] = $entry + ['values' => array_map(static fn($v) => is_bool($v) ? ($v ? I18n::t('Yes') : I18n::t('No')) : $v, $shown + $values)];
      }
    }

    return [
      'summary' => $summary,
      'rows' => $report,
      'errors' => $errorRows,
      'fields' => array_map(static fn(array $map): array => ['column' => $map['column'], 'name' => $map['name'], 'label' => $map['field']->label, 'type' => $map['field']->type->value], $mapping),
      'new_fields' => array_column($newFields, 'name'),
      'insert' => $insert,
      'update' => $update,
    ];
  }

  /** At most this many different values of a column are listed in the analysis */
  private const VALUE_LIST = 20;

  /**
   * Suggestion for a yes/no column: the values that read as yes and as no.
   *
   * @param list<string> $values different values of the column
   * @return array{true_values: list<string>, false_values: list<string>, empty_false: bool}
   */
  private static function booleanSuggestion(array $values): array
  {
    $true = $false = [];
    foreach ($values as $value) {
      try {
        ValueConverter::convert(FieldType::Boolean, $value) ? $true[] = $value : $false[] = $value;
      } catch (InvalidValueException) {
      }
    }
    return ['true_values' => $true, 'false_values' => $false, 'empty_false' => false];
  }

  /**
   * What the plan says about a yes/no column: values for yes and no (not case-sensitive) and
   * whether an empty cell means no. Null = the usual words (ja/nein, 1/0, true/false ...).
   *
   * @return array{true: list<string>, false: list<string>, empty: bool}|null
   */
  private static function booleanMap(array $data): ?array
  {
    if (!array_key_exists('true_values', $data) && !array_key_exists('false_values', $data) && !array_key_exists('empty_false', $data)) {
      return null;
    }
    $list = static fn(mixed $value): array => array_values(array_unique(array_filter(array_map(
      static fn($v): string => mb_strtolower(trim((string)$v)),
      is_array($value) ? $value : explode(',', (string)$value)
    ), static fn(string $v): bool => '' !== $v)));
    return [
      'true' => $list($data['true_values'] ?? []),
      'false' => $list($data['false_values'] ?? []),
      'empty' => (bool)filter_var($data['empty_false'] ?? false, FILTER_VALIDATE_BOOL),
    ];
  }

  /**
   * @param array{true: list<string>, false: list<string>, empty: bool} $map
   * @param list<string> $messages
   */
  private static function mapBoolean(array $map, FieldDefinition $field, ?string $raw, array &$messages): ?bool
  {
    $value = mb_strtolower(trim((string)$raw));
    if ('' === $value) {
      return $map['empty'] ? false : null;
    }
    if (in_array($value, $map['true'], true)) {
      return true;
    }
    if (in_array($value, $map['false'], true)) {
      return false;
    }
    if ([] === $map['true'] && [] === $map['false']) {
      try {
        return (bool)ValueConverter::toStorage($field, $raw);
      } catch (InvalidValueException $e) {
        $messages[] = sprintf('%s: %s', $field->label, $e->getMessage());
        return null;
      }
    }
    $messages[] = I18n::t('{field}: "{value}" is neither yes ({yes}) nor no ({no}).', [
      'field' => $field->label,
      'value' => mb_strimwidth((string)$raw, 0, 40, '…'),
      'yes' => implode(', ', $map['true']) ?: '–',
      'no' => implode(', ', $map['false']) ?: '–',
    ]);
    return null;
  }

  /**
   * Repeatable fields in a file: values separated by "|" ("rot | grün | blau").
   *
   * @return list<string>
   */
  private static function splitList(string $cell): array
  {
    return array_values(array_filter(array_map('trim', explode('|', $cell)), static fn(string $item): bool => '' !== $item));
  }

  /**
   * @param list<string> $items
   * @param list<string> $messages
   */
  private function repeatValue(FieldDefinition $field, array $items, array &$messages): ?string
  {
    $count = count($items);
    if (null !== $field->repeatMax && $count > $field->repeatMax) {
      $messages[] = I18n::t('{field}: at most {max} values allowed (there are {count}).', ['field' => $field->label, 'max' => $field->repeatMax, 'count' => $count]);
    } elseif (null !== $field->repeatMin && $count > 0 && $count < $field->repeatMin) {
      $messages[] = I18n::t('{field}: at least {min} values needed.', ['field' => $field->label, 'min' => $field->repeatMin]);
    }
    return [] !== $items ? json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
  }

  /**
   * Ids of existing records by the (converted) values of a column in the file.
   *
   * @return array<string, string> value => id
   */
  private function existingIds(EntityDefinition $entity, FieldDefinition $field, array $mapping, array $rows, string $name): array
  {
    if (null === $entity->field($name)) {
      return [];
    }
    $index = null;
    foreach ($mapping as $map) {
      if ($map['name'] === $name) {
        $index = $map['index'];
      }
    }
    $values = [];
    foreach (array_column($rows, $index) as $raw) {
      try {
        $value = FieldType::Reference === $field->type ? $raw : ValueConverter::toStorage($field, $raw);
        if (null !== $value) {
          $values[] = (string)$value;
        }
      } catch (InvalidValueException) {
      }
    }
    // Records in the trash still hold their key and unique values
    return $this->records->idsByValues($entity, $name, $values, withTrashed: true);
  }

  /**
   * @param list<array> $newFields
   * @param list<array> $insert
   * @param array<string, array> $update
   */
  private function write(EntityDefinition $entity, string $mode, array $entityData, array $newFields, array $insert, array $update): string
  {
    $created = false;
    if ('existing' !== $mode) {
      $entity = $this->schema->createEntity($entityData);
      $created = true;
    } else {
      foreach ($newFields as $data) {
        $this->schema->addField($entity->id, $data);
      }
      $entity = $this->schema->get($entity->id);
    }

    try {
      $this->db->transaction(function () use ($entity, $insert, $update): void {
        // insertBatch needs the same columns in every row
        $columns = array_unique(array_merge(...array_map('array_keys', $insert ?: [[]])));
        $rows = array_map(static fn(array $row): array => array_merge(array_fill_keys($columns, null), $row), $insert);
        $this->records->insertBatch($entity, $rows);
        foreach ($update as $id => $values) {
          $this->records->update($entity, $id, $values);
        }
        // Rows of the same file can close a circle together - then nothing is imported
        if ([] !== $update) {
          $this->tree->assertNoCycles($entity);
        }
      });
    } catch (Throwable $e) {
      if ($created) {
        $this->schema->deleteEntity($entity->id);
      }
      throw $e;
    }
    return $entity->slug;
  }

  /**
   * Existing entities whose fields match the file's columns (by name or label).
   *
   * @param list<string> $columns
   * @return list<array{entity: string, name: string, columns: array<string, ?string>, key: ?string, score: float}>
   */
  private function matchingEntities(array $columns): array
  {
    $result = [];
    foreach ($this->entityRepository->all() as $entity) {
      if (!$this->currentUser->can(EntityPermission::Import, $entity)) {
        continue;
      }
      $mapped = [];
      $count = 0;
      foreach ($columns as $column) {
        $mapped[$column] = null;
        foreach ($entity->fields as $field) {
          if (FieldType::Media === $field->type || FieldType::Group === $field->type) {
            continue;
          }
          if ($field->name === Naming::snake($column) || mb_strtolower($field->label) === mb_strtolower(trim($column))) {
            $mapped[$column] = $field->name;
            $count++;
            break;
          }
        }
      }
      if (0 === $count) {
        continue;
      }
      $key = null;
      foreach ($mapped as $name) {
        if (null !== $name && $entity->field($name)?->unique) {
          $key = $name;
          break;
        }
      }
      $result[] = ['entity' => $entity->slug, 'name' => $entity->name, 'columns' => $mapped, 'key' => $key, 'score' => round($count / max(1, count($columns)), 2)];
    }
    usort($result, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return $result;
  }

  /**
   * Does the column hold values of another entity (its id, a unique field or the display field)?
   * Plain numbers match almost any number field by chance ("2" -> country no. 2), so numeric
   * columns are only checked against entities their name points to (country_id -> countries).
   *
   * @param list<string|null> $values
   * @param list<string> $excluded entity slugs
   * @return array{entity: string, match: string}|null
   */
  /**
   * Entities whose records the values of a column name (at least half of a sample), best first:
   * the best match field per entity, at most 3.
   *
   * @return list<array{entity: string, name: string, match: string, match_label: string, found: int, total: int}>
   */
  private function referenceCandidates(string $name, array $values, array $excluded): array
  {
    $sample = array_slice(array_values(array_unique(array_filter($values, static fn($v): bool => null !== $v && '' !== trim($v)))), 0, self::REFERENCE_SAMPLE);
    if ([] === $sample) {
      return [];
    }
    $numeric = [] === array_filter($sample, static fn(string $v): bool => !is_numeric(trim($v)));
    $base = (string)preg_replace('/_(id|uuid|key|code|nr|no)$/', '', $name);
    $result = [];
    foreach ($this->entityRepository->all() as $target) {
      if (in_array($target->slug, $excluded, true)) {
        continue;
      }
      $singular = (string)preg_replace('/(ies|s)$/', '', $target->slug);
      $named = '' !== $base && ($base === $target->slug || $base === $singular || str_starts_with($target->slug, $base) || str_starts_with($base, $singular));
      if ($numeric && !$named) {
        continue;
      }
      $candidates = [];
      if ([] === array_filter($sample, static fn(string $v): bool => !Id::isValid(trim($v)))) {
        $candidates[] = 'id';
      }
      foreach ($target->fields as $field) {
        if (($field->unique || $field->name === $target->displayField()) && FieldType::Reference !== $field->type && FieldType::Boolean !== $field->type) {
          $candidates[] = $field->name;
        }
      }
      $best = null;
      foreach ($candidates as $match) {
        // Same name as the target field ("name" -> countries.name): rather the same data than a reference
        if ($match === $name) {
          continue;
        }
        $found = $this->lookup($target, $match, array_map('trim', $sample));
        $hits = count(array_filter($sample, static fn(string $v): bool => isset($found[self::lookupKey($match, $v)])));
        if ($hits >= 0.5 * count($sample) && $hits > ($best['found'] ?? 0)) {
          $best = ['entity' => $target->slug, 'name' => $target->name, 'match' => $match, 'match_label' => 'id' === $match ? 'ID' : (string)$target->field($match)?->label, 'found' => $hits, 'total' => count($sample)];
        }
      }
      if (null !== $best) {
        $result[] = $best;
      }
    }
    usort($result, static fn(array $a, array $b): int => $b['found'] <=> $a['found']);
    return array_slice($result, 0, 3);
  }

  /**
   * @param array<string, true> $used
   */
  private function fieldName(string $column, int $index, array &$used): string
  {
    $name = Naming::snake($column);
    if ('' === $name) {
      $name = 'spalte_'.($index + 1);
    }
    if (in_array($name, FieldDefinition::RESERVED, true)) {
      // e.g. an "Id" column of the source system: kept as its own field
      $name = 'id' === $name ? 'source_id' : $name.'_value';
    }
    $unique = $name;
    for ($n = 2; isset($used[$unique]); $n++) {
      $unique = substr($name, 0, 60).'_'.$n;
    }
    $used[$unique] = true;
    return $unique;
  }

  private static function labelOf(string $column): string
  {
    $label = trim($column);
    // Technical headers (snake_case, camelCase) get a readable label
    if (preg_match('/^[A-Za-z0-9_]+$/', $label)) {
      $label = Naming::label(Naming::snake($label));
    }
    return mb_substr($label, 0, 100);
  }

  private function suggestSlug(string $fileName): string
  {
    $slug = Naming::snake(pathinfo($fileName, PATHINFO_FILENAME), 40);
    if ('' === $slug) {
      $slug = 'import';
    }
    $candidate = $slug;
    for ($n = 2; null !== $this->entityRepository->findBySlug($candidate); $n++) {
      $candidate = substr($slug, 0, 36).'_'.$n;
    }
    return $candidate;
  }

  private function suggestLabelField(array $analysis): ?string
  {
    foreach ($analysis as $column) {
      if (in_array($column['suggestion']['field'], self::LABEL_NAMES, true)) {
        return $column['suggestion']['field'];
      }
    }
    foreach ($analysis as $column) {
      if (in_array($column['suggestion']['type'], ['string', 'email'], true) && !$column['suggestion']['unique']) {
        return $column['suggestion']['field'];
      }
    }
    return $analysis[0]['suggestion']['field'] ?? null;
  }

  /**
   * @return array{columns: list<string>, rows: list<list<string|null>>, sheet: ?string, delimiter: ?string}
   */
  private function read(string $importId, array $meta, array $options): array
  {
    $sheet = isset($options['sheet']) && '' !== (string)$options['sheet'] ? (string)$options['sheet'] : null;
    $delimiter = isset($options['delimiter']) && '' !== (string)$options['delimiter'] ? (string)$options['delimiter'] : null;
    if ('\t' === $delimiter || 'tab' === $delimiter) {
      $delimiter = "\t";
    }
    return $this->reader->read($this->sourcePath($importId, $meta), (string)$meta['extension'], $sheet, $delimiter, $this->maxRows);
  }

  private function meta(string $importId): array
  {
    $file = $this->directory($importId).'/meta.json';
    $meta = Id::isValid($importId) && is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($meta) || (int)$meta['created_at'] < time() - self::EXPIRES_AFTER) {
      throw new UserFacingException(I18n::t('The uploaded file has expired. Please upload it again.'), 410, 'import_expired');
    }
    if ($meta['user_id'] !== $this->currentUser->getId()) {
      throw UserFacingException::forbidden(I18n::t('Someone else started this import.'));
    }
    return $meta;
  }

  private function sourcePath(string $importId, array $meta): string
  {
    return $this->directory($importId).'/source.'.$meta['extension'];
  }

  private function directory(string $importId): string
  {
    return $this->storagePath.'/'.$importId;
  }

  private function assertMayImport(): void
  {
    if ($this->currentUser->isAdmin()) {
      return;
    }
    foreach ($this->entityRepository->all() as $entity) {
      if ($this->currentUser->can(EntityPermission::Import, $entity)) {
        return;
      }
    }
    throw UserFacingException::forbidden(I18n::t('You may not import data.'));
  }
}
