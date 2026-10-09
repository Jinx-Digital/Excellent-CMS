<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Project\ProjectImport;
use App\Application\Service\CurrentUser;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'project:import', description: 'Creates a project from an export folder (project.json, variables, entities, records, media) - e.g. of another installation.')]
final class ProjectImportCommand extends Command
{
  public function __construct(
    private ProjectImport $import,
    private UserRepository $users,
    private CurrentUser $currentUser,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addArgument('folder', InputArgument::REQUIRED, 'The export folder');
    $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Slug of the new project (default: the one of the export)');
    $this->addOption('as', null, InputOption::VALUE_REQUIRED, 'E-mail of the administrator the records and files are created by (default: the first one)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $admin = null !== $input->getOption('as')
      ? $this->users->findByEmail((string)$input->getOption('as'))
      : array_values(array_filter($this->users->search()->all(), static fn($user): bool => $user->isAdmin() && $user->isActive()))[0] ?? null;
    if (null === $admin) {
      $output->writeln('<error>No administrator - create one first: ./yii user:create-admin</error>');
      return Command::FAILURE;
    }
    $this->currentUser->set($admin);
    try {
      foreach ($this->import->import((string)$input->getArgument('folder'), $input->getOption('project')) as $line) {
        $output->writeln($line);
      }
    } catch (Throwable $e) {
      $output->writeln('<error>'.$e->getMessage().'</error>');
      return Command::FAILURE;
    }
    return Command::SUCCESS;
  }
}
