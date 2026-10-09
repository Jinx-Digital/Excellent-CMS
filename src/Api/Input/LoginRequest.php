<?php

declare(strict_types=1);

namespace App\Api\Input;

use Psr\Http\Message\ServerRequestInterface;

final readonly class LoginRequest
{
  public function __construct(
    public string $email = '',
    public string $password = '',
  ) {}

  public static function from(ServerRequestInterface $request): self
  {
    $body = (array)($request->getParsedBody() ?? []);
    return new self(
      email: trim((string)($body['email'] ?? $body['login'] ?? '')),
      password: (string)($body['password'] ?? ''),
    );
  }
}
