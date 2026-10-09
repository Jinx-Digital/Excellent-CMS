<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Docs\DocsSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'docs:sync', description: 'Loads the documentation (docs/*.md) into a project: the pages of an entity, matched by slug - run it after every update.')]
final class DocsSyncCommand extends Command
{
  public function __construct(private DocsSync $docs)
  {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Slug of the project (created if missing)', 'docs');
    $this->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Slug of the entity (created if missing)', 'pages');
    $this->addOption('prune', null, InputOption::VALUE_NONE, 'Delete pages of the entity that are no longer in docs/');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $result = $this->docs->sync((string)$input->getOption('project'), (string)$input->getOption('entity'), (bool)$input->getOption('prune'));
    $output->writeln(sprintf('%d pages created, %d updated, %d deleted.', $result['created'], $result['updated'], $result['deleted']));
    return Command::SUCCESS;
  }
}
