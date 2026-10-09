<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Domain\Project\Project;
use InvalidArgumentException;

/**
 * All storages of uploaded files, by name: "local" (built in, the folder storage/) and the storages
 * of the admin app (StorageDefinitions, read on first use). A project picks one for its new uploads -
 * "local" if it picked none; every file keeps the name of the storage it was written to
 * (media.disk), so older files stay readable when a project switches. Built on first use.
 */
final class MediaStorages
{
  public const LOCAL = 'local';

  /** @var array<string, MediaStorage> */
  private array $built = [];

  /** Built-in and admin app together, see configs() */
  private ?array $all = null;

  /** @var array<string, array> the built-in storage */
  private array $configs;

  /**
   * @param string $localPath folder of "local" (relative to the root)
   * @param string $cmsUrl where the CMS serves files (MEDIA_URL, default /media)
   */
  public function __construct(
    string $localPath,
    private string $cmsUrl,
    private string $rootPath,
    private ?StorageDefinitions $definitions = null,
  ) {
    $this->configs = [self::LOCAL => ['type' => 'local', 'local_path' => $localPath]];
  }

  /**
   * Address of a file at the CMS (MediaFileController) whatever its storage - files of public
   * buckets are transformed there.
   */
  public function cmsUrl(string $path): string
  {
    return rtrim('' !== $this->cmsUrl ? $this->cmsUrl : '/media', '/').'/'.$path;
  }

  public function has(?string $name): bool
  {
    return null !== $name && isset($this->configs()[$name]);
  }

  /**
   * Is the name taken by the built-in storage - not by one of the admin app?
   */
  public function isBuiltIn(string $name): bool
  {
    return isset($this->configs[$name]);
  }

  /**
   * After a storage of the admin app was changed: read them again, build them again.
   */
  public function reset(): void
  {
    $this->all = null;
    $this->built = [];
  }

  /**
   * A storage of a config that is not registered (yet) - e.g. to test the settings.
   */
  public function build(string $name, array $config): MediaStorage
  {
    return MediaStorageFactory::create($name, $config, '' !== $this->cmsUrl ? $this->cmsUrl : '/media', $this->rootPath);
  }

  public function get(string $name): MediaStorage
  {
    if (!$this->has($name)) {
      throw new InvalidArgumentException(sprintf('There is no storage "%s".', $name));
    }
    if (!isset($this->built[$name])) {
      $config = $this->configs()[$name];
      $this->built[$name] = $this->build($name, isset($config['resolve']) ? ($config['resolve'])() : $config);
    }
    return $this->built[$name];
  }

  /**
   * The storage of a file (media.disk) - null if it is not configured any more.
   */
  public function find(?string $name): ?MediaStorage
  {
    return $this->has($name) ? $this->get((string)$name) : null;
  }

  public function defaultName(): string
  {
    return self::LOCAL;
  }

  /**
   * Where new uploads of a project go: its storage, otherwise "local".
   */
  public function forProject(Project $project): MediaStorage
  {
    return $this->get($this->has($project->mediaStorage) ? (string)$project->mediaStorage : self::LOCAL);
  }

  /**
   * Choices for the project settings - without credentials.
   *
   * @return list<array{name: string, label: string, type: string, private: bool, default: bool, source: string}>
   */
  public function options(): array
  {
    $result = [];
    foreach ($this->configs() as $name => $config) {
      $type = strtolower(trim($config['type']));
      $result[] = [
        'name' => (string)$name,
        'label' => (string)($config['label'] ?? '') ?: (string)$name,
        'type' => $type,
        'private' => 'local' === $type || (bool)($config['private'] ?? false),
        'default' => self::LOCAL === $name,
        // builtin: "local", admin: a storage of the admin app
        'source' => isset($config['resolve']) ? 'admin' : 'builtin',
      ];
    }
    return $result;
  }

  /**
   * @return array<string, array> name => config (or type, label, private and "resolve")
   */
  private function configs(): array
  {
    // The built-in name wins
    return $this->all ??= $this->configs + ($this->definitions?->configs() ?? []);
  }
}
