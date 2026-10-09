<?php

declare(strict_types=1);

namespace App\Application\Project;

use App\Application\Schema\SchemaService;
use App\Application\Event\EventHooks;
use App\Domain\Project\Project;
use App\Infrastructure\Media\ImageVariants;
use App\Infrastructure\Media\MediaStorages;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;
use App\Shared\Naming;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Projects (admins only). The table prefix can only change while a project has no entities -
 * renaming all tables of a running project at once is not worth the risk.
 */
final class ProjectService
{
  /** First path segments of the API that are no project */
  /** Names of the API's own paths - no project may be called like that (see config/common/routes.php) */
  public const RESERVED = ['auth', 'entities', 'imports', 'media', 'admin', 'oauth', 'projects', 'content', 'api', 'v1', 'global', 'plugins'];

  public function __construct(
    private ProjectRepository $projects,
    private ConnectionInterface $db,
    private MediaStorages $storages,
    private SchemaService $schema,
    private EntityRepository $entities,
    private EventHooks $events,
    private ImageVariants $variants,
  ) {
  }

  public function get(string $idOrSlug): Project
  {
    return $this->projects->find($idOrSlug) ?? throw UserFacingException::notFound(I18n::t('This project does not exist.'));
  }

  public function create(array $data): Project
  {
    $project = new Project(id: Id::new(), slug: '', name: '', tablePrefix: '', sortOrder: $this->projects->nextSortOrder());
    $this->apply($project, $data, true);
    $this->projects->save($project, true);
    return $this->get($project->id);
  }

  public function update(string $id, array $data): Project
  {
    $project = $this->get($id);
    $oldLanguages = $project->languages;
    $this->apply($project, $data, false);
    // Translation columns first: if that fails, the project keeps its old languages
    if ($project->languages !== $oldLanguages) {
      $entities = array_values(array_filter($this->entities->allProjects(), static fn($entity): bool => $entity->projectId === $project->id));
      $this->schema->changeLanguages($entities, $oldLanguages, $project->languages);
    }
    $this->projects->save($project, false);
    $this->entities->reset();
    return $this->get($project->id);
  }

  /**
   * Variables of a project ({url} ...): name, translatable, value and its translations.
   */
  public function updateVariables(string $id, array $list): Project
  {
    $project = $this->get($id);
    $errors = [];
    $variables = [];
    foreach (array_values($list) as $index => $data) {
      $data = (array)$data;
      $name = trim((string)($data['name'] ?? ''));
      if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
        $errors["variables.{$index}.name"][] = I18n::t('Please enter a name of lower case letters, digits and _ (starting with a letter).');
      } elseif (isset($variables[$name])) {
        $errors["variables.{$index}.name"][] = I18n::t('"{name}" is there twice.', ['name' => $name]);
      }
      $value = self::text($data['value'] ?? null);
      $translatable = (bool)filter_var($data['translatable'] ?? false, FILTER_VALIDATE_BOOL) && [] !== $project->otherLanguages();
      $translations = [];
      foreach ($translatable ? $project->otherLanguages() : [] as $language) {
        $translations[$language] = self::text($data['translations'][$language] ?? null);
      }
      foreach (array_merge([$value], $translations) as $text) {
        if (null !== $text && mb_strlen($text) > 2000) {
          $errors["variables.{$index}.value"][] = I18n::t('At most 2000 characters.');
          break;
        }
      }
      $variables[$name] = ['name' => $name, 'translatable' => $translatable, 'value' => $value, 'translations' => $translations];
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    $before = array_column($project->variables, null, 'name');
    $project->variables = array_values($variables);
    $this->projects->save($project, false);
    // Events on variables: each one added, changed or removed
    foreach ($variables as $name => $variable) {
      if (!isset($before[$name])) {
        $this->events->data('variables', $project->id, 'create', $name, $variable);
      } elseif (json_encode($before[$name]) !== json_encode($variable)) {
        $this->events->data('variables', $project->id, 'update', $name, $variable, $before[$name]);
      }
    }
    foreach (array_diff_key($before, $variables) as $name => $variable) {
      $this->events->data('variables', $project->id, 'delete', (string)$name, $variable);
    }
    return $this->get($project->id);
  }

  private static function text(mixed $value): ?string
  {
    $text = is_scalar($value) ? trim((string)$value) : '';
    return '' === $text ? null : $text;
  }

  /**
   * Only empty projects (no entities). API clients go with it, unused uploads too.
   */
  public function delete(string $id): void
  {
    $project = $this->get($id);
    if ($project->isGlobal) {
      throw UserFacingException::conflict(I18n::t('The area "Global" cannot be deleted.'), 'global_project');
    }
    if (count(array_filter($this->projects->all(), static fn(Project $other): bool => !$other->isGlobal)) <= 1) {
      throw UserFacingException::conflict(I18n::t('The last project cannot be deleted.'), 'last_project');
    }
    if ($this->projects->countEntities($project->id) > 0) {
      throw UserFacingException::conflict(I18n::t('"{project}" still has entities. Please delete them first.', ['project' => $project->name]), 'project_in_use');
    }
    foreach ($this->db->createQuery()->from('media')->where(['project_id' => $project->id])->all() as $file) {
      $this->storages->find((string)$file['disk'])?->delete((string)$file['path']);
      $this->variants->forget((string)$file['id']);
    }
    $this->db->createCommand()->delete('media', ['project_id' => $project->id])->execute();
    // Fields of its groups first: a group may use another one (Columns › Column) - the cascade of the
    // database cannot sort that out
    $groups = $this->db->createQuery()->select('id')->from('field_group')->where(['project_id' => $project->id])->column();
    if ([] !== $groups) {
      $this->db->createCommand()->delete('entity_field', ['group_id' => $groups])->execute();
    }
    $this->projects->delete($project->id);
  }

  private function apply(Project $project, array $data, bool $isNew): void
  {
    $errors = [];
    if ($isNew || array_key_exists('name', $data)) {
      $project->name = trim((string)($data['name'] ?? ''));
      if ('' === $project->name || mb_strlen($project->name) > 100) {
        $errors['name'][] = I18n::t('Please enter a name (at most 100 characters).');
      }
    }
    if ($project->isGlobal && array_key_exists('slug', $data) && trim((string)$data['slug']) !== $project->slug) {
      $errors['slug'][] = I18n::t('The area "Global" keeps its name.');
    } elseif ($isNew || array_key_exists('slug', $data)) {
      $project->slug = trim((string)($data['slug'] ?? ''));
      if (!Naming::isValid($project->slug, 40)) {
        $errors['slug'][] = I18n::t('Please enter a technical name of lower case letters, digits and _ (at most 40 characters).');
      } elseif (in_array($project->slug, self::RESERVED, true)) {
        $errors['slug'][] = I18n::t('"{name}" is reserved.', ['name' => $project->slug]);
      } elseif ($this->projects->slugExists($project->slug, $isNew ? null : $project->id)) {
        $errors['slug'][] = I18n::t('A project "{project}" exists already.', ['project' => $project->slug]);
      }
    }
    if ($isNew || array_key_exists('table_prefix', $data)) {
      $prefix = trim((string)($data['table_prefix'] ?? ''));
      if ($prefix !== $project->tablePrefix) {
        if (!$isNew && $this->projects->countEntities($project->id) > 0) {
          $errors['table_prefix'][] = I18n::t('The table prefix can only be changed while the project has no entities.');
        } elseif (1 !== preg_match('/^[a-z][a-z0-9]{0,18}_$/', $prefix)) {
          $errors['table_prefix'][] = I18n::t('Please enter lower case letters and digits ending with _ (e.g. shop_, at most 20 characters).');
        } elseif ($this->projects->prefixExists($prefix, $isNew ? null : $project->id)) {
          $errors['table_prefix'][] = I18n::t('Another project has the prefix "{prefix}" already.', ['prefix' => $prefix]);
        }
        $project->tablePrefix = $prefix;
      }
    }
    if (array_key_exists('languages', $data)) {
      $languages = array_values(array_unique(array_filter(array_map(
        static fn($code): string => strtolower(trim((string)$code)),
        is_array($data['languages']) ? $data['languages'] : explode(',', (string)$data['languages'])
      ))));
      $invalid = array_filter($languages, static fn(string $code): bool => 1 !== preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,4})?$/', $code));
      if ([] !== $invalid) {
        $errors['languages'][] = I18n::t('Invalid language codes: {codes} (e.g. de, en, de-at).', ['codes' => implode(', ', $invalid)]);
      }
      $project->languages = $languages;
    }
    if (array_key_exists('media_storage', $data)) {
      // Only new uploads go there - existing files stay in the storage they were written to
      $storage = trim((string)$data['media_storage']);
      if ('' !== $storage && !$this->storages->has($storage)) {
        $errors['media_storage'][] = I18n::t('There is no storage "{name}".', ['name' => $storage]);
      }
      $project->mediaStorage = '' === $storage ? null : $storage;
    }
    if (array_key_exists('description', $data)) {
      $description = trim((string)$data['description']);
      $project->description = '' === $description ? null : $description;
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
  }
}
