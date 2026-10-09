<?php

declare(strict_types=1);

namespace Fixtures;

use App\Api\Input\UserInput;
use App\Application\Import\ImportService;
use App\Application\Schema\SchemaService;
use App\Application\Service\CurrentProject;
use App\Application\Service\CurrentUser;
use App\Application\Service\UserService;
use App\Repository\ProjectRepository;

/**
 * Known data the API tests use (do not change it in tests - create your own records for that):
 * an admin, an editor and the public entity "countries" imported from countries.csv.
 */
final class TestFixture extends Fixture
{
  public function __construct(
    UserService $users,
    ImportService $imports,
    CurrentUser $currentUser,
    CurrentProject $currentProject,
    ProjectRepository $projects,
    SchemaService $schema,
  ) {
    parent::__construct($users, $imports, $currentUser, $currentProject, $projects, $schema);
  }

  public function run(): iterable
  {
    $this->createAdmin();
    $result = $this->importCountries();
    yield sprintf('Countries: %d', $result['summary']['create']);

    $countries = $this->schema->get('countries');
    $this->users->create(new UserInput(
      name: 'Redaktion',
      email: self::EDITOR,
      password: self::EDITOR_PASSWORD,
      permissions: [$countries->id => ['read' => true, 'update' => true]],
    ));
  }
}
