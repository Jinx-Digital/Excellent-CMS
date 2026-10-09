<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Search\SearchIndex;
use App\Application\Service\CurrentProject;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:rebuild', description: 'Builds the search index anew: every project, one project (--project) or one entity (--project --entity).')]
final class SearchRebuildCommand extends Command
{
  public function __construct(
    private SearchIndex $index,
    private ProjectRepository $projects,
    private EntityRepository $entities,
    private CurrentProject $currentProject,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Slug of the project');
    $this->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Slug of the entity (with --project)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $projectSlug = $input->getOption('project');
    $entitySlug = $input->getOption('entity');
    $projects = null !== $projectSlug ? array_filter([$this->projects->find((string)$projectSlug)]) : $this->projects->all();
    if ([] === $projects) {
      $output->writeln('<error>This project does not exist.</error>');
      return Command::FAILURE;
    }
    foreach ($projects as $project) {
      $this->currentProject->set($project);
      foreach ($this->entities->all() as $entity) {
        if ($entity->projectId !== $project->id || (null !== $entitySlug && $entity->slug !== $entitySlug)) {
          continue;
        }
        $output->writeln(sprintf('%s / %s: %d records', $project->slug, $entity->slug, $this->index->rebuild($entity)));
      }
      $this->index->pruneTerms($project->id);
    }
    return Command::SUCCESS;
  }
}
