<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

/**
 * One place uploaded files are kept (see MediaStorages): the local folder or a bucket. Files are
 * written once under a new path and never changed, so they can be cached forever.
 */
interface MediaStorage
{
  /** Name of the storage, stored with every file (column media.disk): "local", "r2", "kunde_a" ... */
  public function disk(): string;

  /** local, s3 or r2 */
  public function type(): string;

  /**
   * The CMS serves the files (GET /media/<path>, protected ones only signed): the local folder and
   * private buckets. Public buckets serve their files themselves.
   */
  public function servedByCms(): bool;

  /**
   * @param resource $stream
   */
  public function put(string $path, $stream, string $mimeType): void;

  public function delete(string $path): void;

  /** A second file with the same content in this storage (copying records into another project) */
  public function copy(string $from, string $to): void;

  /** Address of a file: the bucket's (public bucket) or the CMS's /media/<path> */
  public function url(string $path): string;

  /**
   * Content of a file, null if it does not exist.
   *
   * @return resource|null
   */
  public function read(string $path);
}
