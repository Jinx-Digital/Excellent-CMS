<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use InvalidArgumentException;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Builds one storage of the uploads (see MediaStorages) from its config:
 *
 *   local  the folder storage/ (outside public/), served by the CMS (MediaFileController)
 *   s3     AWS S3 or any S3 compatible service (endpoint for MinIO, Wasabi, Spaces ...)
 *   r2     Cloudflare R2: S3 with region "auto"
 *   …      the S3 compatible services of StorageTypes - and the types of plugins (FTP, SFTP, WebDAV …)
 *
 * A bucket (or server) is either public - its files are served by the bucket (url: bucket URL or CDN) and are
 * not protected - or private: the CMS reads the files and serves them like local ones.
 */
final class MediaStorageFactory
{
  /**
   * @param array{type: string, private?: bool, url?: string, local_path?: string, http_client?: \Symfony\Contracts\HttpClient\HttpClientInterface, s3?: array{bucket: string, region: string, endpoint: string, key: string, secret: string, path_style: bool, prefix: string, acl?: string, account_id?: string, jurisdiction?: string}, options?: array<string, mixed>} $config
   *        options: the settings of the types of plugins (see StorageTypes::extend())
   * @param string $cmsUrl where the CMS serves files (MediaFileController), e.g. /media
   */
  public static function create(string $name, array $config, string $cmsUrl, string $rootPath): MediaStorage
  {
    $type = strtolower(trim($config['type']));
    if (!StorageTypes::has($type)) {
      throw new InvalidArgumentException(sprintf('Storage "%s": type "%s" is invalid. Valid types are "%s".', $name, $type, implode('", "', array_keys(StorageTypes::all()))));
    }
    if ('local' === $type) {
      $path = $config['local_path'] ?? 'storage';
      $root = rtrim(str_starts_with($path, '/') ? $path : $rootPath.'/'.$path, '/');
      // The folder is created with the first upload, not when the app starts. Only PHP reads it,
      // so the folders may stay private (Flysystem's default 0700).
      $adapter = new LocalFilesystemAdapter($root, lazyRootCreation: true);
      return new FlysystemMediaStorage(new Filesystem($adapter, ['public_url' => $cmsUrl]), $name, $type, true);
    }

    if (!in_array($type, StorageTypes::S3, true)) {
      return self::server($name, $type, $config, $cmsUrl);
    }

    $s3 = $config['s3'] ?? [];
    foreach (['bucket', 'key', 'secret'] as $required) {
      if ('' === ($s3[$required] ?? '')) {
        throw new InvalidArgumentException(sprintf('Storage "%s" (%s) needs a %s.', $name, $type, $required));
      }
    }
    $region = '' !== ($s3['region'] ?? '') ? $s3['region'] : ('r2' === $type ? 'auto' : 'us-east-1');
    $s3['endpoint'] = StorageTypes::endpoint($type, $s3);
    if ('r2' === $type && '' === $s3['endpoint']) {
      throw new InvalidArgumentException(sprintf('Storage "%s" (r2) needs the account ID or the endpoint https://<account id>.r2.cloudflarestorage.com.', $name));
    }
    if ('' === $s3['endpoint'] && 's3' !== $type) {
      throw new InvalidArgumentException(sprintf('Storage "%s" (%s) needs a %s.', $name, $type, 'minio' === $type ? 'endpoint' : 'region'));
    }
    // MinIO addresses buckets by path
    $s3['path_style'] = ($s3['path_style'] ?? false) || 'minio' === $type;

    $options = [
      'region' => $region,
      'accessKeyId' => $s3['key'],
      'accessKeySecret' => $s3['secret'],
      'pathStyleEndpoint' => ($s3['path_style'] ?? false) ? 'true' : 'false',
      'sendChunkedBody' => 'false',
    ];
    if ('' !== ($s3['endpoint'] ?? '')) {
      $options['endpoint'] = $s3['endpoint'];
    }
    $client = (new AclFreeS3Client($options, null, $config['http_client'] ?? null))->withAcl($s3['acl'] ?? null);
    $prefix = trim($s3['prefix'] ?? '', '/');
    $private = (bool)($config['private'] ?? false);
    if ($private) {
      // Served by the CMS: the prefix is part of the bucket path, not of the address
      $publicUrl = $cmsUrl;
    } else {
      $baseUrl = rtrim(self::publicUrl($name, $config['url'] ?? '', $s3, $region, $type), '/');
      $publicUrl = '' !== $prefix ? $baseUrl.'/'.$prefix : $baseUrl;
    }

    return new FlysystemMediaStorage(
      new Filesystem(new AsyncAwsS3Adapter($client, $s3['bucket'], $prefix), ['public_url' => $publicUrl]),
      $name,
      $type,
      $private,
    );
  }

  /**
   * Types of plugins (FTP, SFTP, WebDAV …): private - served by the CMS - or public at their URL (the folder on a web server).
   */
  private static function server(string $name, string $type, array $config, string $cmsUrl): MediaStorage
  {
    $options = $config['options'] ?? [];
    // Types of plugins (FTP, SFTP, WebDAV …): the plugin builds the adapter from the settings
    $factory = StorageTypes::factory($type) ?? throw new InvalidArgumentException(sprintf('Storage "%s": type "%s" is invalid - is its plugin active?', $name, $type));
    $adapter = $factory($name, $options);
    $private = (bool)($config['private'] ?? true);
    $url = rtrim((string)($config['url'] ?? ''), '/');
    if (!$private && '' === $url) {
      throw new InvalidArgumentException(sprintf('Storage "%s" (%s) needs the public URL of its folder - or make it private.', $name, $type));
    }
    return new FlysystemMediaStorage(new Filesystem($adapter, ['public_url' => $private ? $cmsUrl : $url]), $name, $type, $private);
  }

  /**
   * The configured URL if set. Otherwise the bucket URL - only right if the bucket is publicly readable.
   */
  private static function publicUrl(string $name, string $url, array $s3, string $region, string $type): string
  {
    if ('' !== $url) {
      return $url;
    }
    if ('r2' === $type) {
      throw new InvalidArgumentException(sprintf('Storage "%s" (r2) needs the public URL of the bucket (r2.dev or custom domain) - or make it private.', $name));
    }
    if ('' === ($s3['endpoint'] ?? '')) {
      return sprintf('https://%s.s3.%s.amazonaws.com', $s3['bucket'], $region);
    }
    return rtrim($s3['endpoint'], '/').'/'.$s3['bucket'];
  }
}
