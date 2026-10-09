<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

/**
 * The kinds of storages for uploads (all Flysystem adapters) and their settings - for the storages
 * of the admin app (see StorageService) and the form there. The storages of the .env use the same
 * types (MEDIA_STORAGE_<NAME>_TYPE).
 *
 *   local         a folder of the server
 *   s3, r2, minio AWS S3, Cloudflare R2, MinIO (own endpoint)
 *   hetzner, digitalocean, wasabi, backblaze, scaleway, ionos
 *                 S3 compatible services: the endpoint follows from the region
 *   …             more types from plugins (e.g. FTP, SFTP, WebDAV: plugin "storage-servers"),
 *                 see PluginRegistry::storageType()
 *
 * Field kinds: text (a value or a $NAME .env variable), secret (the same, stored encrypted and never
 * returned), key (a multi-line secret), bool.
 */
final class StorageTypes
{
  /** S3 compatible services: endpoint by region */
  public const S3_ENDPOINTS = [
    'hetzner' => 'https://{region}.your-objectstorage.com',
    'digitalocean' => 'https://{region}.digitaloceanspaces.com',
    'wasabi' => 'https://s3.{region}.wasabisys.com',
    'backblaze' => 'https://s3.{region}.backblazeb2.com',
    'scaleway' => 'https://s3.{region}.scw.cloud',
    'ionos' => 'https://s3.{region}.ionoscloud.com',
  ];

  /** @var array<string, array{label: string, group: string, fields: list<array>, plugin: string}> types of plugins */
  private static array $extra = [];
  /** @var array<string, \Closure(string, array<string, mixed>): \League\Flysystem\FilesystemAdapter> */
  private static array $factories = [];

  /**
   * A type of a plugin (PluginManager registers the ones of the active plugins).
   *
   * @param \Closure(string, array<string, mixed>): \League\Flysystem\FilesystemAdapter $factory name and resolved settings → adapter
   */
  public static function extend(string $type, array $definition, \Closure $factory): void
  {
    self::$extra[$type] = $definition + ['group' => 'server', 'fields' => []];
    self::$factories[$type] = $factory;
  }

  /**
   * Forgets the types of plugins (before the active ones register again).
   */
  public static function reset(): void
  {
    self::$extra = [];
    self::$factories = [];
  }

  /**
   * The adapter factory of a plugin's type - null for the built-in types.
   *
   * @return (\Closure(string, array<string, mixed>): \League\Flysystem\FilesystemAdapter)|null
   */
  public static function factory(string $type): ?\Closure
  {
    return self::$factories[$type] ?? null;
  }

  /** Types that are S3 underneath */
  public const S3 = ['s3', 'r2', 'minio', 'hetzner', 'digitalocean', 'wasabi', 'backblaze', 'scaleway', 'ionos'];

  /**
   * @return array<string, array{label: string, group: string, fields: list<array{key: string, kind: string, required?: bool, placeholder?: string, default?: mixed}>}>
   */
  public static function all(): array
  {
    $text = static fn(string $key, bool $required = false, string $placeholder = ''): array => ['key' => $key, 'kind' => 'text', 'required' => $required, 'placeholder' => $placeholder];
    $secret = static fn(string $key, bool $required = false, string $kind = 'secret'): array => ['key' => $key, 'kind' => $kind, 'required' => $required];
    $bool = static fn(string $key, bool $default = false): array => ['key' => $key, 'kind' => 'bool', 'default' => $default];
    $keys = [$secret('key', true), $secret('secret', true)];
    $preset = static fn(string $label, string $regions): array => [
      'label' => $label,
      'group' => 's3',
      'fields' => [$text('region', true, $regions), $text('bucket', true), ...$keys, $text('prefix'), $text('url', false, 'https://cdn.example.com')],
    ];

    // Built-in types win over a plugin's type of the same name
    return array_merge(self::$extra, [
      'local' => ['label' => 'Local folder', 'group' => 'server', 'fields' => [$text('path', true, 'storage/archive')]],
      's3' => ['label' => 'Amazon S3', 'group' => 's3', 'fields' => [
        $text('bucket', true), $text('region', false, 'eu-central-1'), $text('endpoint', false, 'https://s3.example.com'), ...$keys,
        $text('prefix'), $bool('path_style'), $text('acl', false, 'public-read'), $text('url', false, 'https://cdn.example.com'),
      ]],
      'r2' => ['label' => 'Cloudflare R2', 'group' => 's3', 'fields' => [
        $text('account_id', true), $text('jurisdiction', false, 'eu'), $text('bucket', true), ...$keys, $text('prefix'),
        $text('url', false, 'https://pub-….r2.dev'),
      ]],
      'hetzner' => $preset('Hetzner Object Storage', 'fsn1, nbg1, hel1'),
      'ionos' => $preset('IONOS S3 Object Storage', 'eu-central-1, eu-central-2, eu-south-2'),
      'digitalocean' => $preset('DigitalOcean Spaces', 'fra1, ams3, nyc3, sfo3, sgp1'),
      'scaleway' => $preset('Scaleway Object Storage', 'fr-par, nl-ams, pl-waw'),
      'wasabi' => $preset('Wasabi', 'eu-central-1, eu-central-2, us-east-1'),
      'backblaze' => $preset('Backblaze B2', 'eu-central-003, us-west-004'),
      'minio' => ['label' => 'MinIO / S3 compatible', 'group' => 's3', 'fields' => [
        $text('endpoint', true, 'https://minio.example.com'), $text('bucket', true), ...$keys, $text('region', false, 'us-east-1'),
        $text('prefix'), $text('url', false, 'https://minio.example.com/bucket'),
      ]],
    ]);
  }

  public static function has(string $type): bool
  {
    return isset(self::all()[$type]);
  }

  /**
   * @return array<string, array{key: string, kind: string, required?: bool, placeholder?: string, default?: mixed}>
   */
  public static function fields(string $type): array
  {
    return array_column(self::all()[$type]['fields'] ?? [], null, 'key');
  }

  public static function isSecret(string $kind): bool
  {
    return in_array($kind, ['secret', 'key'], true);
  }

  /**
   * Endpoint of an S3 type without its own: R2 by account (and jurisdiction), the services by region.
   */
  public static function endpoint(string $type, array $s3): string
  {
    if ('' !== ($s3['endpoint'] ?? '')) {
      return $s3['endpoint'];
    }
    if ('r2' === $type && '' !== ($s3['account_id'] ?? '')) {
      $jurisdiction = trim($s3['jurisdiction'] ?? '');
      return sprintf('https://%s.%sr2.cloudflarestorage.com', $s3['account_id'], '' !== $jurisdiction ? $jurisdiction.'.' : '');
    }
    if (isset(self::S3_ENDPOINTS[$type]) && '' !== ($s3['region'] ?? '')) {
      return str_replace('{region}', $s3['region'], self::S3_ENDPOINTS[$type]);
    }
    return '';
  }
}
