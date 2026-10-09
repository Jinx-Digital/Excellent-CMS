<?php

declare(strict_types=1);

namespace App\Plugin;

use HttpSoft\Message\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers of plugin routes that are no JSON - e.g. a sitemap (XML) or a text file. A route returns it
 * instead of an array:
 *
 *     $registry->publicRoute('GET', 'sitemap.xml', fn(PluginRequest $request) => PluginResponse::xml($xml));
 */
final class PluginResponse
{
  /**
   * @param array<string, string> $headers
   */
  public static function text(string $body, string $contentType = 'text/plain; charset=utf-8', int $status = 200, array $headers = []): ResponseInterface
  {
    $response = (new ResponseFactory())->createResponse($status)->withHeader('Content-Type', $contentType)->withHeader('X-Content-Type-Options', 'nosniff');
    foreach ($headers as $name => $value) {
      $response = $response->withHeader($name, $value);
    }
    $response->getBody()->write($body);
    return $response;
  }

  /**
   * @param array<string, string> $headers
   */
  public static function xml(string $body, int $status = 200, array $headers = []): ResponseInterface
  {
    return self::text($body, 'application/xml; charset=utf-8', $status, $headers);
  }
}
