<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Content\RecordSchedules;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'schedule:run', description: 'Publishes and unpublishes the records scheduled until now (cron, every minute).')]
final class ScheduleRunCommand extends Command
{
  public function __construct(
    private RecordSchedules $schedules,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $result = $this->schedules->run();
    $output->writeln(sprintf('Scheduled - done: %d, failed: %d', $result['done'], $result['failed']));
    return Command::SUCCESS;
  }
}
