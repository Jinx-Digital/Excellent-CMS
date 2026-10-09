<?php

declare(strict_types=1);

namespace App\Api\Controller\Content;

use App\Application\Media\ImageTransform;
use App\Application\Media\MediaUrlSigner;
use App\Infrastructure\Media\ImageVariants;
use App\Infrastructure\Media\MediaStorage;
use App\Infrastructure\Media\MediaStorages;
use App\Repository\MediaRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * GET /media/<path> - the files of the local folder and of private buckets, served by PHP so they
 * can be protected: a file used by a record of a public entity is open to everyone, every other one
 * needs the signed address the API hands out with the records (?expires=…&signature=…).
 *
 * Files of public buckets are served by the bucket and only come here to be transformed.
 *
 * Images can be asked for in another size and format (?w=400&h=300&fit=cover&format=webp, see
 * ImageTransform) - each variant is made once and then served from runtime/media-variants.
 */
final class MediaFileController
{
  private const YEAR = 31536000;

  public function __construct(
    private MediaRepository $media,
    private MediaStorages $storages,
    private MediaUrlSigner $signer,
    private ImageVariants $variants,
    private ResponseFactoryInterface $responses,
    private StreamFactoryInterface $streams,
  ) {
  }

  public function file(#[RouteArgument('path')] string $path, ServerRequestInterface $request): ResponseInterface
  {
    // The path is the one stored with the file ("<project>/<year>/<month>/<id>.<ext>"), nothing else
    $row = $this->media->find(pathinfo($path, PATHINFO_FILENAME));
    $storage = null !== $row && (string)$row['path'] === $path ? $this->storages->find((string)$row['disk']) : null;
    $query = $request->getQueryParams();
    try {
      $transform = ImageTransform::fromQuery($query, $this->variants->maxSize);
    } catch (\InvalidArgumentException $e) {
      return $this->status(400, $e->getMessage());
    }
    if (null === $storage || (null === $transform && !$storage->servedByCms())) {
      return $this->status(404);
    }

    $signed = $this->signer->verify($path, $query['expires'] ?? null, $query['signature'] ?? null);
    if (!$signed && !$this->media->isPublic((string)$row['id'])) {
      return $this->status(403);
    }

    if (null !== $transform) {
      return $this->variant($row, $storage, $transform, $signed, $query, $request);
    }

    $etag = '"'.$row['id'].'"';
    $headers = [
      'Content-Type' => (string)$row['mime_type'],
      'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode((string)$row['name']),
      'X-Content-Type-Options' => 'nosniff',
      // SVG may contain scripts: opened directly they run in a sandbox without scripts (in <img> they never run)
      ...('image/svg+xml' === (string)$row['mime_type'] ? ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox"] : []),
      'ETag' => $etag,
      'Last-Modified' => gmdate('D, d M Y H:i:s', (int)strtotime((string)$row['created_at'])).' GMT',
      'Accept-Ranges' => 'bytes',
      // Files never change: public ones are cached for good, protected ones until the signature expires
      'Cache-Control' => $signed ? 'private, max-age='.max(0, (int)$query['expires'] - time()) : 'public, max-age='.self::YEAR.', immutable',
    ];

    if (in_array($etag, array_map('trim', explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
      return $this->withHeaders($this->responses->createResponse(304), $headers);
    }

    $stream = $storage->read($path);
    if (null === $stream) {
      return $this->status(404);
    }
    $size = (int)$row['size'];

    // One range ("bytes=100-199", "bytes=100-", "bytes=-500") - players seek in audio and video
    if (1 === preg_match('/^bytes=(\d*)-(\d*)$/', trim($request->getHeaderLine('Range')), $range) && ('' !== $range[1] || '' !== $range[2])) {
      [$start, $end] = '' === $range[1]
        ? [max(0, $size - (int)$range[2]), $size - 1]
        : [(int)$range[1], '' === $range[2] ? $size - 1 : min((int)$range[2], $size - 1)];
      if ($start > $end || $start >= $size) {
        fclose($stream);
        return $this->withHeaders($this->status(416), ['Content-Range' => 'bytes */'.$size]);
      }
      $body = (string)stream_get_contents($stream, $end - $start + 1, $start);
      fclose($stream);
      return $this->withHeaders($this->responses->createResponse(206)->withBody($this->streams->createStream($body)), $headers + [
        'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $size),
        'Content-Length' => (string)strlen($body),
      ]);
    }

    return $this->withHeaders($this->responses->createResponse(200)->withBody($this->streams->createStreamFromResource($stream)), $headers + ['Content-Length' => (string)$size]);
  }

  /**
   * @param array<string, mixed> $row
   * @param array<string, mixed> $query
   */
  private function variant(array $row, MediaStorage $storage, ImageTransform $transform, bool $signed, array $query, ServerRequestInterface $request): ResponseInterface
  {
    $mimeType = (string)$row['mime_type'];
    if (!ImageVariants::canRead($mimeType)) {
      return $this->status(415, 'Only images (jpg, png, gif, webp) can be transformed.');
    }
    $key = $transform->key($mimeType);
    $etag = '"'.$row['id'].'-'.substr(md5($key), 0, 12).'"';
    $format = $transform->formatFor($mimeType);
    $headers = [
      'Content-Type' => ImageTransform::FORMATS[$format],
      'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode(pathinfo((string)$row['name'], PATHINFO_FILENAME).'.'.$format),
      'X-Content-Type-Options' => 'nosniff',
      'ETag' => $etag,
      'Last-Modified' => gmdate('D, d M Y H:i:s', (int)strtotime((string)$row['created_at'])).' GMT',
      'Cache-Control' => $signed ? 'private, max-age='.max(0, (int)$query['expires'] - time()) : 'public, max-age='.self::YEAR.', immutable',
    ];
    if (in_array($etag, array_map('trim', explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
      return $this->withHeaders($this->responses->createResponse(304), $headers);
    }
    try {
      $file = $this->variants->get((string)$row['id'], $mimeType, $transform, static fn() => $storage->read((string)$row['path']));
    } catch (\RuntimeException $e) {
      return $this->status(422, $e->getMessage());
    }
    return $this->withHeaders($this->responses->createResponse(200)->withBody($this->streams->createStreamFromFile($file)), $headers + ['Content-Length' => (string)filesize($file)]);
  }

  private function status(int $code, ?string $message = null): ResponseInterface
  {
    $response = $this->responses->createResponse($code)->withHeader('Cache-Control', 'no-store');
    if (null === $message) {
      return $response;
    }
    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withBody($this->streams->createStream($message));
  }

  /**
   * @param array<string, string> $headers
   */
  private function withHeaders(ResponseInterface $response, array $headers): ResponseInterface
  {
    foreach ($headers as $name => $value) {
      $response = $response->withHeader($name, $value);
    }
    return $response;
  }
}
