<?php

declare(strict_types=1);

namespace App\Plugin;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * What a plugin gets from the CMS: its name and folder, its settings (resolved: $NAME .env
 * variables read, secrets decrypted) and the database.
 */
final class PluginContext
{
  /**
   * @param array<string, mixed> $settings
   */
  public function __construct(
    public readonly string $name,
    public readonly string $path,
    private readonly array $settings,
    public readonly ConnectionInterface $db,
    private readonly ?\Closure $trigger = null,
    private readonly ?\Psr\Log\LoggerInterface $logger = null,
    /** Key of the plugin for sign() (derived from the CMS's JWT_SECRET) */
    private readonly ?string $key = null,
  ) {
  }

  /**
   * A value signed with the plugin's own key: "<value>.<signature>" - e.g. a token for a form that
   * only this CMS can have issued. The value is readable, not secret.
   */
  public function sign(string $value): string
  {
    return $value.'.'.$this->signature($value);
  }

  /**
   * The value of a token of sign() - null if it was not signed with this plugin's key (or changed).
   */
  public function verify(string $token): ?string
  {
    $dot = strrpos($token, '.');
    if (false === $dot) {
      return null;
    }
    $value = substr($token, 0, $dot);
    return hash_equals($this->signature($value), substr($token, $dot + 1)) ? $value : null;
  }

  private function signature(string $value): string
  {
    if (null === $this->key) {
      throw new \RuntimeException('Signing needs JWT_SECRET.');
    }
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $value, $this->key, true)), '+/', '-_'), '=');
  }

  /**
   * Writes to the log of the CMS (runtime/logs/app.log), with the plugin's name in front.
   *
   * @param 'debug'|'info'|'notice'|'warning'|'error' $level
   */
  public function log(string $level, string $message): void
  {
    $this->logger?->log($level, sprintf('Plugin "%s": %s', $this->name, $message));
  }

  /**
   * Starts the events of one of the plugin's event sources (see PluginRegistry::eventSource()):
   * the item ($data, available as {{record.…}}) with its id, in a project - limited to events of
   * the target (e.g. a form) if they have one. They run when the request is done.
   *
   * @param array<string, mixed> $data
   */
  public function trigger(string $source, string $action, string $projectId, string $id, array $data, ?string $target = null): void
  {
    if (null !== $this->trigger) {
      ($this->trigger)($this->name.'.'.$source, $action, $projectId, $id, $data, $target);
    }
  }

  public function setting(string $key, mixed $default = null): mixed
  {
    $value = $this->settings[$key] ?? null;
    return null === $value || '' === $value ? $default : $value;
  }

  /**
   * @return array<string, mixed>
   */
  public function settings(): array
  {
    return $this->settings;
  }
}
