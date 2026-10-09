<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Application\Content\RecordLocks;
use App\Application\Import\ImportService;
use App\Application\Media\MediaService;
use App\Application\Service\OAuthService;
use App\Application\Service\RateLimiter;
use App\Infrastructure\Media\ImageVariants;
use App\Repository\MediaRepository;
use App\Repository\UserTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cleanup', description: 'Removes expired OAuth tokens and mail links, abandoned imports, unused media, variants of deleted images, expired record locks and old rate limit counters (cron, e.g. hourly).')]
final class CleanupCommand extends Command
{
  public function __construct(
    private OAuthService $oauth,
    private ImportService $imports,
    private RateLimiter $rateLimiter,
    private MediaService $media,
    private UserTokenRepository $userTokens,
    private ImageVariants $variants,
    private MediaRepository $files,
    private RecordLocks $locks,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $output->writeln(sprintf(
      'Removed - tokens: %d, mail links: %d, imports: %d, media: %d, image variants: %d, record locks: %d, rate limit counters: %d',
      $this->oauth->deleteExpiredTokens(),
      $this->userTokens->deleteExpired(),
      $this->imports->removeExpired(),
      $this->media->removeUnused(),
      $this->variants->removeOrphans(fn(array $ids): array => array_map('strval', array_keys($this->files->findMany($ids)))),
      $this->locks->removeExpired(),
      $this->rateLimiter->deleteOlderThan(86400),
    ));
    return Command::SUCCESS;
  }
}
