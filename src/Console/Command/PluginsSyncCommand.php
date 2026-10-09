<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Schema\PluginSetup;
use App\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'plugins:sync', description: 'Creates missing blocks of the active plugins (every project or --project, every plugin or --plugin); --templates resets the templates of their blocks to the plugins\' ones.')]
final class PluginsSyncCommand extends Command
{
  public function __construct(
    private PluginSetup $setup,
    private ProjectRepository $projects,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Slug of the project');
    $this->addOption('plugin', null, InputOption::VALUE_REQUIRED, 'Name of the plugin');
    $this->addOption('templates', null, InputOption::VALUE_NONE, 'Overwrite the templates of the plugins\' blocks (also ones changed in the admin app)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $projectId = null;
    if (null !== $input->getOption('project')) {
      $project = $this->projects->find((string)$input->getOption('project'));
      if (null === $project) {
        $output->writeln('<error>This project does not exist.</error>');
        return Command::FAILURE;
      }
      $projectId = $project->id;
    }
    $plugin = $input->getOption('plugin');
    $count = $this->setup->ensure($projectId, null !== $plugin ? (string)$plugin : null, (bool)$input->getOption('templates'));
    $output->writeln(sprintf('%d blocks created or updated.', $count));
    return Command::SUCCESS;
  }
}
