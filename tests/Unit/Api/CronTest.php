<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Tests\Support\ApiTestCase;

/**
 * Web cron (servers without cron jobs): only with CRON_KEY, publishing every time, the cleanup hourly.
 */
class CronTest extends ApiTestCase
{
  protected function _after(): void
  {
    unset($_ENV['CRON_KEY']);
  }

  public function testWebCron(): void
  {
    // Off without a key
    $this->assertSame(404, $this->api('GET', '/cron')['status']);
    $_ENV['CRON_KEY'] = 'k-'.bin2hex(random_bytes(12));
    $this->assertSame(403, $this->api('GET', '/cron?key=wrong')['status']);

    $first = $this->api('GET', '/cron?key='.$_ENV['CRON_KEY']);
    $this->assertSame(200, $first['status'], json_encode($first['body']));
    $this->assertSame(['done', 'failed'], array_keys($first['body']['data']['scheduled']));
    // The cleanup at most hourly (an earlier one may have run already)
    $second = $this->api('POST', '/cron', [], headers: ['X-Cron-Key' => $_ENV['CRON_KEY']]);
    $this->assertSame(200, $second['status']);
    $this->assertNull($second['body']['data']['cleanup']);
  }
}
