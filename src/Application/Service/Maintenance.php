<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Content\RecordLocks;
use App\Application\Content\RecordSchedules;
use App\Application\Import\ImportService;
use App\Application\Media\MediaService;
use App\Infrastructure\Media\ImageVariants;
use App\Repository\MediaRepository;
use App\Repository\SettingRepository;
use App\Repository\UserTokenRepository;

/**
 * What runs regularly: scheduled publishing (every minute) and the cleanup (hourly) - by cron
 * (./yii schedule:run, ./yii cleanup) or, on servers without cron, by the web cron (GET /api/v1/cron).
 */
final class Maintenance
{
  private const LAST_CLEANUP = 'maintenance_cleanup_at';

  public function __construct(
    private RecordSchedules $schedules,
    private OAuthService $oauth,
    private ImportService $imports,
    private RateLimiter $rateLimiter,
    private MediaService $media,
    private UserTokenRepository $userTokens,
    private ImageVariants $variants,
    private MediaRepository $files,
    private RecordLocks $locks,
    private SettingRepository $settings,
  ) {
  }

  /**
   * @return array{done: int, failed: int}
   */
  public function schedules(): array
  {
    return $this->schedules->run();
  }

  /**
   * @return array{tokens: int, mail_links: int, imports: int, media: int, image_variants: int, record_locks: int, rate_limit_counters: int}
   */
  public function cleanup(): array
  {
    $result = [
      'tokens' => $this->oauth->deleteExpiredTokens(),
      'mail_links' => $this->userTokens->deleteExpired(),
      'imports' => $this->imports->removeExpired(),
      'media' => $this->media->removeUnused(),
      'image_variants' => $this->variants->removeOrphans(fn(array $ids): array => array_map('strval', array_keys($this->files->findMany($ids)))),
      'record_locks' => $this->locks->removeExpired(),
      'rate_limit_counters' => $this->rateLimiter->deleteOlderThan(86400),
    ];
    $this->settings->set(self::LAST_CLEANUP, time());
    return $result;
  }

  /**
   * The cleanup if the last one is at least $seconds ago - null: not due yet.
   *
   * @return array<string, int>|null
   */
  public function cleanupIfDue(int $seconds = 3600): ?array
  {
    $last = (int)$this->settings->get(self::LAST_CLEANUP, 0);
    return time() - $last >= $seconds ? $this->cleanup() : null;
  }
}
