<?php

declare(strict_types=1);

namespace App\Presentation\Api\Shared\ResponseFactory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Raw (non-JSON) responses: PDFs, CSV exports, photos.
 */
final readonly class FileResponder
{
  public function __construct(
    private ResponseFactoryInterface $responseFactory,
    private StreamFactoryInterface $streamFactory,
  ) {}

  public function content(string $content, string $filename, string $mime, bool $inline = false): ResponseInterface
  {
    return $this->responseFactory->createResponse()
      ->withHeader('Content-Type', $mime)
      ->withHeader('Content-Disposition', ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes($filename, '"\\').'"')
      ->withHeader('Cache-Control', 'private, no-store')
      ->withBody($this->streamFactory->createStream($content));
  }

  public function file(string $path, string $filename, string $mime): ResponseInterface
  {
    return $this->responseFactory->createResponse()
      ->withHeader('Content-Type', $mime)
      ->withHeader('Content-Disposition', 'inline; filename="'.addcslashes($filename, '"\\').'"')
      ->withHeader('Cache-Control', 'private, max-age=3600')
      ->withBody($this->streamFactory->createStreamFromFile($path));
  }
}
