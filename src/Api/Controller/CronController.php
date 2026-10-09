<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Application\Service\Maintenance;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Web cron for servers without cron jobs: GET/POST /v1/cron?key=<CRON_KEY> (or header X-Cron-Key), called every
 * minute by an outside service (e.g. cron-job.org). Runs the scheduled publishing and - at most hourly - the
 * cleanup. Without CRON_KEY (at least 16 characters) the address does not exist.
 */
final class CronController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private Maintenance $maintenance,
  ) {
  }

  public function run(ServerRequestInterface $request): ResponseInterface
  {
    $key = (string)($_ENV['CRON_KEY'] ?? '');
    if (strlen($key) < 16) {
      throw UserFacingException::notFound(I18n::t('This URL does not exist.'));
    }
    $given = $request->getHeaderLine('X-Cron-Key') ?: (string)($request->getQueryParams()['key'] ?? '');
    if (!hash_equals($key, $given)) {
      throw new UserFacingException(I18n::t('Only administrators may do this.'), 403, 'forbidden');
    }
    @set_time_limit(300);
    return $this->responseFactory->success([
      'scheduled' => $this->maintenance->schedules(),
      // null: the last cleanup was less than an hour ago
      'cleanup' => $this->maintenance->cleanupIfDue(),
    ]);
  }
}
