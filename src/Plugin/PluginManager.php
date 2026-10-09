<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Application\Service\EnvVariables;
use App\Application\Service\SecretBox;
use App\Repository\PluginRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use FilesystemIterator;
use Psr\Http\Message\UploadedFileInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use Yiisoft\Http\Status;
use ZipArchive;

/**
 * Plugins: folders in plugins/ (PLUGINS_DIR) with a plugin.json, uploaded as ZIP in the admin app.
 * No Composer, no build: their classes are loaded from the folder, their admin UI is described in
 * JSON (settings, panels).
 *
 *   upload      ZIP checked and unpacked - a new version replaces the files of the old one
 *   install     migrations of the plugin up, then it is installed but inactive
 *   activate    only a flag: from the next request on its register() runs (steps, panels …)
 *   deactivate  flag off - its data and tables stay
 *   uninstall   its migrations down (backwards), its settings and files removed
 *
 * plugin.json: {name, label, description, version, author, url, class, autoload: {"Vendor\\Ns\\": "src/"},
 * bootstrap: "vendor/autoload.php" (libraries the plugin brings along, installed when it was packed),
 * settings: [{key, label, kind: text|secret|textarea|select|bool, required, default, options, help}]}
 *
 * Safe mode: a plugin that fails while starting is switched off; the error shows in the admin app.
 * PLUGINS_ENABLED=false turns off all plugins (and uploads).
 */
final class PluginManager
{
  private const NAME = '/^[a-z][a-z0-9-]{1,39}$/';
  /** Files a plugin may contain */
  public const EXTENSIONS = ['php', 'json', 'js', 'mjs', 'css', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'md', 'txt', 'html', 'twig'];
  private const MAX_SIZE = 20 * 1024 * 1024;

  /** @var array<string, PluginRegistry> project id (or "*": no project) => what its active plugins add */
  private array $registries = [];
  /** The registry whose field and storage types are registered right now */
  private ?string $pushed = null;
  /** @var array<string, PluginContext> */
  private array $contexts = [];
  /** @var array<string, true> autoloaders registered in this request */
  private array $autoloaded = [];

  public function __construct(
    private string $directory,
    private bool $enabled,
    private PluginRepository $repository,
    private EnvVariables $env,
    private SecretBox $secretBox,
    private ?\App\Application\Event\EventHooks $events = null,
    private ?\App\Application\Service\CurrentProject $currentProject = null,
    private ?\Psr\Log\LoggerInterface $logger = null,
    /** Signing key of the CMS (JWT_SECRET) - plugins get a key of their own derived from it */
    private string $secret = '',
  ) {
  }

  public function isEnabled(): bool
  {
    return $this->enabled;
  }

  /**
   * What the plugins active in the project of the request add (global ones: in every project) -
   * they are started on first use. Plugins active elsewhere only bring their storage types.
   * Without a project (console): every active plugin.
   */
  public function registry(): PluginRegistry
  {
    $projectId = null !== $this->currentProject && $this->currentProject->isScoped() ? $this->currentProject->id() : null;
    $key = $projectId ?? '*';
    if (!isset($this->registries[$key])) {
      $this->registries[$key] = $this->boot($projectId);
    }
    $registry = $this->registries[$key];
    if ($this->pushed !== $key) {
      $this->pushed = $key;
      // Field types of the plugins
      \App\Domain\Schema\CustomFieldTypes::reset();
      foreach ($registry->fieldTypes() as $type) {
        \App\Domain\Schema\CustomFieldTypes::add($type);
      }
      // Storage types of the plugins for the storages of the admin app
      \App\Infrastructure\Media\StorageTypes::reset();
      foreach ($registry->storageTypes() as $type => $definition) {
        \App\Infrastructure\Media\StorageTypes::extend($type, ['label' => $definition['label'], 'group' => 'server', 'fields' => $definition['fields'], 'plugin' => $definition['plugin'], 'description' => $definition['description']], $definition['factory']);
      }
    }
    return $registry;
  }

  /**
   * Scope "global" in plugin.json: a pure admin plugin, active in all projects at once.
   */
  public function isGlobal(string $name): bool
  {
    try {
      return 'global' === ($this->manifest($name)['scope'] ?? 'project');
    } catch (Throwable) {
      return false;
    }
  }

  /**
   * Is the plugin active in the project (a global one, or in this one)?
   */
  public function isActiveIn(string $name, ?string $projectId): bool
  {
    $row = $this->rows()[$name] ?? null;
    return null !== $row && ((bool)$row['is_active'] || (null !== $projectId && in_array($projectId, self::decodeList($row['projects'] ?? null), true)));
  }

  private function boot(?string $projectId): PluginRegistry
  {
    $registry = new PluginRegistry();
    if (!$this->enabled) {
      return $registry;
    }
    try {
      $rows = $this->repository->all();
    } catch (Throwable) {
      // Before the migration: no table yet
      return $registry;
    }
    $started = [];
    foreach ($this->inOrder($rows) as $name => $row) {
      $projects = self::decodeList($row['projects'] ?? null);
      if (!$row['is_active'] && [] === $projects) {
        continue;
      }
      // The plugins it needs first - without them it stays off
      $requires = [];
      try {
        $requires = $this->manifest((string)$name)['requires'];
      } catch (Throwable) {
      }
      if ([] !== array_diff(array_keys($requires), $started)) {
        continue;
      }
      try {
        $this->start((string)$name, $row, $registry);
        $started[] = (string)$name;
      } catch (Throwable $e) {
        // Safe mode: switched off, the reason stays for the admin app
        $registry->forget((string)$name);
        $this->repository->update((string)$name, ['is_active' => false, 'projects' => null, 'error' => self::describe($e)]);
        continue;
      }
      $here = null === $projectId || $row['is_active'] || in_array($projectId, $projects, true);
      // … and in the project: the plugins it needs active there too
      foreach (array_keys($requires) as $required) {
        $here = $here && (null === $projectId || $this->isActiveIn($required, $projectId));
      }
      if (!$here) {
        $registry->onlyStorage((string)$name);
      }
    }
    return $registry;
  }

  /**
   * The rows so that the plugins a plugin needs come before it (cycles: in the order they are).
   *
   * @param array<string, array> $rows
   * @return array<string, array>
   */
  private function inOrder(array $rows): array
  {
    $ordered = [];
    $visit = function (string $name, array $path) use (&$visit, &$ordered, $rows): void {
      if (isset($ordered[$name]) || !isset($rows[$name]) || in_array($name, $path, true)) {
        return;
      }
      try {
        $requires = $this->manifest($name)['requires'];
      } catch (Throwable) {
        $requires = [];
      }
      foreach (array_keys($requires) as $required) {
        $visit($required, [...$path, $name]);
      }
      $ordered[$name] = $rows[$name];
    };
    foreach (array_keys($rows) as $name) {
      $visit((string)$name, []);
    }
    return $ordered;
  }

  /**
   * Installed plugins that need this one.
   *
   * @return list<string>
   */
  private function dependents(string $name): array
  {
    $result = [];
    foreach (array_keys($this->rows()) as $other) {
      try {
        if (isset($this->manifest((string)$other)['requires'][$name])) {
          $result[] = (string)$other;
        }
      } catch (Throwable) {
      }
    }
    return $result;
  }

  /**
   * Refuses to switch a plugin off where active plugins need it.
   *
   * @param list<string>|null $projects the projects it leaves - null: all
   */
  private function assertNotNeeded(string $name, ?array $projects): void
  {
    $needed = [];
    foreach ($this->dependents($name) as $other) {
      $row = $this->rows()[$other] ?? null;
      $theirs = self::decodeList($row['projects'] ?? null);
      if (null !== $row && ((bool)$row['is_active'] || (null === $projects ? [] !== $theirs : [] !== array_intersect($theirs, $projects)))) {
        $needed[] = $other;
      }
    }
    if ([] !== $needed) {
      throw new UserFacingException(I18n::t('These plugins need it there: {plugins}. Deactivate them first.', ['plugins' => implode(', ', $needed)]), Status::CONFLICT, 'plugin_required');
    }
  }

  /**
   * "requires" of plugin.json: {"forms": "^1.0"} or ["forms"] - name => constraint ("*": any).
   *
   * @return array<string, string>
   */
  private static function requirements(mixed $value): array
  {
    $result = [];
    foreach (is_array($value) ? $value : [] as $key => $constraint) {
      [$name, $constraint] = is_int($key) ? [(string)$constraint, '*'] : [(string)$key, trim((string)$constraint) ?: '*'];
      if (1 === preg_match(self::NAME, $name)) {
        $result[$name] = $constraint;
      }
    }
    return $result;
  }

  private static function fits(string $version, string $constraint): bool
  {
    if ('*' === $constraint) {
      return true;
    }
    try {
      return \Composer\Semver\Semver::satisfies($version, $constraint);
    } catch (Throwable) {
      return false;
    }
  }

  /**
   * Started anew on next use (after activating, deactivating …).
   */
  private function reboot(): void
  {
    $this->registries = [];
    $this->pushed = null;
  }

  public function context(string $name): ?PluginContext
  {
    $this->registry();
    return $this->contexts[$name] ?? null;
  }

  /**
   * Every plugin: the ones in the folder and the installed ones (also if their folder is gone).
   *
   * @return list<array<string, mixed>>
   */
  public function all(): array
  {
    $rows = $this->rows();
    $names = array_unique([...array_keys($rows), ...$this->folders()]);
    sort($names);
    return array_values(array_map(fn(string $name): array => $this->present($name, $rows[$name] ?? null), $names));
  }

  public function get(string $name): array
  {
    if (!in_array($name, $this->folders(), true) && null === $this->repository->find($name)) {
      throw UserFacingException::notFound(I18n::t('There is no plugin "{name}".', ['name' => $name]));
    }
    return $this->present($name, $this->repository->find($name));
  }

  /**
   * A ZIP of a plugin: checked, unpacked into plugins/<name>/ (replacing an older version's files).
   */
  public function upload(UploadedFileInterface $file): array
  {
    $this->assertEnabled();
    if (UPLOAD_ERR_OK !== $file->getError() || $file->getSize() > self::MAX_SIZE) {
      throw ValidationException::field('file', I18n::t('Please upload a ZIP file of at most {size} MB.', ['size' => self::MAX_SIZE / 1024 / 1024]));
    }
    $temporary = tempnam(sys_get_temp_dir(), 'plugin');
    $file->moveTo($temporary);
    $zip = new ZipArchive();
    if (true !== $zip->open($temporary)) {
      @unlink($temporary);
      throw ValidationException::field('file', I18n::t('This is no ZIP file.'));
    }
    try {
      // plugin.json at the top - or in the one folder the ZIP has
      $root = null;
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string)$zip->getNameIndex($i);
        if (1 === preg_match('#^([^/]+/)?plugin\.json$#', $entry, $match)) {
          $root = $match[1] ?? '';
          break;
        }
      }
      if (null === $root) {
        throw ValidationException::field('file', I18n::t('The ZIP has no plugin.json.'));
      }
      $manifest = self::decodeManifest((string)$zip->getFromName($root.'plugin.json'));
      $files = [];
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string)$zip->getNameIndex($i);
        if ('' === $entry || str_ends_with($entry, '/') || !str_starts_with($entry, $root) || str_starts_with($entry, '__MACOSX/') || str_contains($entry, '/.')) {
          continue;
        }
        $relative = substr($entry, strlen($root));
        if (str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
          throw ValidationException::field('file', I18n::t('The ZIP contains a path that is not allowed: {path}', ['path' => $entry]));
        }
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true) && !in_array(basename($relative), ['LICENSE', 'README', 'CHANGELOG'], true)) {
          throw ValidationException::field('file', I18n::t('Files of this type are not allowed in plugins: {path}', ['path' => $relative]));
        }
        $files[$relative] = $i;
      }
      $target = $this->path($manifest['name']);
      $staging = $target.'.upload-'.bin2hex(random_bytes(4));
      foreach ($files as $relative => $index) {
        $path = $staging.'/'.$relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
          throw new UserFacingException(I18n::t('The plugin folder is not writable: {path}', ['path' => $this->directory]), Status::INTERNAL_SERVER_ERROR, 'plugins_not_writable');
        }
        file_put_contents($path, (string)$zip->getFromIndex($index));
      }
      // The new files in place of the old ones
      if (is_dir($target)) {
        self::remove($target);
      }
      rename($staging, $target);
    } finally {
      $zip->close();
      @unlink($temporary);
    }
    return $this->get($manifest['name']);
  }

  /**
   * Installs the plugin of the folder - or updates it to the version of its files (new migrations).
   */
  public function install(string $name): array
  {
    $this->assertEnabled();
    $manifest = $this->manifest($name);
    // Plugins it needs: installed, in a fitting version
    $missing = [];
    foreach ($manifest['requires'] as $required => $constraint) {
      $row = $this->repository->find($required);
      if (null === $row || !self::fits((string)$row['version'], $constraint)) {
        $missing[] = $required.('*' !== $constraint ? ' '.$constraint : '');
      }
    }
    if ([] !== $missing) {
      throw new UserFacingException(I18n::t('Please install first: {plugins}.', ['plugins' => implode(', ', $missing)]), Status::CONFLICT, 'plugin_requires');
    }
    $row = $this->repository->find($name);
    $applied = null !== $row ? self::decodeList($row['migrations'] ?? null) : [];
    $this->autoload($name, $manifest);
    // Every migration that ran is noted at once - an uninstall reverts exactly those
    $save = function (array $applied, ?string $version) use ($name, &$row): void {
      $values = ['migrations' => json_encode($applied)] + (null !== $version ? ['version' => $version] : []);
      if (null === $row) {
        $this->repository->insert(['name' => $name, 'is_active' => false, 'version' => $version ?? '0'] + $values);
        $row = $this->repository->find($name);
      } else {
        $this->repository->update($name, $values);
      }
    };
    foreach ($this->migrations($name) as $migration => $file) {
      if (in_array($migration, $applied, true)) {
        continue;
      }
      try {
        self::loadMigration($file)->up($this->repository->db());
      } catch (Throwable $e) {
        $save($applied, null);
        throw new UserFacingException(I18n::t('The migration {migration} of the plugin failed: {error}', ['migration' => $migration, 'error' => self::describe($e)]), Status::UNPROCESSABLE_ENTITY, 'plugin_migration');
      }
      $applied[] = $migration;
      $save($applied, null);
    }
    $save($applied, $manifest['version']);
    return $this->get($name);
  }

  /**
   * Plugins of the scope "global" (plugin.json - pure admin plugins, e.g. storage types): in every
   * project. Plugins of projects (the default): in these projects (ids) - or, without, in the
   * current one too.
   *
   * @param list<string>|null $projects
   */
  public function activate(string $name, ?array $projects = null): array
  {
    $this->assertEnabled();
    $row = $this->installedRow($name);
    if ($row['version'] !== ($this->manifest($name)['version'])) {
      throw new UserFacingException(I18n::t('The files are of another version - please update the plugin first.'), Status::CONFLICT, 'plugin_outdated');
    }
    // Started once on trial: a plugin that fails is not switched on
    try {
      $this->start($name, $row, new PluginRegistry());
    } catch (Throwable $e) {
      $this->repository->update($name, ['error' => self::describe($e)]);
      throw new UserFacingException(I18n::t('The plugin could not be started: {error}', ['error' => self::describe($e)]), Status::UNPROCESSABLE_ENTITY, 'plugin_failed');
    }
    $requires = $this->manifest($name)['requires'];
    if ($this->isGlobal($name)) {
      // A global plugin needs global ones (active everywhere)
      $missing = array_filter(array_keys($requires), fn(string $required): bool => !(bool)($this->rows()[$required]['is_active'] ?? false));
      if ([] !== $missing) {
        throw new UserFacingException(I18n::t('Please activate first: {plugins}.', ['plugins' => implode(', ', $missing)]), Status::CONFLICT, 'plugin_requires');
      }
      $this->repository->update($name, ['is_active' => true, 'projects' => null, 'error' => null]);
      $this->reboot();
      return $this->get($name);
    }
    if (null === $projects) {
      $current = $this->currentProject?->find();
      if (null === $current || $current->isGlobal) {
        throw new \App\Shared\Exception\ValidationException(['projects' => [I18n::t('Please choose at least one project (not "Global").')]]);
      }
      $projects = [...self::decodeList($row['projects'] ?? null), $current->id];
    }
    $projects = array_values(array_unique(array_filter(array_map('strval', $projects))));
    $known = [] !== $projects ? $this->repository->db()->createQuery()->from('project')->select('id')->where(['id' => $projects, 'is_global' => false])->column() : [];
    if ([] === $projects || count($known) !== count($projects)) {
      throw new \App\Shared\Exception\ValidationException(['projects' => [I18n::t('Please choose at least one project (not "Global").')]]);
    }
    // The plugins it needs: active in these projects too
    $missing = [];
    foreach (array_keys($requires) as $required) {
      foreach ($projects as $projectId) {
        if (!$this->isActiveIn($required, $projectId)) {
          $missing[$required] = $required;
        }
      }
    }
    if ([] !== $missing) {
      throw new UserFacingException(I18n::t('Please activate first in these projects: {plugins}.', ['plugins' => implode(', ', $missing)]), Status::CONFLICT, 'plugin_requires');
    }
    // Plugins that need this one keep their projects
    $this->assertNotNeeded($name, array_values(array_diff(self::decodeList($row['projects'] ?? null), $projects)));
    $this->repository->update($name, ['is_active' => false, 'projects' => json_encode($projects), 'error' => null]);
    $this->reboot();
    return $this->get($name);
  }

  public function deactivate(string $name): array
  {
    $row = $this->installedRow($name);
    $this->assertNotNeeded($name, (bool)$row['is_active'] ? null : self::decodeList($row['projects'] ?? null));
    $this->repository->update($name, ['is_active' => false, 'projects' => null]);
    $this->reboot();
    return $this->get($name);
  }

  /**
   * Migrations of the plugin backwards, its row and its files removed.
   */
  public function uninstall(string $name): void
  {
    $this->assertEnabled();
    $row = $this->installedRow($name);
    $dependents = $this->dependents($name);
    if ([] !== $dependents) {
      throw new UserFacingException(I18n::t('These plugins need it: {plugins}. Uninstall them first.', ['plugins' => implode(', ', $dependents)]), Status::CONFLICT, 'plugin_required');
    }
    $this->repository->update($name, ['is_active' => false, 'projects' => null]);
    $migrations = $this->migrations($name);
    if ([] !== $migrations) {
      $this->autoload($name, $this->manifest($name));
    }
    foreach (array_reverse(self::decodeList($row['migrations'] ?? null)) as $migration) {
      if (isset($migrations[$migration])) {
        try {
          self::loadMigration($migrations[$migration])->down($this->repository->db());
        } catch (Throwable $e) {
          throw new UserFacingException(I18n::t('The migration {migration} of the plugin could not be reverted: {error}', ['migration' => $migration, 'error' => self::describe($e)]), Status::UNPROCESSABLE_ENTITY, 'plugin_migration');
        }
      }
    }
    $this->repository->delete($name);
    // What it created stays - unprotected now
    $db = $this->repository->db();
    foreach (['entity' => 'entity_id', 'field_group' => 'group_id'] as $table => $column) {
      $ids = $db->createQuery()->from($table)->select('id')->where(['managed_by' => $name])->column();
      if ([] !== $ids) {
        $db->createCommand()->update('entity_field', ['is_locked' => false], [$column => $ids])->execute();
        $db->createCommand()->update($table, ['managed_by' => null], ['id' => $ids])->execute();
      }
    }
    if (is_dir($this->path($name))) {
      self::remove($this->path($name));
    }
    $this->reboot();
  }

  /**
   * Settings as in StorageService: a value or $NAME, secrets stored encrypted and never returned
   * ("" keeps a stored secret, null removes it).
   */
  public function saveSettings(string $name, array $input): array
  {
    $row = $this->installedRow($name);
    $fields = array_column($this->manifest($name)['settings'], null, 'key');
    $stored = self::decodeObject($row['settings'] ?? null);
    $errors = [];
    foreach ($fields as $key => $field) {
      if (!array_key_exists($key, $input)) {
        continue;
      }
      $value = $input[$key];
      $kind = $field['kind'] ?? 'text';
      if ('bool' === $kind) {
        $stored[$key] = (bool)$value;
        continue;
      }
      if (null === $value) {
        unset($stored[$key]);
        continue;
      }
      $value = trim((string)$value);
      if ('secret' === $kind && '' === $value) {
        continue;
      }
      if ('' === $value) {
        unset($stored[$key]);
      } elseif (null !== ($problem = $this->env->check($value))) {
        $errors[$key][] = $problem;
      } elseif ('select' === $kind && !in_array($value, array_map(static fn(array $o): string => (string)$o['value'], (array)($field['options'] ?? [])), true)) {
        $errors[$key][] = I18n::t('Please choose one of the options.');
      } elseif ('secret' === $kind && null === EnvVariables::reference($value)) {
        if (!$this->secretBox->isAvailable()) {
          $errors[$key][] = I18n::t('Values can only be stored with APP_ENCRYPTION_KEY in the .env - use $NAME for a .env variable instead.');
        } else {
          $stored[$key] = $this->secretBox->encrypt(str_starts_with($value, '$$') ? substr($value, 1) : $value);
        }
      } else {
        $stored[$key] = $value;
      }
    }
    foreach ($fields as $key => $field) {
      if (($field['required'] ?? false) && self::applies($field, $stored, $fields) && !isset($stored[$key]) && !isset($errors[$key]) && 'bool' !== ($field['kind'] ?? 'text')) {
        $errors[$key][] = I18n::t('Required.');
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    $this->repository->update($name, ['settings' => json_encode((object)$stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $this->registry = null;
    $this->contexts = [];
    return $this->get($name);
  }

  /**
   * A file of the plugin's assets/ (scripts, styles, images of its web components) - only of
   * active plugins, only inside assets/.
   */
  public function asset(string $name, string $path): ?string
  {
    if (1 !== preg_match(self::NAME, $name)) {
      return null;
    }
    $row = $this->repository->find($name);
    if (null === $row || (!$row['is_active'] && [] === self::decodeList($row['projects'] ?? null))) {
      return null;
    }
    $base = realpath($this->path($name).'/assets');
    $file = false !== $base ? realpath($base.'/'.$path) : false;
    return false !== $file && str_starts_with($file, $base.'/') && is_file($file) ? $file : null;
  }

  /**
   * Does a setting apply? "when": {"provider": "google"} - only if the other settings have these
   * values (defaults count).
   *
   * @param array<string, mixed> $stored
   * @param array<string, array> $fields
   */
  private static function applies(array $field, array $stored, array $fields): bool
  {
    foreach ((array)($field['when'] ?? []) as $key => $wanted) {
      $value = $stored[$key] ?? ($fields[$key]['default'] ?? null);
      if (!in_array($value, (array)$wanted, false)) {
        return false;
      }
    }
    return true;
  }

  /**
   * The panels of an active plugin with their tables.
   *
   * @return list<array{key: string, title: string, columns: list<array>, rows: list<array>, error: ?string}>
   */
  public function panels(string $name): array
  {
    $result = [];
    foreach ($this->registry()->panels($name) as $key => $panel) {
      try {
        $data = ($panel['provider'])();
        $result[] = ['key' => $key, 'title' => $panel['title'], 'columns' => array_values((array)($data['columns'] ?? [])), 'rows' => array_values((array)($data['rows'] ?? [])), 'error' => null];
      } catch (Throwable $e) {
        $result[] = ['key' => $key, 'title' => $panel['title'], 'columns' => [], 'rows' => [], 'error' => self::describe($e)];
      }
    }
    return $result;
  }

  // ---------------------------------------------------------------------------------------------

  /**
   * Loads the plugin's classes and calls its register().
   */
  private function start(string $name, array $row, PluginRegistry $registry): void
  {
    $manifest = $this->manifest($name);
    $this->autoload($name, $manifest);
    $class = $manifest['class'];
    if (!class_exists($class)) {
      throw new \RuntimeException(sprintf('The class %s of the plugin does not exist.', $class));
    }
    $plugin = new $class();
    if (!$plugin instanceof PluginInterface) {
      throw new \RuntimeException(sprintf('%s does not implement %s.', $class, PluginInterface::class));
    }
    $events = $this->events;
    $context = new PluginContext($name, $this->path($name), $this->resolveSettings($manifest, $row), $this->repository->db(),
      static function (string $source, string $action, string $projectId, string $id, array $data, ?string $target) use ($events): void {
        $events?->data($source, $projectId, $action, $id, $data, null, $target);
      }, $this->logger, '' !== $this->secret ? hash_hmac('sha256', 'plugin:'.$name, $this->secret, true) : null);
    $this->contexts[$name] = $context;
    $plugin->register($registry->for($name), $context);
  }

  /**
   * Settings with $NAME read from the .env and secrets decrypted; defaults of plugin.json.
   *
   * @return array<string, mixed>
   */
  private function resolveSettings(array $manifest, array $row): array
  {
    $stored = self::decodeObject($row['settings'] ?? null);
    $settings = [];
    foreach ($manifest['settings'] as $field) {
      $key = (string)$field['key'];
      $value = $stored[$key] ?? ($field['default'] ?? null);
      $settings[$key] = match (true) {
        'bool' === ($field['kind'] ?? 'text') => (bool)$value,
        null === $value => null,
        'secret' === ($field['kind'] ?? 'text') && isset($stored[$key]) && null === EnvVariables::reference((string)$value) => $this->secretBox->decrypt((string)$value),
        default => $this->env->resolve((string)$value),
      };
    }
    return $settings;
  }

  /**
   * Classes of the plugin from its folder (autoload of plugin.json) - no Composer needed.
   */
  private function autoload(string $name, array $manifest): void
  {
    if (isset($this->autoloaded[$name])) {
      return;
    }
    $this->autoloaded[$name] = true;
    $base = realpath($this->path($name)) ?: $this->path($name);
    // Libraries of the plugin (its own Composer autoloader, made when the ZIP was built)
    if ('' !== $manifest['bootstrap']) {
      $file = realpath($base.'/'.$manifest['bootstrap']);
      if (false === $file || !str_starts_with($file, $base.'/')) {
        throw new \RuntimeException(sprintf('The bootstrap file %s of the plugin is missing.', $manifest['bootstrap']));
      }
      require_once $file;
    }
    foreach ($manifest['autoload'] as $prefix => $folder) {
      $folder = rtrim($base.'/'.trim((string)$folder, '/'), '/');
      spl_autoload_register(static function (string $class) use ($prefix, $folder, $base): void {
        if (!str_starts_with($class, (string)$prefix)) {
          return;
        }
        $file = $folder.'/'.str_replace('\\', '/', substr($class, strlen((string)$prefix))).'.php';
        $real = realpath($file);
        // Only files inside the plugin's folder
        if (false !== $real && str_starts_with($real, $base.'/')) {
          require_once $real;
        }
      });
    }
  }

  /**
   * @return array<string, string> migration name => file, in order
   */
  private function migrations(string $name): array
  {
    $files = glob($this->path($name).'/migrations/*.php') ?: [];
    sort($files);
    $result = [];
    foreach ($files as $file) {
      $result[basename($file, '.php')] = $file;
    }
    return $result;
  }

  private static function loadMigration(string $file): PluginMigration
  {
    $migration = require $file;
    if (!$migration instanceof PluginMigration) {
      throw new \RuntimeException(sprintf('%s does not return a %s.', basename($file), PluginMigration::class));
    }
    return $migration;
  }

  /**
   * plugin.json of a folder, checked.
   */
  private function manifest(string $name): array
  {
    $file = $this->path($name).'/plugin.json';
    if (!is_file($file)) {
      throw new UserFacingException(I18n::t('The files of the plugin "{name}" are missing - please upload it again.', ['name' => $name]), Status::CONFLICT, 'plugin_files_missing');
    }
    $manifest = self::decodeManifest((string)file_get_contents($file));
    if ($manifest['name'] !== $name) {
      throw new UserFacingException(I18n::t('The plugin.json in "{name}" names another plugin.', ['name' => $name]), Status::CONFLICT, 'plugin_invalid');
    }
    return $manifest;
  }

  /**
   * @return array{name: string, label: string, description: string, version: string, author: string, url: string, class: string, autoload: array<string, string>, bootstrap: string, scope: string, requires: array<string, string>, settings: list<array>}
   */
  private static function decodeManifest(string $json): array
  {
    $data = json_decode($json, true);
    $problems = [];
    if (!is_array($data)) {
      throw ValidationException::field('file', I18n::t('plugin.json is no valid JSON.'));
    }
    if (1 !== preg_match(self::NAME, (string)($data['name'] ?? ''))) {
      $problems[] = I18n::t('"name": lower case letters, digits and -, 2 to 40 characters.');
    }
    if ('' === trim((string)($data['version'] ?? ''))) {
      $problems[] = I18n::t('"version" is missing.');
    }
    $autoload = is_array($data['autoload'] ?? null) ? $data['autoload'] : [];
    $class = (string)($data['class'] ?? '');
    if ('' === $class || [] === array_filter(array_keys($autoload), static fn($prefix): bool => '' !== (string)$prefix && str_starts_with($class, (string)$prefix))) {
      $problems[] = I18n::t('"class" must be a class of the "autoload" of the plugin.');
    }
    if (str_contains((string)($data['bootstrap'] ?? ''), '..')) {
      $problems[] = I18n::t('"bootstrap" may not leave the folder of the plugin.');
    }
    foreach ($autoload as $prefix => $folder) {
      if (str_starts_with((string)$prefix, 'App\\') || str_contains((string)$folder, '..')) {
        $problems[] = I18n::t('"autoload" may not use the namespace App\\ or leave the folder of the plugin.');
      }
    }
    if ([] !== $problems) {
      throw new ValidationException(['file' => $problems], I18n::t('The plugin.json is not valid.'));
    }
    $settings = [];
    foreach ((array)($data['settings'] ?? []) as $field) {
      if (is_array($field) && 1 === preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string)($field['key'] ?? ''))) {
        $settings[] = $field + ['label' => $field['key'], 'kind' => 'text'];
      }
    }
    return [
      'name' => (string)$data['name'],
      'label' => (string)($data['label'] ?? $data['name']),
      'description' => (string)($data['description'] ?? ''),
      'version' => trim((string)$data['version']),
      'author' => (string)($data['author'] ?? ''),
      'url' => (string)($data['url'] ?? ''),
      'class' => $class,
      'autoload' => array_map('strval', $autoload),
      'bootstrap' => ltrim((string)($data['bootstrap'] ?? ''), '/'),
      // global: a pure admin plugin, active in every project - else activated per project
      'scope' => 'global' === ($data['scope'] ?? null) ? 'global' : 'project',
      // Other plugins it needs: name => version constraint (as in Composer, "^1.0", "*")
      'requires' => self::requirements($data['requires'] ?? []),
      'settings' => $settings,
    ];
  }

  private function present(string $name, ?array $row): array
  {
    $manifest = null;
    $problem = null;
    try {
      $manifest = $this->manifest($name);
    } catch (Throwable $e) {
      $problem = $e->getMessage();
    }
    $stored = self::decodeObject($row['settings'] ?? null);
    $settings = [];
    $secrets = [];
    foreach ($manifest['settings'] ?? [] as $field) {
      $key = (string)$field['key'];
      $value = $stored[$key] ?? null;
      if ('secret' === ($field['kind'] ?? 'text')) {
        $env = EnvVariables::reference(is_string($value) ? $value : null);
        $secrets[$key] = ['env' => $env, 'stored' => null !== $value && null === $env, 'set' => null !== $value && (null === $env || '' !== $this->env->resolve((string)$value))];
      } else {
        $settings[$key] = 'bool' === ($field['kind'] ?? 'text') ? (bool)($value ?? $field['default'] ?? false) : (string)($value ?? $field['default'] ?? '');
      }
    }
    $projects = null !== $row ? self::decodeList($row['projects'] ?? null) : [];
    $active = null !== $row && ((bool)$row['is_active'] || [] !== $projects);
    return [
      'name' => $name,
      'label' => $manifest['label'] ?? $name,
      'description' => $manifest['description'] ?? '',
      'author' => $manifest['author'] ?? '',
      'url' => $manifest['url'] ?? '',
      // The version of the files - and the installed one (differs: update waiting)
      'version' => $manifest['version'] ?? null,
      'installed_version' => $row['version'] ?? null,
      'installed' => null !== $row,
      'active' => $active,
      // global: an admin plugin, in every project; project: active in "projects"
      'scope' => 'global' === ($manifest['scope'] ?? 'project') ? 'global' : 'project',
      'requires' => (object)($manifest['requires'] ?? []),
      'required_by' => $this->dependents($name),
      'projects' => $projects,
      'update' => null !== $row && null !== $manifest && $row['version'] !== $manifest['version'],
      'error' => $row['error'] ?? null,
      'problem' => $problem,
      'migrations' => null !== $row ? self::decodeList($row['migrations'] ?? null) : [],
      'pending_migrations' => array_values(array_diff(array_keys($this->migrations($name)), null !== $row ? self::decodeList($row['migrations'] ?? null) : [])),
      'fields' => $manifest['settings'] ?? [],
      'settings' => (object)$settings,
      'secrets' => (object)$secrets,
      'steps' => $active ? array_values(array_map(static fn(PluginStep $s): array => $s->toArray(), array_filter($this->registry()->steps(), static fn(PluginStep $s): bool => $s->plugin === $name))) : [],
    ];
  }

  /**
   * @return array<string, array>
   */
  private function rows(): array
  {
    try {
      return $this->repository->all();
    } catch (Throwable) {
      return [];
    }
  }

  /**
   * @return list<string> names of the folders with a plugin.json
   */
  private function folders(): array
  {
    $names = [];
    foreach (glob($this->directory.'/*/plugin.json') ?: [] as $file) {
      $name = basename(dirname($file));
      if (1 === preg_match(self::NAME, $name)) {
        $names[] = $name;
      }
    }
    return $names;
  }

  private function installedRow(string $name): array
  {
    return $this->repository->find($name) ?? throw new UserFacingException(I18n::t('The plugin "{name}" is not installed.', ['name' => $name]), Status::CONFLICT, 'plugin_not_installed');
  }

  private function path(string $name): string
  {
    if (1 !== preg_match(self::NAME, $name)) {
      throw UserFacingException::notFound(I18n::t('There is no plugin "{name}".', ['name' => $name]));
    }
    return rtrim($this->directory, '/').'/'.$name;
  }

  private function assertEnabled(): void
  {
    if (!$this->enabled) {
      throw new UserFacingException(I18n::t('Plugins are switched off on this server (PLUGINS_ENABLED).'), Status::FORBIDDEN, 'plugins_disabled');
    }
  }

  private static function describe(Throwable $e): string
  {
    return mb_substr(sprintf('%s: %s', (new \ReflectionClass($e))->getShortName(), $e->getMessage()), 0, 1000);
  }

  /**
   * @return list<string>
   */
  private static function decodeList(mixed $json): array
  {
    $list = is_string($json) ? json_decode($json, true) : null;
    return is_array($list) ? array_values(array_map('strval', $list)) : [];
  }

  /**
   * @return array<string, mixed>
   */
  private static function decodeObject(mixed $json): array
  {
    $data = is_string($json) ? json_decode($json, true) : null;
    return is_array($data) ? $data : [];
  }

  private static function remove(string $folder): void
  {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
      $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($folder);
  }
}
