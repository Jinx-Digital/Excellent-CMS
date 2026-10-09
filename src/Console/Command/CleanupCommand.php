<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Service\Maintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cleanup', description: 'Removes expired OAuth tokens and mail links, abandoned imports, unused media, variants of deleted images, expired record locks and old rate limit counters (cron, e.g. hourly).')]
final class CleanupCommand extends Command
{
  public function __construct(private Maintenance $maintenance)
  {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $removed = $this->maintenance->cleanup();
    $output->writeln(sprintf(
      'Removed - tokens: %d, mail links: %d, imports: %d, media: %d, image variants: %d, record locks: %d, rate limit counters: %d',
      ...array_values($removed),
    ));
    return Command::SUCCESS;
  }
}
