<?php

declare(strict_types=1);

namespace App\Application\Docs;

use App\Application\Content\RecordService;
use App\Application\Project\ProjectService;
use App\Application\Schema\SchemaService;
use App\Application\Service\CurrentProject;
use App\Domain\Schema\EntityDefinition;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use App\Repository\RecordRepository;
use RuntimeException;

/**
 * The documentation (docs/*.md) as content: the pages of an entity (default "pages") of a project (default
 * "docs", created if missing - English only) - a tree by "parent", in the order of "order". Pages are matched
 * by their slug (the file name), so it can run after every update. The documentation is English only.
 *
 * A file: front matter, then Markdown:
 *
 *   ---
 *   title: Media
 *   summary: Uploads, storages …
 *   parent: concepts
 *   order: 110
 *   ---
 *   # Media
 *   …
 */
final class DocsSync
{
  public function __construct(
    private ProjectRepository $projects,
    private ProjectService $projectService,
    private CurrentProject $currentProject,
    private EntityRepository $entities,
    private SchemaService $schema,
    private RecordService $records,
    private RecordRepository $recordRepository,
    private ?\App\Application\Schema\PluginSetup $blocks = null,
    private ?string $directory = null,
  ) {
  }

  /**
   * @return array{created: int, updated: int, deleted: int}
   */
  public function sync(string $projectSlug = 'docs', string $entitySlug = 'pages', bool $prune = false): array
  {
    $docs = $this->read();
    $project = $this->projects->find($projectSlug);
    if (null === $project) {
      $project = $this->projectService->create(['name' => 'Documentation', 'slug' => $projectSlug, 'table_prefix' => $projectSlug.'_', 'languages' => ['en']]);
      $this->blocks?->ensure($project->id);
    }
    $previous = $this->currentProject->find();
    $this->currentProject->set($project);
    $this->entities->reset();
    try {
      $entity = $this->entities->findBySlug($entitySlug) ?? $this->createEntity($entitySlug);
      $result = ['created' => 0, 'updated' => 0, 'deleted' => 0];
      $ids = $this->existing($entity);
      // Pages in the trash come back
      $trashed = $entity->trash ? $this->recordRepository->trashedIds($entity, array_values($ids)) : [];
      if ([] !== $trashed) {
        $this->records->restore($entity, $trashed);
      }
      // Parents first: the tree needs their ids
      foreach (self::byDepth($docs) as $slug => $doc) {
        $data = [
          'title' => $doc['title'],
          'slug' => $slug,
          'summary' => $doc['summary'],
          'body' => $doc['body'],
        ];
        if (null !== $entity->field('parent')) {
          $data['parent'] = null !== $doc['parent'] ? ($ids[$doc['parent']] ?? null) : null;
        }
        if (null !== $entity->field('position')) {
          $data['position'] = $doc['order'];
        }
        if (isset($ids[$slug])) {
          $this->records->update($entity, $ids[$slug], $data);
          $result['updated']++;
        } else {
          $ids[$slug] = (string)$this->records->create($entity, $data)['id'];
          $result['created']++;
        }
      }
      if ($prune) {
        foreach (array_diff_key($ids, $docs) as $id) {
          $this->records->delete($entity, $id);
          $result['deleted']++;
        }
      }
      return $result;
    } finally {
      $this->currentProject->set($previous);
      $this->entities->reset();
    }
  }

  /**
   * The files of docs/ by their slug.
   *
   * @return array<string, array{title: string, summary: string, parent: ?string, order: int, body: string}>
   */
  public function read(): array
  {
    return self::parse($this->directory ?? dirname(__DIR__, 3).'/docs');
  }

  /**
   * @return array<string, array{title: string, summary: string, parent: ?string, order: int, body: string}>
   */
  public static function parse(string $directory): array
  {
    $docs = [];
    foreach (glob($directory.'/*.md') ?: [] as $file) {
      $content = str_replace("\r\n", "\n", (string)file_get_contents($file));
      if (1 !== preg_match('/^---\n(.*?)\n---\n(.*)$/s', $content, $m)) {
        continue;
      }
      preg_match_all('/^(\w+):\s*(.*)$/m', $m[1], $pairs, PREG_SET_ORDER);
      $meta = [];
      foreach ($pairs as [, $key, $value]) {
        $meta[$key] = trim($value);
      }
      // The heading is the title - the text starts below it
      $body = trim((string)preg_replace('/^#\s+.+\n+/', '', ltrim($m[2]), 1));
      $docs[basename($file, '.md')] = [
        'title' => $meta['title'] ?? basename($file, '.md'),
        'summary' => $meta['summary'] ?? '',
        'parent' => ($meta['parent'] ?? '') ?: null,
        'order' => (int)($meta['order'] ?? 0),
        'body' => $body,
      ];
    }
    foreach ($docs as $slug => $doc) {
      if (null !== $doc['parent'] && !isset($docs[$doc['parent']])) {
        throw new RuntimeException(sprintf('docs/%s.md: the parent "%s" does not exist.', $slug, $doc['parent']));
      }
    }
    uasort($docs, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
    return $docs;
  }

  private function createEntity(string $slug): EntityDefinition
  {
    $text = static fn(string $name, string $label, string $type, array $extra = []): array => ['name' => $name, 'label' => $label, 'type' => $type, 'translatable' => true] + $extra;
    return $this->schema->createEntity(['slug' => $slug, 'name' => 'Documentation', 'access' => 'public', 'label_field' => 'title', 'tree_field' => 'parent', 'trash' => true, 'fields' => [
      $text('title', 'Title', 'string', ['required' => true]),
      $text('slug', 'Slug', 'slug', ['slug_source' => 'title']),
      ['name' => 'parent', 'label' => 'Parent page', 'type' => 'reference', 'reference' => $slug],
      ['name' => 'position', 'label' => 'Position', 'type' => 'integer'],
      $text('summary', 'Summary', 'text'),
      $text('body', 'Text', 'markdown'),
      ...(null !== $this->entities->findGroup('seo') ? [['name' => 'seo', 'label' => 'SEO', 'type' => 'group', 'group' => 'seo']] : []),
    ]]);
  }

  /**
   * Ids of the records by slug (the trash too: a deleted page comes back instead of a second one).
   *
   * @return array<string, string>
   */
  private function existing(EntityDefinition $entity): array
  {
    $ids = [];
    foreach ($this->recordRepository->queryWithTrashed($entity)->select(['id', 'slug'])->all() as $row) {
      $ids[(string)$row['slug']] = (string)$row['id'];
    }
    return $ids;
  }

  /**
   * Parents before their children.
   *
   * @param array<string, array> $docs
   * @return array<string, array>
   */
  private static function byDepth(array $docs): array
  {
    $depth = static function (string $slug) use ($docs, &$depth): int {
      return null === $docs[$slug]['parent'] ? 0 : 1 + $depth($docs[$slug]['parent']);
    };
    $sorted = $docs;
    uksort($sorted, static fn(string $a, string $b): int => [$depth($a), $docs[$a]['order']] <=> [$depth($b), $docs[$b]['order']]);
    return $sorted;
  }
}
