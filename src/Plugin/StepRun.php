<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Application\Event\Template;
use App\Application\Service\EnvVariables;
use App\Infrastructure\Webhook\WebhookSender;

/**
 * One run of a plugin's event step: the records of the event, their placeholders, progress and
 * the helpers a step needs (HTTP, .env variables). Plugins get it in their handler.
 */
final class StepRun
{
  /** @var array<int, array<string, mixed>> values for the next steps, per record (see pass()) */
  private array $passed = [];

  /**
   * @param array<string, mixed> $event the event (name, action …)
   * @param list<array<string, mixed>> $records
   * @param list<array<string, mixed>> $contexts the placeholders per record ({{record.title}} …)
   * @param \Closure(int, int): void $progress
   */
  public function __construct(
    public readonly array $event,
    public readonly array $records,
    public readonly array $contexts,
    private readonly \Closure $progress,
    private readonly WebhookSender $sender,
    private readonly EnvVariables $env,
    public readonly PluginContext $plugin,
  ) {
  }

  /**
   * A text with the placeholders of a record (context of index $index) and $NAME variables.
   */
  public function render(string $template, int $index = 0): string
  {
    return $this->env->interpolate((string)Template::render($template, $this->contexts[$index] ?? []));
  }

  /**
   * A whole value: "$NAME" read from the .env, otherwise as it is.
   */
  public function env(?string $value): string
  {
    return $this->env->resolve($value);
  }

  /**
   * Values the next steps of the run get for the record of index $index: "record" replaces the
   * record ({{record.…}}), every other key is a placeholder of its own ({{names[0]}}).
   *
   * @param array<string, mixed> $values
   */
  public function pass(int $index, array $values): void
  {
    $this->passed[$index] = $values + ($this->passed[$index] ?? []);
  }

  /**
   * @internal the records with what the step passed on
   * @param list<array<string, mixed>> $records
   * @return list<array<string, mixed>>
   */
  public function apply(array $records): array
  {
    foreach ($this->passed as $index => $values) {
      if (!isset($records[$index])) {
        continue;
      }
      if (array_key_exists('record', $values)) {
        $records[$index]['data'] = $values['record'];
      }
      unset($values['record']);
      $records[$index]['vars'] = $values + (array)($records[$index]['vars'] ?? []);
    }
    return $records;
  }

  public function progress(int $done, int $total): void
  {
    ($this->progress)($done, $total);
  }

  /**
   * POST with a JSON body - throws if it fails (HTTP 400 and more, no answer).
   *
   * @param array<string, string> $headers
   * @return int the HTTP status
   */
  public function postJson(string $url, mixed $body, array $headers = []): int
  {
    $result = $this->sender->send($url, (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json', 'User-Agent' => 'Excellent-CMS-Plugin'] + $headers);
    if (null === $result['status'] || $result['status'] >= 400) {
      throw new \RuntimeException(sprintf('HTTP %s: %s', $result['status'] ?? '–', (string)($result['error'] ?? '')));
    }
    return $result['status'];
  }
}
