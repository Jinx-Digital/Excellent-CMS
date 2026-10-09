<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Application\Service\EnvVariables;
use App\Application\Service\SecretBox;
use App\Repository\StorageRepository;
use Throwable;

/**
 * The storages of the admin app (table `storage`) as configs for MediaStorages. Their settings are
 * resolved when a storage is used - not before: $NAME .env variables are read then, secrets
 * decrypted then (see StorageService for how they are stored).
 */
final class StorageDefinitions
{
  public function __construct(
    private StorageRepository $repository,
    private EnvVariables $env,
    private SecretBox $secretBox,
    private ?\App\Plugin\PluginManager $plugins = null,
  ) {
  }

  /**
   * name => type, label, private and "resolve" (builds the config of MediaStorageFactory).
   *
   * @return array<string, array{type: string, label: string, private: bool, resolve: \Closure}>
   */
  public function configs(): array
  {
    // Storage types of plugins (FTP, SFTP …) are known once the plugins started
    $this->plugins?->registry();
    try {
      $rows = $this->repository->all();
    } catch (Throwable) {
      // Before the migration: no table yet
      return [];
    }
    $configs = [];
    foreach ($rows as $row) {
      $configs[(string)$row['name']] = [
        'type' => (string)$row['type'],
        'label' => (string)$row['label'],
        'private' => 'local' === $row['type'] || (bool)$row['is_private'],
        'resolve' => fn(): array => $this->config($row),
      ];
    }
    return $configs;
  }

  /**
   * The config of MediaStorageFactory for a row - with the settings resolved.
   */
  public function config(array $row): array
  {
    $this->plugins?->registry();
    $type = (string)$row['type'];
    $settings = $this->resolve($type, self::settings($row));
    $text = static fn(string $key): string => (string)($settings[$key] ?? '');
    $config = ['type' => $type, 'label' => (string)$row['label'], 'private' => 'local' === $type || (bool)$row['is_private'], 'url' => $text('url')];

    if ('local' === $type) {
      return $config + ['local_path' => $text('path')];
    }
    if (in_array($type, StorageTypes::S3, true)) {
      return $config + ['s3' => [
        'bucket' => $text('bucket'),
        'region' => $text('region'),
        'endpoint' => $text('endpoint'),
        'account_id' => $text('account_id'),
        'jurisdiction' => $text('jurisdiction'),
        'key' => $text('key'),
        'secret' => $text('secret'),
        'path_style' => (bool)($settings['path_style'] ?? false),
        'prefix' => $text('prefix'),
        'acl' => $text('acl'),
      ]];
    }
    return $config + ['options' => $settings];
  }

  /**
   * The settings as stored: texts as entered, secrets as "$NAME" or encrypted, bools.
   */
  public static function settings(array $row): array
  {
    $settings = json_decode((string)($row['settings'] ?? ''), true);
    return is_array($settings) ? $settings : [];
  }

  /**
   * Values to use: $NAME read from the .env, secrets decrypted.
   */
  private function resolve(string $type, array $settings): array
  {
    $resolved = [];
    foreach (StorageTypes::fields($type) as $key => $field) {
      $value = $settings[$key] ?? null;
      $resolved[$key] = match (true) {
        'bool' === $field['kind'] => (bool)($value ?? $field['default'] ?? false),
        null === $value || '' === $value => '',
        StorageTypes::isSecret($field['kind']) => str_starts_with((string)$value, '$') ? $this->env->resolve((string)$value) : $this->secretBox->decrypt((string)$value),
        default => trim($this->env->resolve((string)$value)),
      };
    }
    return $resolved;
  }
}
