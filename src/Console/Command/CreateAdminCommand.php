<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Api\Input\UserInput;
use App\Application\Service\UserService;
use App\Shared\Exception\ValidationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'user:create-admin', description: 'Creates an administrator (e.g. the first one after the installation).')]
final class CreateAdminCommand extends Command
{
  public function __construct(
    private UserService $userService,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse (Anmeldung)')
      ->addArgument('name', InputArgument::OPTIONAL, 'Anzeigename', 'Administrator')
      ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Passwort (ohne: wird abgefragt)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $password = (string)$input->getOption('password');
    if ('' === $password) {
      $question = (new Question('Password: '))->setHidden(true);
      $password = (string)$this->getHelper('question')->ask($input, $output, $question);
    }

    try {
      $user = $this->userService->create(new UserInput(
        name: (string)$input->getArgument('name'),
        email: mb_strtolower(trim((string)$input->getArgument('email'))),
        password: $password,
        isAdmin: true,
      ));
    } catch (ValidationException $e) {
      foreach ($e->getErrors() as $field => $messages) {
        $output->writeln("<error>{$field}: ".implode(' ', $messages).'</error>');
      }
      return Command::FAILURE;
    }

    $output->writeln("<info>Administrator \"{$user->getEmail()}\" created.</info>");
    return Command::SUCCESS;
  }
}
