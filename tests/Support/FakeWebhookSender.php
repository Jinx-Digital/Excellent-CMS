<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Webhook\WebhookSender;

/**
 * Records the webhook calls instead of sending them.
 */
final class FakeWebhookSender implements WebhookSender
{
  /** @var list<array{url: string, body: array, headers: array<string, string>, raw: string}> */
  public static array $calls = [];

  /** Status the fake receiver answers with */
  public static int $status = 200;

  public function send(string $url, string $body, array $headers): array
  {
    self::$calls[] = ['url' => $url, 'body' => (array)json_decode($body, true), 'headers' => $headers, 'raw' => $body];
    return ['status' => self::$status, 'error' => self::$status >= 400 ? 'Fehler' : null];
  }
}
