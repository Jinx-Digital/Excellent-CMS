<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

/**
 * Sends one webhook request: POST of a JSON body.
 */
interface WebhookSender
{
  /**
   * @param array<string, string> $headers
   * @return array{status: ?int, error: ?string} HTTP status, or the error when nothing came back
   */
  public function send(string $url, string $body, array $headers): array;
}
