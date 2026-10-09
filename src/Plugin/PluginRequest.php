<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * A request to a route of a plugin (see PluginRegistry::route()): query, body, who asks, in which
 * project - and the plugin's context (settings, database).
 */
final class PluginRequest
{
  /**
   * @param array<string, mixed> $query
   * @param array<string, mixed> $body
   */
  public function __construct(
    public readonly string $method,
    public readonly string $path,
    public readonly array $query,
    public readonly array $body,
    public readonly ?string $projectId,
    public readonly ?string $userId,
    public readonly bool $isAdmin,
    public readonly PluginContext $plugin,
    /** @var array<string, string> parameters of the path ("forms/{id}" → id) */
    public readonly array $params = [],
    /** Address of the caller (public routes) */
    public readonly ?string $ip = null,
  ) {
  }
}
