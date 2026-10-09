<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use League\Flysystem\FilesystemOperator;

/**
 * Uploaded files in a Flysystem filesystem (local folder or S3 compatible bucket, see
 * MediaStorageFactory). Files are written once under a new path and never changed, so they can be
 * cached forever.
 */
final class FlysystemMediaStorage implements MediaStorage
{
  public function __construct(
    private FilesystemOperator $filesystem,
    private string $disk,
    private string $type = 'local',
    private bool $servedByCms = true,
  ) {
  }

  public function disk(): string
  {
    return $this->disk;
  }

  public function type(): string
  {
    return $this->type;
  }

  public function servedByCms(): bool
  {
    return $this->servedByCms;
  }

  public function put(string $path, $stream, string $mimeType): void
  {
    $this->filesystem->writeStream($path, $stream, [
      'ContentType' => $mimeType,
      'CacheControl' => 'public, max-age=31536000, immutable',
    ]);
  }

  public function delete(string $path): void
  {
    $this->filesystem->delete($path);
  }

  public function copy(string $from, string $to): void
  {
    $this->filesystem->copy($from, $to);
  }

  public function url(string $path): string
  {
    return $this->filesystem->publicUrl($path);
  }

  public function read(string $path)
  {
    return $this->filesystem->fileExists($path) ? $this->filesystem->readStream($path) : null;
  }
}
