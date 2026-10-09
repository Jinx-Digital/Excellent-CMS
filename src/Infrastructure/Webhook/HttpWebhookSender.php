<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class HttpWebhookSender implements WebhookSender
{
  /** Seconds a receiver gets to answer */
  private const TIMEOUT = 5;

  private HttpClientInterface $client;

  public function __construct(?HttpClientInterface $client = null)
  {
    $this->client = $client ?? HttpClient::create(['timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT, 'max_redirects' => 0]);
  }

  public function send(string $url, string $body, array $headers): array
  {
    try {
      $response = $this->client->request('POST', $url, ['body' => $body, 'headers' => $headers]);
      $status = $response->getStatusCode();
      return ['status' => $status, 'error' => $status >= 400 ? mb_strimwidth($response->getContent(false), 0, 300, '…') : null];
    } catch (Throwable $e) {
      return ['status' => null, 'error' => $e->getMessage()];
    }
  }
}
