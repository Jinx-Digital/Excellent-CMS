<?php

declare(strict_types=1);

namespace Fixtures;

use App\Api\Input\UserInput;
use App\Application\Access\AccessControl;
use App\Application\Import\ImportService;
use App\Application\Content\RecordService;
use App\Application\Media\MediaService;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFile;
use App\Application\Project\ProjectService;
use App\Application\Schema\GroupService;
use App\Application\Schema\SchemaService;
use App\Application\Service\CurrentProject;
use App\Application\Service\CurrentUser;
use App\Application\Service\OAuthService;
use App\Application\Service\SettingsService;
use App\Application\Service\UserService;
use App\Repository\ProjectRepository;

/**
 * Demo data to click through, two projects and the global area:
 *   Global         regions, countries and languages - shared by all projects
 *   Bibliothek     authors, genres and books, imported from resources/samples like a user would do it,
 *                  plus an editor and an API client
 *  Role "Autor"    writes books and blog posts in both projects, with the user "Autorin"
 *   Dokumentation  the documentation of Excellent CMS (docs/): pages as a tree and a blog, in English,
 *                  with the field group SEO and project variables
 */
final class DemoFixture extends Fixture
{
  public function __construct(
    UserService $users,
    ImportService $imports,
    CurrentUser $currentUser,
    CurrentProject $currentProject,
    ProjectRepository $projects,
    SchemaService $schema,
    private OAuthService $oauth,
    private SettingsService $settings,
    private ProjectService $projectService,
    private GroupService $groups,
    private RecordService $records,
    private AccessControl $access,
    private MediaService $media,
    private \App\Application\Docs\DocsSync $docs,
    private \App\Application\Schema\PluginSetup $blocks,
  ) {
    parent::__construct($users, $imports, $currentUser, $currentProject, $projects, $schema);
  }

  public function run(): iterable
  {
    $this->createAdmin();
    yield sprintf('Admin: %s / %s', self::ADMIN, self::ADMIN_PASSWORD);

    yield from $this->library();
    yield from $this->documentation();
    yield from $this->author();

    $this->settings->updateRateLimit(['enabled' => true, 'requests' => 120, 'window' => 60]);
    yield 'Rate limit: 120 requests per minute';
  }

  /**
   * Project 1: a small library - authors, genres and books, all imported; regions, countries and languages are
   * global (area "Global", shared by all projects).
   */
  private function library(): iterable
  {
    $this->projectService->update($this->currentProject->get()->id, ['name' => 'Library', 'slug' => 'bibliothek', 'table_prefix' => 'lib_', 'languages' => ['en', 'de']]);
    $this->useProject('bibliothek');
    yield 'Project Library: /api/v1/bibliothek/content, tables lib_*, languages en + de';

    // Shared by all projects: regions, countries and languages in the area "Global"
    $this->useProject('global');
    $result = $this->importCountries();
    yield sprintf('  Global: regions %d as a tree (world › continents › subregions)', $this->records->search($this->schema->get('regions'), '', [], null)->count());
    yield sprintf('  Global: countries %d (referencing their region)', $result['summary']['create']);
    $result = $this->import('languages.csv', ['slug' => 'languages', 'name' => 'Languages', 'access' => 'public', 'label_field' => 'name'], [
      'code' => ['label' => 'ISO code', 'unique' => true, 'required' => true],
      'name' => ['unique' => true, 'required' => true],
      'name_de' => ['label' => 'Name (German)'],
      'native_name' => ['label' => 'Native name'],
    ]);
    yield sprintf('  Global: languages %d', $result['summary']['create']);
    $this->useProject('bibliothek');

    $result = $this->import('authors.csv', ['slug' => 'authors', 'name' => 'Authors', 'access' => 'public', 'label_field' => 'name', 'trash' => true], [
      'name' => ['unique' => true, 'required' => true],
      'born' => ['label' => 'Born'],
      'died' => ['label' => 'Died'],
      'country' => ['type' => 'reference', 'reference' => 'countries', 'match' => 'alpha2code', 'label' => 'Country of birth'],
      'wikipedia' => ['label' => 'Wikipedia'],
    ]);
    yield sprintf('  Authors: %d', $result['summary']['create']);

    $result = $this->import('genres.csv', ['slug' => 'genres', 'name' => 'Genres', 'access' => 'public', 'label_field' => 'name'], [
      'name' => ['unique' => true, 'required' => true],
      'name_de' => ['label' => 'Name (German)'],
      'description' => ['label' => 'Description'],
    ]);
    yield sprintf('  Genres: %d', $result['summary']['create']);

    $result = $this->import('books.csv', ['slug' => 'books', 'name' => 'Books', 'access' => 'oauth', 'label_field' => 'title', 'trash' => true], [
      'Title' => ['field' => 'title', 'label' => 'Title', 'required' => true],
      'Original title' => ['field' => 'original_title', 'label' => 'Original title'],
      'Author' => ['field' => 'author', 'type' => 'reference', 'reference' => 'authors', 'match' => 'name', 'label' => 'Author', 'required' => true],
      'First published' => ['field' => 'first_published', 'label' => 'First published'],
      'Genre' => ['field' => 'genre', 'type' => 'reference', 'reference' => 'genres', 'match' => 'name'],
      'Language' => ['field' => 'language', 'type' => 'reference', 'reference' => 'languages', 'match' => 'name', 'label' => 'Language'],
      'Price' => ['field' => 'price', 'label' => 'Price'],
      'In stock' => ['field' => 'in_stock', 'label' => 'In stock'],
    ]);
    // The database numbers the books (1, 2, 3 ...)
    $books = $this->schema->get('books');
    $this->schema->addField($books->id, ['name' => 'number', 'label' => 'Number', 'type' => 'autoincrement']);
    yield sprintf('  Books: %d (referencing author, genre and the global language)', $result['summary']['create']);

    $read = ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'import' => false];
    $permissions = ['books' => ['read' => true, 'create' => true, 'update' => true, 'delete' => false, 'import' => true]];
    foreach (['authors', 'countries', 'regions', 'genres', 'languages'] as $slug) {
      $permissions[$slug] = $read;
    }
    $this->users->create(new UserInput(
      name: 'Redaktion',
      email: self::EDITOR,
      password: self::EDITOR_PASSWORD,
      permissions: array_combine(array_map(fn(string $slug): string => $this->schema->get($slug)->id, array_keys($permissions)), array_values($permissions)),
      // The role the migration created: may take over records others are editing
      roles: ['editor'],
    ));
    yield sprintf('  Editor: %s / %s (edits and imports books, reads the rest, role Redakteur)', self::EDITOR, self::EDITOR_PASSWORD);

    $client = $this->oauth->createClient(['name' => 'Demo website', 'entities' => ['books']]);
    yield sprintf('  API client "Demo website" (books): client_id=%s client_secret=%s', $client['client']->clientId, $client['secret']);
  }

  /**
   * The role "Autor" for both projects - writes books and blog posts and changes or deletes only its
   * own ones (OwnRecordRule), adds missing authors, reads the rest; no imports - and the user
   * "Autorin" with it.
   */
  private function author(): iterable
  {
    $read = ['read' => true];
    $write = ['read' => true, 'create' => true, 'update_own' => true, 'delete_own' => true];
    $permissions = [];
    $projectIds = [];
    foreach ([
      'bibliothek' => ['books' => $write, 'authors' => ['read' => true, 'create' => true], 'genres' => $read, 'languages' => $read, 'countries' => $read, 'regions' => $read],
      'docs' => ['blog' => $write, 'pages' => $read],
    ] as $project => $entities) {
      $this->useProject($project);
      $projectIds[] = $this->currentProject->get()->id;
      foreach ($entities as $slug => $allowed) {
        $permissions[$this->schema->get($slug)->id] = $allowed;
      }
    }
    $this->access->saveRole(null, ['slug' => 'author', 'name' => 'Autor', 'permissions' => $permissions]);
    yield 'Role "Autor": books and blog posts (read, create, edit and delete own ones), new authors, reads the rest - no imports';

    $this->users->create(new UserInput(
      name: 'Autorin',
      email: self::AUTHOR,
      password: self::AUTHOR_PASSWORD,
      projects: $projectIds,
      roles: ['author'],
    ));
    yield sprintf('  Author: %s / %s (role Autor, both projects)', self::AUTHOR, self::AUTHOR_PASSWORD);
  }

  /**
   * Project 2: the documentation of Excellent CMS (docs/) - pages as a tree and a blog, English only,
   * with the field group SEO and the variables {{url}} (website), {{api_url}} and {{admin_url}} (demo).
   */
  private function documentation(): iterable
  {
    $docsProject = $this->projectService->create(['name' => 'Documentation', 'slug' => 'docs', 'table_prefix' => 'docs_', 'languages' => ['en']]);
    // Blocks of the CMS (Columns), as for projects created in the admin app
    $this->blocks->ensure($docsProject->id);
    $this->useProject('docs');
    $project = $this->currentProject->get();
    $this->projectService->updateVariables($project->id, [
      ['name' => 'url', 'value' => 'https://excellent.jinx-digital.com'],
      ['name' => 'api_url', 'value' => 'https://admin.demo.excellent.jinx-digital.com/api/v1'],
      ['name' => 'admin_url', 'value' => 'https://admin.demo.excellent.jinx-digital.com'],
    ]);
    $this->useProject('docs');
    yield 'Project Documentation: /api/v1/docs/content, tables docs_*, English, variables {{url}}, {{api_url}} and {{admin_url}}';

    $seo = $this->groups->create(['name' => 'seo', 'label' => 'SEO', 'category' => 'Meta', 'fields' => [
      ['name' => 'keywords', 'label' => 'Keywords', 'type' => 'string', 'length' => 50, 'repeatable' => true, 'repeat_max' => 10],
      ['name' => 'description', 'label' => 'Description', 'type' => 'string', 'length' => 160],
    ]]);
    yield '  Field group: SEO (keywords + description)';

    $text = static fn(string $name, string $label, string $type, array $extra = []): array => ['name' => $name, 'label' => $label, 'type' => $type, 'translatable' => true] + $extra;
    // The pages: the documentation of docs/ (as ./yii docs:sync does it on servers)
    $synced = $this->docs->sync('docs', 'pages');
    $this->useProject('docs');
    yield sprintf('  Pages: %d of docs/ as a tree', $synced['created']);
    $blog = $this->schema->createEntity(['slug' => 'blog', 'name' => 'Blog', 'access' => 'public', 'label_field' => 'title', 'trash' => true, 'fields' => [
      $text('title', 'Titel', 'string', ['required' => true]),
      $text('slug', 'Slug', 'slug', ['slug_source' => 'title']),
      ['name' => 'published', 'label' => 'Published', 'type' => 'date', 'required' => true],
      $text('summary', 'Kurzbeschreibung', 'text'),
      $text('body', 'Inhalt', 'markdown'),
      ['name' => 'tags', 'label' => 'Tags', 'type' => 'string', 'length' => 50, 'repeatable' => true],
      ['name' => 'seo', 'label' => 'SEO', 'type' => 'group', 'group' => $seo->id],
    ]]);

    // The blog (English, like the documentation)
    $data = json_decode((string)file_get_contents(dirname(__DIR__).'/resources/samples/docs.json'), true);
    foreach ($data['posts'] as $post) {
      $this->records->create($blog, [
        'title' => $post['title'],
        'slug' => $post['slug'],
        'published' => $post['published'],
        'summary' => $post['summary'],
        'body' => $post['body'],
        'tags' => $post['tags'],
        'seo' => $post['seo'],
      ]);
    }
    yield sprintf('  Blog: %d posts', count($data['posts']));

    // Page builder: landing pages from blocks - each block type is a field group of the kind "block"
    $hero = $this->groups->create(['name' => 'hero', 'label' => 'Hero', 'kind' => 'block', 'category' => 'Text', 'fields' => [
      ['name' => 'title', 'label' => 'Title', 'type' => 'string', 'required' => true],
      ['name' => 'text', 'label' => 'Text', 'type' => 'text'],
      ['name' => 'button_label', 'label' => 'Button label', 'type' => 'string', 'length' => 50],
      ['name' => 'button_url', 'label' => 'Button link', 'type' => 'url'],
    ]]);
    $features = $this->groups->create(['name' => 'features', 'label' => 'Features', 'kind' => 'block', 'category' => 'Text', 'fields' => [
      ['name' => 'title', 'label' => 'Title', 'type' => 'string'],
      ['name' => 'items', 'label' => 'Features', 'type' => 'string', 'repeatable' => true, 'repeat_max' => 12],
    ]]);
    $richText = $this->groups->create(['name' => 'rich_text', 'label' => 'Text', 'kind' => 'block', 'category' => 'Text', 'fields' => [
      ['name' => 'body', 'label' => 'Text', 'type' => 'markdown', 'required' => true],
    ]]);
    $cta = $this->groups->create(['name' => 'call_to_action', 'label' => 'Call to action', 'kind' => 'block', 'category' => 'Text', 'fields' => [
      ['name' => 'title', 'label' => 'Title', 'type' => 'string', 'required' => true],
      ['name' => 'button_label', 'label' => 'Button label', 'type' => 'string', 'length' => 50],
      ['name' => 'button_url', 'label' => 'Button link', 'type' => 'url'],
    ]]);
    // More block types (media & text, gallery …) from resources/samples/blocks.json
    $blockData = json_decode((string)file_get_contents(dirname(__DIR__).'/resources/samples/blocks.json'), true);
    $more = [];
    foreach ($blockData['groups'] as $group) {
      $created = $this->groups->create($group);
      // Field groups used inside blocks (a column, a question) are no block types themselves
      if ('block' === $group['kind']) {
        $more[] = $created->id;
      }
    }

    // DEMO_PREVIEW_URL: a page that shows them, e.g. demo/page-builder.php of the PHP SDK
    $landing = $this->schema->createEntity(['slug' => 'landing_pages', 'name' => 'Landing pages', 'access' => 'public', 'label_field' => 'title', 'drafts' => true, 'preview_url' => $_ENV['DEMO_PREVIEW_URL'] ?? null, 'fields' => [
      ['name' => 'title', 'label' => 'Title', 'type' => 'string', 'required' => true],
      ['name' => 'slug', 'label' => 'Slug', 'type' => 'slug', 'slug_source' => 'title'],
      // ... and the block Columns of the CMS
      ['name' => 'content', 'label' => 'Content', 'type' => 'group', 'blocks' => [$hero->id, $richText->id, ...$more, $features->id, $cta->id, 'columns']],
      ['name' => 'seo', 'label' => 'SEO', 'type' => 'group', 'group' => $seo->id],
    ]]);
    $this->records->create($landing, [
      'title' => 'Home',
      'slug' => 'home',
      'content' => [
        ['_type' => 'hero', 'title' => 'Content in real tables', 'text' => 'Import your spreadsheets, get a typed content API.', 'button_label' => 'Try the demo', 'button_url' => '{{admin_url}}'],
        ['_type' => 'features', 'title' => 'What you get', 'items' => ['Import from Excel and CSV', 'Projects and languages', 'Drafts, working copies, revisions', 'Events and webhooks', 'Page builder with preview']],
        ['_type' => 'rich_text', 'body' => "Every block type has its fields like a field group: the website renders one template per block type.\n\nWith the PHP SDK: `\$page->blocks('content')`."],
        ['_type' => 'call_to_action', 'title' => 'Ready to start?', 'button_label' => 'Read the docs', 'button_url' => '{{url}}/#docs'],
      ],
      'seo' => ['description' => 'Excellent CMS - a headless CMS with real tables.'],
      'draft' => false,
    ]);
    // A page with every block type - images are generated
    $images = [];
    foreach ([1 => ['#065f46', '#34d399'], 2 => ['#1e3a8a', '#60a5fa'], 3 => ['#7c2d12', '#fb923c'], 4 => ['#581c87', '#e879f9']] as $number => $colors) {
      $images['@image:'.$number] = $this->sampleImage($number, ...$colors);
    }
    $replace = static function (mixed $value) use (&$replace, $images): mixed {
      return is_array($value) ? array_map($replace, $value) : (is_string($value) ? ($images[$value] ?? $value) : $value);
    };
    $this->records->create($landing, ['title' => 'All blocks', 'slug' => 'blocks', 'content' => $replace($blockData['showcase']), 'draft' => false]);
    yield sprintf('  Landing pages: home and "All blocks" - %d block types (page builder)', 4 + count($more));
  }

  /**
   * A generated image for the page builder demo: a gradient with its number. Returns the media id.
   */
  private function sampleImage(int $number, string $from, string $to): string
  {
    [$width, $height] = [1200, 800];
    $image = imagecreatetruecolor($width, $height);
    $rgb = static fn(string $hex): array => array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    [$a, $b] = [$rgb($from), $rgb($to)];
    for ($x = 0; $x < $width; $x++) {
      $t = $x / ($width - 1);
      $color = imagecolorallocate($image, ...array_map(static fn(int $i): int => (int)round($a[$i] + ($b[$i] - $a[$i]) * $t), [0, 1, 2]));
      imageline($image, $x, 0, $x, $height, $color);
    }
    $white = imagecolorallocatealpha($image, 255, 255, 255, 60);
    imagefilledellipse($image, (int)($width * 0.7), (int)($height * 0.35), 420, 420, $white);
    imagefilledellipse($image, (int)($width * 0.25), (int)($height * 0.75), 260, 260, $white);
    ob_start();
    imagepng($image);
    $content = (string)ob_get_clean();
    $file = new UploadedFile((new StreamFactory())->createStream($content), strlen($content), UPLOAD_ERR_OK, "block-sample-{$number}.png", 'image/png');
    return (string)$this->media->upload($file, null, true)['id'];
  }
}
