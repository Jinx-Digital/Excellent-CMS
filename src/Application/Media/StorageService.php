<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Application\Service\EnvVariables;
use App\Application\Service\SecretBox;
use App\Infrastructure\Media\MediaStorages;
use App\Infrastructure\Media\StorageDefinitions;
use App\Infrastructure\Media\StorageTypes;
use App\Repository\StorageRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;
use Throwable;
use Yiisoft\Http\Status;

/**
 * Storages for uploads defined in the admin app (all types of StorageTypes). Every text setting is a
 * value or a $NAME .env variable, read when the storage is used. Secrets (keys, passwords) likewise:
 * "$NAME", or a value that is stored encrypted (SecretBox, APP_ENCRYPTION_KEY) and never returned -
 * the API only tells whether one is stored. Sent back empty, a stored secret stays; null removes it.
 *
 * The name is what files (media.disk) and projects (media_storage) refer to - it can not change, and
 * a storage in use can not be deleted.
 */
final class StorageService
{
  public function __construct(
    private StorageRepository $repository,
    private StorageDefinitions $definitions,
    private MediaStorages $storages,
    private EnvVariables $env,
    private SecretBox $secretBox,
    private ?\App\Plugin\PluginManager $plugins = null,
  ) {
    // Storage types of plugins (FTP, SFTP …) are known once the plugins started
    $this->plugins?->registry();
  }

  /**
   * The types and their settings for the form.
   */
  public function types(): array
  {
    $types = [];
    foreach (StorageTypes::all() as $type => $definition) {
      $types[] = ['type' => $type] + $definition;
    }
    return ['types' => $types, 'encryption' => $this->secretBox->isAvailable()];
  }

  /**
   * All storages: the ones of the configuration (read-only, no settings) and of the admin app.
   */
  public function list(): array
  {
    $rows = array_column($this->repository->all(), null, 'name');
    $result = [];
    foreach ($this->storages->options() as $option) {
      $row = $rows[$option['name']] ?? null;
      $result[] = null !== $row && 'admin' === $option['source']
        ? $this->present($row) + ['default' => $option['default']]
        : $option + ['id' => null, 'editable' => false];
    }
    return $result;
  }

  public function get(string $id): array
  {
    return $this->present($this->find($id));
  }

  public function create(array $data): array
  {
    $name = strtolower(trim((string)($data['name'] ?? '')));
    $type = strtolower(trim((string)($data['type'] ?? '')));
    $errors = [];
    if (1 !== preg_match('/^[a-z][a-z0-9_]{0,19}$/', $name)) {
      $errors['name'][] = I18n::t('Lower case letters, digits and _, starting with a letter (at most 20 characters).');
    } elseif ($this->storages->has($name)) {
      $errors['name'][] = I18n::t('There is a storage "{name}" already.', ['name' => $name]);
    }
    if (!StorageTypes::has($type)) {
      $errors['type'][] = I18n::t('Pick a type.');
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }

    $row = ['id' => Id::new(), 'name' => $name, 'type' => $type, 'label' => '', 'is_private' => true, 'settings' => '{}'];
    $row = $this->apply($row, $data);
    $this->repository->insert($row);
    $this->storages->reset();
    return $this->get($row['id']);
  }

  public function update(string $id, array $data): array
  {
    $row = $this->find($id);
    if (isset($data['name']) && $data['name'] !== $row['name']) {
      throw ValidationException::field('name', I18n::t('The name of a storage can not change - files refer to it.'));
    }
    $type = strtolower(trim((string)($data['type'] ?? $row['type'])));
    if (!StorageTypes::has($type)) {
      throw ValidationException::field('type', I18n::t('Pick a type.'));
    }
    if ($type !== $row['type']) {
      // Another type: the settings of the old one do not fit
      $row['type'] = $type;
      $row['settings'] = '{}';
    }
    $values = $this->apply($row, $data);
    unset($values['id']);
    $this->repository->update($id, $values);
    $this->storages->reset();
    return $this->get($id);
  }

  public function delete(string $id): void
  {
    $row = $this->find($id);
    $usage = $this->repository->usage((string)$row['name']);
    if ($usage['media'] > 0 || $usage['projects'] > 0) {
      throw new UserFacingException(I18n::t('The storage is in use ({media} files, {projects} projects) and can not be deleted.', $usage), Status::CONFLICT, 'storage_in_use');
    }
    $this->repository->delete($id);
    $this->storages->reset();
  }

  /**
   * Writes, reads and deletes a small file with the saved settings.
   *
   * @return array{ok: bool, error: ?string}
   */
  public function test(string $id): array
  {
    $row = $this->find($id);
    $path = '.excellent-check-'.bin2hex(random_bytes(4)).'.txt';
    try {
      $storage = $this->storages->build((string)$row['name'], $this->definitions->config($row));
      $content = 'Excellent CMS '.date('c');
      $stream = fopen('php://memory', 'r+');
      fwrite($stream, $content);
      rewind($stream);
      $storage->put($path, $stream, 'text/plain');
      $read = $storage->read($path);
      $back = is_resource($read) ? stream_get_contents($read) : null;
      $storage->delete($path);
      if ($back !== $content) {
        return ['ok' => false, 'error' => I18n::t('The file could be written but not read back.')];
      }
      return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
      return ['ok' => false, 'error' => self::message($e)];
    }
  }

  private function find(string $id): array
  {
    return $this->repository->find($id) ?? throw new UserFacingException(I18n::t('Storage not found.'), Status::NOT_FOUND, 'not_found');
  }

  /**
   * For the API: texts as entered, secrets only whether and how they are set.
   */
  private function present(array $row): array
  {
    $type = (string)$row['type'];
    $stored = StorageDefinitions::settings($row);
    $settings = [];
    $secrets = [];
    foreach (StorageTypes::fields($type) as $key => $field) {
      $value = $stored[$key] ?? null;
      if ('bool' === $field['kind']) {
        $settings[$key] = (bool)($value ?? $field['default'] ?? false);
      } elseif (StorageTypes::isSecret($field['kind'])) {
        $env = EnvVariables::reference(is_string($value) ? $value : null);
        $secrets[$key] = [
          'env' => $env,
          'stored' => null !== $value && null === $env,
          'set' => null !== $value && (null === $env || '' !== $this->env->resolve((string)$value)),
        ];
      } else {
        $settings[$key] = (string)($value ?? '');
      }
    }
    return [
      'id' => (string)$row['id'],
      'name' => (string)$row['name'],
      'label' => '' !== (string)$row['label'] ? (string)$row['label'] : (string)$row['name'],
      'own_label' => (string)$row['label'],
      'type' => $type,
      'private' => 'local' === $type || (bool)$row['is_private'],
      'settings' => $settings,
      'secrets' => $secrets,
      'usage' => $this->repository->usage((string)$row['name']),
      'source' => 'admin',
      'editable' => true,
    ];
  }

  /**
   * Label, private and the settings of the input on the row.
   */
  private function apply(array $row, array $data): array
  {
    $type = (string)$row['type'];
    $errors = [];
    if (array_key_exists('label', $data)) {
      $row['label'] = mb_substr(trim((string)$data['label']), 0, 100);
    }
    if (array_key_exists('private', $data)) {
      $row['is_private'] = 'local' === $type || (bool)$data['private'];
    }

    $settings = array_intersect_key(StorageDefinitions::settings($row), StorageTypes::fields($type));
    $input = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $secretInput = is_array($data['secrets'] ?? null) ? $data['secrets'] : [];
    foreach (StorageTypes::fields($type) as $key => $field) {
      if ('bool' === $field['kind']) {
        if (array_key_exists($key, $input)) {
          $settings[$key] = (bool)$input[$key];
        }
        continue;
      }
      if (StorageTypes::isSecret($field['kind'])) {
        if (!array_key_exists($key, $secretInput)) {
          continue;
        }
        $value = $secretInput[$key];
        if (null === $value) {
          unset($settings[$key]);
          continue;
        }
        $value = trim((string)$value);
        if ('' === $value) {
          // Empty: the stored one stays
          continue;
        }
        if (null !== ($problem = $this->env->check($value))) {
          $errors['secrets.'.$key][] = $problem;
          continue;
        }
        if (null !== EnvVariables::reference($value)) {
          $settings[$key] = $value;
          continue;
        }
        if (!$this->secretBox->isAvailable()) {
          $errors['secrets.'.$key][] = I18n::t('Values can only be stored with APP_ENCRYPTION_KEY in the .env - use $NAME for a .env variable instead.');
          continue;
        }
        $settings[$key] = $this->secretBox->encrypt(str_starts_with($value, '$$') ? substr($value, 1) : $value);
        continue;
      }
      if (!array_key_exists($key, $input)) {
        continue;
      }
      $value = trim((string)$input[$key]);
      if ('' === $value) {
        unset($settings[$key]);
      } elseif (null !== ($problem = $this->env->check($value))) {
        $errors['settings.'.$key][] = $problem;
      } else {
        $settings[$key] = $value;
      }
    }

    foreach (StorageTypes::fields($type) as $key => $field) {
      if (($field['required'] ?? false) && !isset($settings[$key]) && !isset($errors[(StorageTypes::isSecret($field['kind']) ? 'secrets.' : 'settings.').$key])) {
        $errors[(StorageTypes::isSecret($field['kind']) ? 'secrets.' : 'settings.').$key][] = I18n::t('Required.');
      }
    }
    // Public storages without an address of their own: servers and R2 need the URL of the files
    if (!$row['is_private'] && ('r2' === $type || null !== StorageTypes::factory($type)) && !isset($settings['url'])) {
      $errors['settings.url'][] = I18n::t('A public storage needs the URL its files are served at - or make it private.');
    }
    if ('local' === $type && isset($settings['path']) && 1 === preg_match('#(^|/)\.\.(/|$)#', (string)$settings['path'])) {
      $errors['settings.path'][] = I18n::t('The folder can not contain "..".');
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }

    $row['settings'] = json_encode((object)$settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $row;
  }

  /**
   * A short message of an adapter error (the first one in the chain that says something).
   */
  private static function message(Throwable $e): string
  {
    $messages = [];
    for ($current = $e; null !== $current; $current = $current->getPrevious()) {
      $message = trim($current->getMessage());
      if ('' !== $message && !in_array($message, $messages, true)) {
        $messages[] = $message;
      }
    }
    return mb_substr(implode(' – ', $messages), 0, 500);
  }
}
