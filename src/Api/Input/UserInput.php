<?php

declare(strict_types=1);

namespace App\Api\Input;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Create/update payload for users. A null property means "not sent" (keep the current value).
 */
final readonly class UserInput
{
  /**
   * @param array<string, array<string, bool>>|null $permissions entity id => permission => allowed
   */
  public function __construct(
    public ?string $name = null,
    public ?string $email = null,
    public ?string $password = null,
    public ?bool $isAdmin = null,
    public ?bool $isActive = null,
    public ?array $permissions = null,
    /** @var list<string>|null ids of the projects the user works in */
    public ?array $projects = null,
    /** @var list<string>|null slugs of the roles (see AccessControl) */
    public ?array $roles = null,
  ) {}

  public static function from(ServerRequestInterface $request): self
  {
    $body = (array)($request->getParsedBody() ?? []);
    $string = static fn(string $key): ?string => array_key_exists($key, $body) && null !== $body[$key] ? trim((string)$body[$key]) : null;

    $permissions = null;
    if (isset($body['permissions']) && is_array($body['permissions'])) {
      $permissions = [];
      foreach ($body['permissions'] as $entityId => $values) {
        if (is_array($values)) {
          $permissions[(string)$entityId] = array_map(static fn($v): bool => (bool)filter_var($v, FILTER_VALIDATE_BOOL), $values);
        }
      }
    }

    $isAdmin = array_key_exists('is_admin', $body) ? (bool)filter_var($body['is_admin'], FILTER_VALIDATE_BOOL) : null;

    return new self(
      name: $string('name'),
      email: null !== $string('email') ? mb_strtolower($string('email')) : null,
      password: array_key_exists('password', $body) && '' !== (string)$body['password'] ? (string)$body['password'] : null,
      isAdmin: $isAdmin,
      isActive: array_key_exists('is_active', $body) ? (bool)filter_var($body['is_active'], FILTER_VALIDATE_BOOL) : null,
      permissions: $permissions,
      projects: isset($body['projects']) && is_array($body['projects']) ? array_values(array_map('strval', $body['projects'])) : null,
      roles: isset($body['roles']) && is_array($body['roles']) ? array_values(array_map('strval', $body['roles'])) : null,
    );
  }
}
