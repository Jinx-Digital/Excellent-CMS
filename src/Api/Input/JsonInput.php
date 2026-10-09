<?php

declare(strict_types=1);

namespace App\Api\Input;

use DomainException;
use Psr\Http\Message\ServerRequestInterface;

class JsonInput
{
  private array $data;

  public function __construct(ServerRequestInterface $request)
  {
    $parsed = $request->getParsedBody();
    if (is_array($parsed) && !empty($parsed)) {
      $this->data = $parsed;
    } else {
      $body = (string)$request->getBody();
      $decoded = json_decode($body, true);
      $this->data = is_array($decoded) ? $decoded : [];
    }
  }

  public static function from(ServerRequestInterface $request): self
  {
    return new self($request);
  }

  public function get(string $key, mixed $default = null): mixed
  {
    return $this->data[$key] ?? $default;
  }

  public function getString(string $key, string $default = ''): string
  {
    return isset($this->data[$key]) ? (string)$this->data[$key] : $default;
  }

  public function getInt(string $key, int $default = 0): int
  {
    return isset($this->data[$key]) ? (int)$this->data[$key] : $default;
  }

  public function getFloat(string $key, float $default = 0.0): float
  {
    return isset($this->data[$key]) ? (float)$this->data[$key] : $default;
  }

  public function getBool(string $key, bool $default = false): bool
  {
    return isset($this->data[$key]) ? (bool)$this->data[$key] : $default;
  }

  public function getArray(string $key, array $default = []): array
  {
    return isset($this->data[$key]) && is_array($this->data[$key]) ? $this->data[$key] : $default;
  }

  public function has(string $key): bool
  {
    return array_key_exists($key, $this->data) && null !== $this->data[$key] && '' !== $this->data[$key];
  }

  /**
   * Validate that all required fields are present in the JSON payload.
   *
   * @param string[] $requiredKeys
   * @throws DomainException
   */
  public function validateRequired(array $requiredKeys): void
  {
    $missing = [];
    foreach ($requiredKeys as $key) {
      if (!$this->has($key)) {
        $missing[] = $key;
      }
    }

    if (!empty($missing)) {
      throw new DomainException('Missing required fields: '.implode(', ', $missing));
    }
  }

  public function toArray(): array
  {
    return $this->data;
  }
}
