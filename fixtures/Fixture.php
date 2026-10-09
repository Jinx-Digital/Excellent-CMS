<?php

declare(strict_types=1);

namespace Fixtures;

use App\Api\Input\UserInput;
use App\Application\Import\ImportService;
use App\Application\Schema\SchemaService;
use App\Application\Service\CurrentProject;
use App\Application\Service\CurrentUser;
use App\Application\Service\UserService;
use App\Domain\Entity\User;
use App\Repository\ProjectRepository;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFile;

/**
 * Base class of the demo/test data (`./yii fixtures:load <set>`). Fixtures use the application
 * services - the demo data is imported exactly like a user would import it.
 */
abstract class Fixture
{
  public const ADMIN = 'admin@example.com';
  public const ADMIN_PASSWORD = 'admin123';
  public const EDITOR = 'redaktion@example.com';
  public const EDITOR_PASSWORD = 'redaktion123';
  public const AUTHOR = 'autorin@example.com';
  public const AUTHOR_PASSWORD = 'autorin123';

  public function __construct(
    protected UserService $users,
    protected ImportService $imports,
    protected CurrentUser $currentUser,
    protected CurrentProject $currentProject,
    protected ProjectRepository $projects,
    protected SchemaService $schema,
  ) {
  }

  /**
   * @return iterable<string> lines for the console
   */
  abstract public function run(): iterable;

  protected function createAdmin(): User
  {
    $admin = $this->users->create(new UserInput(name: 'Administrator', email: self::ADMIN, password: self::ADMIN_PASSWORD, isAdmin: true));
    // Act as the admin from now on (imports belong to a user), in the project the migration created
    $this->currentUser->set($admin);
    $this->useProject('main');
    return $admin;
  }

  protected function useProject(string $slug): void
  {
    $this->currentProject->set($this->projects->find($slug));
  }

  /**
   * The UN M49 regions as a tree (World > continents > subregions, from ICU/CLDR): first the
   * regions, then the parent field, then the parents by their code - like a user would do it.
   */
  protected function importRegions(): array
  {
    $result = $this->import('regions.csv', ['slug' => 'regions', 'name' => 'Regions', 'access' => 'public', 'label_field' => 'name'], [
      'code' => ['label' => 'M49 code', 'unique' => true, 'required' => true],
      'name' => ['required' => true],
      'name_de' => ['label' => 'Name (German)'],
      'parent' => null,
    ]);
    $regions = $this->schema->get('regions');
    $this->schema->addField($regions->id, ['name' => 'parent', 'label' => 'Parent region', 'type' => 'reference', 'reference' => 'regions']);
    $this->schema->updateEntity($regions->id, ['tree_field' => 'parent']);
    $this->importInto('regions.csv', 'regions', 'code', ['code' => ['field' => 'code'], 'parent' => ['field' => 'parent', 'match' => 'code']]);
    return $result;
  }

  /**
   * All ISO 3166 countries (names, codes, currency from ICU/CLDR) as the public entity "countries",
   * each pointing to its region (entity "regions", imported first).
   */
  protected function importCountries(): array
  {
    $this->importRegions();
    return $this->import('countries.csv', ['slug' => 'countries', 'name' => 'Countries', 'access' => 'public', 'label_field' => 'name'], [
      'name' => ['unique' => true, 'required' => true],
      'name_de' => ['label' => 'Name (German)'],
      'alpha2code' => ['label' => 'ISO 2', 'unique' => true, 'required' => true],
      'alpha3code' => ['label' => 'ISO 3', 'unique' => true],
      'numeric_code' => ['label' => 'ISO number', 'unique' => true],
      'region' => ['type' => 'reference', 'reference' => 'regions', 'match' => 'code', 'label' => 'Region'],
      'currency' => ['label' => 'Currency'],
      'eu_member' => ['label' => 'EU member'],
    ]);
  }

  /**
   * Imports a sample file from resources/samples with the suggested mapping, adjusted by $plan.
   *
   * @param array<string, array> $columns column => overrides (null = ignore the column)
   */
  protected function import(string $file, array $entity, array $columns = []): array
  {
    $path = dirname(__DIR__).'/resources/samples/'.$file;
    $content = (string)file_get_contents($path);
    $upload = new UploadedFile((new StreamFactory())->createStream($content), strlen($content), UPLOAD_ERR_OK, $file, 'text/csv');
    $analysis = $this->imports->upload($upload);

    $planColumns = [];
    foreach ($analysis['columns'] as $column) {
      $override = array_key_exists($column['column'], $columns) ? $columns[$column['column']] : [];
      $planColumns[] = null === $override
        ? ['column' => $column['column'], 'field' => null]
        : ['column' => $column['column']] + $override + $column['suggestion'];
    }

    return $this->imports->run($analysis['import_id'], [
      'delimiter' => $analysis['delimiter'],
      'mode' => 'new',
      'entity' => $entity + $analysis['entity'],
      'columns' => $planColumns,
    ]);
  }

  /**
   * Imports a sample file into an existing entity, updating records with the same key.
   *
   * @param array<string, array{field: string, match?: string}> $columns column => field (other columns are ignored)
   */
  protected function importInto(string $file, string $target, string $key, array $columns): array
  {
    $path = dirname(__DIR__).'/resources/samples/'.$file;
    $content = (string)file_get_contents($path);
    $upload = new UploadedFile((new StreamFactory())->createStream($content), strlen($content), UPLOAD_ERR_OK, $file, 'text/csv');
    $analysis = $this->imports->upload($upload);

    $planColumns = [];
    foreach ($analysis['columns'] as $column) {
      $planColumns[] = ['column' => $column['column'], 'field' => $columns[$column['column']]['field'] ?? null, 'match' => $columns[$column['column']]['match'] ?? null];
    }
    return $this->imports->run($analysis['import_id'], [
      'delimiter' => $analysis['delimiter'],
      'mode' => 'existing',
      'target' => $target,
      'key' => $key,
      'columns' => $planColumns,
    ]);
  }
}
