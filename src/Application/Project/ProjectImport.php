<?php

declare(strict_types=1);

namespace App\Application\Project;

use App\Application\Content\RecordService;
use App\Application\Media\MediaLibrary;
use App\Application\Media\MediaService;
use App\Application\Schema\GroupService;
use App\Application\Schema\PluginSetup;
use App\Application\Schema\SchemaService;
use App\Application\Service\CurrentProject;
use App\Domain\Schema\EntityDefinition;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * A project from an export folder (e.g. of another installation, read through the API):
 *
 *   project.json     slug, name, table_prefix, languages (the first is the default)
 *   variables.json   [{name, value, translatable, translations}]
 *   groups.json      field groups and blocks as GET /admin/groups delivers them
 *   entities.json    entities with their fields as GET /admin/entities delivers them
 *   records/<entity>.json  records as GET /entities/{entity}/records/{id} delivers them (with _i18n)
 *   media.json       {old id: {file, name, mime_type, focal_point}} - the files next to it
 *
 * Everything gets new ids: files and records referenced by their old ids (media fields, references, also in
 * groups and translations) are pointed to the new ones. The project must not exist yet (or be empty).
 */
final class ProjectImport
{
  /** Old id → new id (files and records) */
  private array $ids = [];

  public function __construct(
    private ProjectRepository $projects,
    private ProjectService $projectService,
    private CurrentProject $currentProject,
    private EntityRepository $entities,
    private SchemaService $schema,
    private GroupService $groups,
    private RecordService $records,
    private MediaService $media,
    private MediaLibrary $library,
    private ?PluginSetup $blocks = null,
  ) {
  }

  /**
   * @return iterable<string> what happened, step by step
   */
  public function import(string $directory, ?string $slug = null): iterable
  {
    $directory = rtrim($directory, '/');
    $project = self::json($directory.'/project.json');
    $slug ??= (string)$project['slug'];
    $existing = $this->projects->find($slug);
    if (null !== $existing && $this->projects->countEntities($existing->id) > 0) {
      throw new RuntimeException(sprintf('The project "%s" has entities already - import into a new (or empty) one.', $slug));
    }
    $languages = array_values(array_unique(array_merge(array_filter([$project['default_language'] ?? null]), (array)($project['languages'] ?? []))));
    $target = $existing ?? $this->projectService->create(['name' => (string)$project['name'], 'slug' => $slug, 'table_prefix' => (string)($project['table_prefix'] ?? $slug.'_'), 'languages' => $languages]);
    $this->blocks?->ensure($target->id);
    $previous = $this->currentProject->find();
    $this->currentProject->set($this->projects->find($target->id));
    $this->entities->reset();
    try {
      yield sprintf('Project %s (%s), languages %s', $slug, $target->tablePrefix, implode(', ', $languages));

      $variables = is_file($directory.'/variables.json') ? self::json($directory.'/variables.json') : ($project['variables'] ?? []);
      $this->projectService->updateVariables($target->id, array_map(static fn(array $v): array => array_intersect_key($v, array_flip(['name', 'value', 'translatable', 'translations'])), $variables));
      yield sprintf('  %d variables', count($variables));

      $groups = is_file($directory.'/groups.json') ? self::json($directory.'/groups.json') : [];
      $created = $this->createInPasses($groups, fn(array $group) => $this->groups->create([
        'name' => $group['name'], 'label' => $group['label'] ?? $group['name'], 'kind' => $group['kind'] ?? 'group', 'category' => $group['category'] ?? null,
        'description' => $group['description'] ?? null, 'template' => $group['template'] ?? null, 'fields' => array_map(self::field(...), $group['fields'] ?? []),
      ]), fn(array $group) => null !== $this->entities->findGroup((string)$group['name']));
      yield sprintf('  %d field groups and blocks', $created);

      // Entities: those they point to first; a reference to itself (trees) is added afterwards
      $entities = self::json($directory.'/entities.json');
      $order = self::byReferences($entities);
      foreach ($order as $entity) {
        $own = array_filter($entity['fields'], static fn(array $f): bool => ($f['reference'] ?? null) === $entity['slug']);
        $definition = array_intersect_key($entity, array_flip(['slug', 'name', 'description', 'access', 'label_field', 'unique_together', 'trash', 'drafts', 'revisions', 'preview_url', 'form_tabs']));
        $definition['fields'] = array_values(array_map(self::field(...), array_filter($entity['fields'], static fn(array $f): bool => ($f['reference'] ?? null) !== $entity['slug'])));
        $made = $this->schema->createEntity($definition);
        foreach ($own as $field) {
          $this->schema->addField($made->id, self::field($field));
        }
        if (null !== ($entity['tree_field'] ?? null) || [] !== $own) {
          $this->schema->updateEntity($made->id, array_filter(['tree_field' => $entity['tree_field'] ?? null]));
        }
        $this->entities->reset();
      }
      yield sprintf('  %d entities: %s', count($order), implode(', ', array_column($order, 'slug')));

      // Files
      $media = is_file($directory.'/media.json') ? self::json($directory.'/media.json') : [];
      foreach ($media as $oldId => $file) {
        $path = $directory.'/'.$file['file'];
        if (!is_file($path)) {
          yield sprintf('  ! file %s (%s) is missing', $oldId, $file['name']);
          continue;
        }
        $content = (string)file_get_contents($path);
        $upload = new UploadedFile((new StreamFactory())->createStream($content), strlen($content), UPLOAD_ERR_OK, (string)$file['name'], (string)$file['mime_type']);
        $new = $this->media->upload($upload, null, true);
        $this->ids[(string)$oldId] = (string)$new['id'];
        if (is_array($file['focal_point'] ?? null)) {
          $this->library->update((string)$new['id'], ['focal_point' => $file['focal_point']]);
        }
      }
      yield sprintf('  %d files', count($media));

      // Records: in the order of the entities; references to records not there yet get them in a second pass
      $later = [];
      foreach ($order as $entity) {
        $definition = $this->entities->findBySlug((string)$entity['slug']) ?? throw new RuntimeException('Entity '.$entity['slug'].' is missing.');
        $file = $directory.'/records/'.$entity['slug'].'.json';
        $rows = is_file($file) ? self::json($file) : [];
        foreach ($rows as $row) {
          [$data, $open] = $this->values($definition, $row);
          $new = $this->records->create($definition, $data);
          $this->ids[(string)$row['id']] = (string)$new['id'];
          if ([] !== $open) {
            $later[] = [$definition, (string)$new['id'], $row];
          }
        }
        yield sprintf('  %s: %d records', $entity['slug'], count($rows));
      }
      foreach ($later as [$definition, $id, $row]) {
        $this->records->update($definition, $id, $this->values($definition, $row)[0]);
      }
    } finally {
      $this->currentProject->set($previous);
      $this->entities->reset();
    }
  }

  /**
   * The values of a record for RecordService (old ids → new ones) - and the fields still pointing to records
   * not imported yet.
   *
   * @return array{0: array<string, mixed>, 1: list<string>}
   */
  private function values(EntityDefinition $entity, array $row): array
  {
    $data = [];
    $open = [];
    foreach ($entity->fields as $field) {
      if (!array_key_exists($field->name, $row) || $field->type->isGenerated()) {
        continue;
      }
      $value = $this->map($row[$field->name], $missing);
      if ($missing) {
        $open[] = $field->name;
        $value = $field->repeatable ? [] : null;
      }
      $data[$field->name] = $value;
    }
    if ($entity->drafts && array_key_exists('draft', $row)) {
      $data['draft'] = (bool)$row['draft'];
    }
    if (is_array($row['_i18n'] ?? null)) {
      $data['_i18n'] = $this->map($row['_i18n'], $ignored);
    }
    return [$data, $open];
  }

  /**
   * Old ids in a value (files, references - also inside groups) → new ids; objects of the API ({id, …})
   * become their new id. $missing: an id of a record that is not imported yet.
   */
  private function map(mixed $value, ?bool &$missing): mixed
  {
    $missing ??= false;
    if (is_array($value)) {
      if (isset($value['id']) && is_string($value['id']) && !isset($value['_type'])) {
        if (isset($this->ids[$value['id']])) {
          return $this->ids[$value['id']];
        }
        // A reference or file object of the export whose target is not there (yet)
        if (1 === preg_match('/^[1-9A-Za-z]{21,22}$/', $value['id'])) {
          $missing = true;
          return null;
        }
      }
      $result = [];
      foreach ($value as $key => $inner) {
        $result[$key] = $this->map($inner, $missing);
      }
      return $result;
    }
    return is_string($value) && isset($this->ids[$value]) ? $this->ids[$value] : $value;
  }

  /**
   * A field as the schema takes it, from the field of an export.
   */
  private static function field(array $field): array
  {
    $data = array_intersect_key($field, array_flip([
      'name', 'label', 'type', 'length', 'scale', 'uuid_version', 'media_accept', 'slug_source', 'repeatable', 'sortable', 'repeat_min', 'repeat_max',
      'translatable', 'pattern', 'pattern_message', 'options', 'read_roles', 'write_roles', 'required', 'unique', 'reference', 'searchable', 'filterable',
      'search_weight', 'min_value', 'max_value', 'slider', 'block_categories',
    ]));
    // Groups and blocks by name (ids differ)
    if (is_array($field['group'] ?? null)) {
      $data['group'] = $field['group']['name'];
    }
    if (is_array($field['blocks'] ?? null)) {
      $data['blocks'] = array_values(array_map(static fn(array $block): string => (string)$block['name'], $field['blocks']));
    }
    return array_filter($data, static fn($v): bool => null !== $v);
  }

  /**
   * Entities in an order where every reference (but to itself) points to one created before.
   *
   * @return list<array>
   */
  private static function byReferences(array $entities): array
  {
    $bySlug = array_column($entities, null, 'slug');
    $done = [];
    $visit = static function (string $slug, array $path = []) use (&$visit, &$done, $bySlug): void {
      if (isset($done[$slug]) || !isset($bySlug[$slug]) || in_array($slug, $path, true)) {
        return;
      }
      foreach ($bySlug[$slug]['fields'] as $field) {
        if (null !== ($field['reference'] ?? null) && $field['reference'] !== $slug) {
          $visit((string)$field['reference'], [...$path, $slug]);
        }
      }
      $done[$slug] = $bySlug[$slug];
    };
    foreach (array_keys($bySlug) as $slug) {
      $visit((string)$slug);
    }
    return array_values($done);
  }

  /**
   * Creates what it can, again and again while that helps (groups that use other groups).
   */
  private function createInPasses(array $items, callable $create, callable $exists): int
  {
    $created = 0;
    $open = array_values(array_filter($items, static fn(array $item): bool => !$exists($item)));
    while ([] !== $open) {
      $left = [];
      $error = null;
      foreach ($open as $item) {
        try {
          $create($item);
          $this->entities->reset();
          $created++;
        } catch (Throwable $e) {
          $left[] = $item;
          $error = $e;
        }
      }
      if (count($left) === count($open)) {
        throw new RuntimeException('Could not create: '.implode(', ', array_column($left, 'name')).' - '.$error?->getMessage(), 0, $error);
      }
      $open = $left;
    }
    return $created;
  }

  private static function json(string $file): array
  {
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : throw new RuntimeException('Cannot read '.$file);
  }
}
