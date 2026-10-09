<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Environment;
use App\Repository\UserRepository;
use Fixtures\Fixture;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Injector\Injector;

/**
 * Loads demo/test data from fixtures/ (dev/test only): `./yii fixtures:load dev` or `test`.
 * Only into an empty database - `make db-reset` / `make test-db-reset` start from scratch.
 */
#[AsCommand(name: 'fixtures:load', description: 'Creates the demo or test data from fixtures/ (dev/test only).')]
final class FixturesLoadCommand extends Command
{
  /**
   * @param array<string, list<class-string<Fixture>>> $sets
   */
  public function __construct(
    private Injector $injector,
    private UserRepository $userRepository,
    private array $sets,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addArgument('set', InputArgument::OPTIONAL, 'Fixture set: '.implode(', ', array_keys($this->sets)), 'dev');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    if (!Environment::isDev() && !Environment::isTest()) {
      $output->writeln('<error>Fixtures exist in dev/test only.</error>');
      return Command::FAILURE;
    }
    if (!class_exists(Fixture::class)) {
      $output->writeln('<error>The fixtures cannot be loaded - please install the dev dependencies (composer install).</error>');
      return Command::FAILURE;
    }
    $set = (string)$input->getArgument('set');
    if (!isset($this->sets[$set])) {
      $output->writeln("<error>Unknown fixture set \"{$set}\". Available: ".implode(', ', array_keys($this->sets)).'</error>');
      return Command::FAILURE;
    }
    if ($this->userRepository->count() > 0) {
      $output->writeln('<comment>The database contains data already. Start over: make db-reset / make test-db-reset</comment>');
      return Command::SUCCESS;
    }

    foreach ($this->sets[$set] as $class) {
      if (!is_subclass_of($class, Fixture::class)) {
        throw new RuntimeException("{$class} must extend ".Fixture::class);
      }
      $output->writeln("Fixture: {$class}");
      /** @var Fixture $fixture */
      $fixture = $this->injector->make($class);
      foreach ($fixture->run() as $line) {
        $output->writeln('  '.$line);
      }
    }
    $output->writeln("<info>Fixture set \"{$set}\" loaded.</info>");
    return Command::SUCCESS;
  }
}
