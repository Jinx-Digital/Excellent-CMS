<?php

declare(strict_types=1);

namespace App\Api\Input;

use Psr\Http\Message\ServerRequestInterface;

final readonly class ListRequest
{
  public function __construct(
    public string $s = '',
    public int $page = 1,
    public int $limit = 50,
    public ?string $sort = null,
    public ?string $order = null,
    public array $filter = [],
  ) {}

  public const MAX_LIMIT = 200;

  public static function from(ServerRequestInterface $request, int $defaultLimit = 50): self
  {
    $params = $request->getQueryParams();
    $s = (string)($params['s'] ?? '');
    $page = max(1, (int)($params['page'] ?? 1));
    $limit = min(self::MAX_LIMIT, max(1, (int)($params['limit'] ?? $defaultLimit)));
    $sort = isset($params['sort']) && '' !== trim((string)$params['sort']) ? (string)$params['sort'] : null;
    $order = isset($params['order']) && '' !== trim((string)$params['order']) ? (string)$params['order'] : null;
    $filter = isset($params['filter']) && is_array($params['filter']) ? $params['filter'] : [];

    return new self(
      s: $s,
      page: $page,
      limit: $limit,
      sort: $sort,
      order: $order,
      filter: $filter,
    );
  }
}
