<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Content\RecordPresenter;
use App\Application\Service\CurrentProject;
use App\Repository\EntityRepository;
use App\Repository\EventRepository;
use App\Repository\RecordRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use App\Shared\Id;

/**
 * Events of the current project (admins): create, change, delete, test run, runs and retries.
 */
final class EventService
{
  public const STEPS = ['webhook', 'email', 'create', 'update', 'delete'];
  public const MODES = ['direct', 'queue'];

  public function __construct(
    private EventRepository $events,
    private EntityRepository $entities,
    private CurrentProject $currentProject,
    private ConditionMatcher $conditions,
    private EventRunner $runner,
    private EventQueue $queue,
    private RecordRepository $records,
    private RecordPresenter $presenter,
    private EventDispatcher $dispatcher,
    private \App\Application\Media\MediaLibrary $library,
    private \App\Application\Service\EnvVariables $secrets,
    private ?\App\Plugin\PluginManager $plugins = null,
  ) {
  }

  /**
   * Event sources of the active plugins for the event editor: source, label, actions, targets.
   *
   * @return list<array{source: string, plugin: string, label: string, actions: array<string, string>, targets: ?list<array{value: string, label: string}>, target_label: string}>
   */
  public function pluginSources(): array
  {
    $result = [];
    foreach ($this->plugins?->registry()->eventSources() ?? [] as $source => $definition) {
      $result[] = [
        'source' => $source,
        'plugin' => $definition['plugin'],
        'label' => $definition['label'],
        'actions' => (object)$definition['actions'],
        'targets' => null !== $definition['targets'] ? array_values(($definition['targets'])($this->projectId())) : null,
        'target_label' => $definition['targetLabel'],
      ];
    }
    return $result;
  }

  /**
   * @return list<array>
   */
  public function all(): array
  {
    $last = $this->events->lastRuns($this->projectId());
    return array_map(fn(array $row): array => $this->present($row, $last[(string)$row['id']] ?? null), $this->events->all($this->projectId()));
  }

  public function get(string $id): array
  {
    return $this->present($this->row($id));
  }

  public function create(array $data): array
  {
    $id = Id::new();
    $this->events->insert(['id' => $id, 'project_id' => $this->projectId()] + $this->values($data, null));
    $this->dispatcher->reset();
    return $this->get($id);
  }

  public function update(string $id, array $data): array
  {
    $this->events->update($id, $this->values($data, $this->row($id)));
    $this->dispatcher->reset();
    return $this->get($id);
  }

  public function delete(string $id): void
  {
    $this->events->delete($this->row($id)['id']);
  }

  /**
   * @return list<array>
   */
  public function runs(string $id): array
  {
    return array_map(self::presentRun(...), $this->events->runs($this->row($id)['id']));
  }

  /**
   * A failed run once more - steps that are done are skipped.
   */
  public function retry(string $runId): array
  {
    $run = $this->events->findRun($runId);
    $event = null !== $run ? $this->events->find((string)$run['event_id'], $this->projectId()) : null;
    if (null === $run || null === $event) {
      throw UserFacingException::notFound(I18n::t('This run does not exist (any more).'));
    }
    if ('failed' !== $run['status']) {
      throw new UserFacingException(I18n::t('Only failed runs can be started again.'));
    }
    $this->events->updateRun($runId, ['status' => 'queued', 'error' => null]);
    'queue' === $event['mode'] ? $this->queue->push($runId) : $this->runner->run($runId);
    return self::presentRun((array)$this->events->findRun($runId));
  }

  /**
   * Test run with one record: does the condition match, and what would the steps do - nothing is
   * sent or saved.
   */
  public function test(string $id, string $recordId): array
  {
    $event = $this->row($id);
    $condition = null !== $event['condition'] ? (array)json_decode((string)$event['condition'], true) : null;
    $actions = (array)json_decode((string)$event['actions'], true);
    $pluginSource = ($this->plugins?->registry()->eventSources() ?? [])[(string)($event['source'] ?? '')] ?? null;
    if (null !== $pluginSource) {
      // Plugins: their sample of the item (e.g. a submission by its id) - empty: of the event's target (e.g. its form)
      $data = null !== $pluginSource['sample'] ? ($pluginSource['sample'])('' !== $recordId ? $recordId : (string)($event['source_target'] ?? ''), $this->projectId()) : null;
      if (!is_array($data)) {
        throw UserFacingException::notFound(I18n::t('This record does not exist (any more).'));
      }
      $record = ['id' => $recordId, 'entity' => (string)$event['source'], 'action' => $actions[0] ?? 'create', 'data' => $data, 'old' => null];
      return ['matches' => $this->conditions->matchesData($condition, $data, null), 'steps' => $this->runner->preview($event, [$record])];
    }
    if ('entity' !== ($event['source'] ?? 'entity')) {
      // Media: the file by its id (empty: the latest); variables: the variable by its name (empty: the first)
      if ('' === $recordId) {
        $recordId = 'media' === $event['source']
          ? (string)($this->library->search('', null, null)->select('id')->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])->limit(1)->scalar() ?: '')
          : (string)($this->currentProject->get()->variables[0]['name'] ?? '');
        if ('' === $recordId) {
          throw UserFacingException::notFound(I18n::t('There is nothing to test with yet.'));
        }
      }
      $data = 'media' === $event['source']
        ? $this->library->get($recordId)
        : (array_column($this->currentProject->get()->variables, null, 'name')[$recordId] ?? throw UserFacingException::notFound(I18n::t('There is no variable "{name}".', ['name' => $recordId])));
      $record = ['id' => $recordId, 'entity' => (string)$event['source'], 'action' => $actions[0] ?? 'update', 'data' => $data, 'old' => null];
      return ['matches' => $this->conditions->matchesData($condition, $data, null), 'steps' => $this->runner->preview($event, [$record])];
    }
    $entity = $this->entities->findById((string)$event['entity_id']) ?? throw UserFacingException::notFound(I18n::t('This record does not exist (any more).'));
    // Empty: the record changed last
    if ('' === $recordId) {
      $recordId = (string)($this->records->query($entity)->select('id')->orderBy([new \Yiisoft\Db\Expression\Expression('COALESCE([[updated_at]], [[created_at]]) DESC, [[id]] DESC')])->limit(1)->scalar() ?: '');
      if ('' === $recordId) {
        throw UserFacingException::notFound(I18n::t('There is nothing to test with yet.'));
      }
    }
    $row = $this->records->find($entity, $recordId, true) ?? throw UserFacingException::notFound(I18n::t('This record does not exist (any more).'));
    $data = $this->presenter->withMedia($entity, [$this->presenter->presentForAdmin($entity, $row)])[0];
    $condition = null !== $event['condition'] ? (array)json_decode((string)$event['condition'], true) : null;
    $actions = (array)json_decode((string)$event['actions'], true);
    $record = ['id' => $recordId, 'entity' => $entity->slug, 'action' => $actions[0] ?? 'update', 'data' => $data, 'old' => null];
    return [
      'matches' => $this->conditions->matches($entity, $condition, $recordId, $data, null),
      'steps' => $this->runner->preview($event, [$record]),
    ];
  }

  private function present(array $row, ?array $lastRun = null): array
  {
    $entity = null !== $row['entity_id'] ? $this->entities->findById((string)$row['entity_id']) : null;
    return [
      'id' => (string)$row['id'],
      'name' => (string)$row['name'],
      'source' => (string)($row['source'] ?? 'entity'),
      // Event sources of plugins: limited to this target (e.g. a form), null = all
      'target' => $row['source_target'] ?? null,
      'entity' => $entity?->slug,
      'actions' => array_values((array)json_decode((string)$row['actions'], true)),
      'condition' => null !== $row['condition'] ? json_decode((string)$row['condition']) : null,
      'steps' => json_decode((string)$row['steps']),
      'mode' => (string)$row['mode'],
      'active' => (bool)$row['is_active'],
      'last_run' => null !== $lastRun ? self::presentRun($lastRun) : null,
    ];
  }

  private static function presentRun(array $run): array
  {
    return [
      'id' => (string)$run['id'],
      'status' => (string)$run['status'],
      'depth' => (int)($run['depth'] ?? 0),
      'count' => (int)$run['count'],
      'steps' => array_values((array)json_decode((string)$run['steps'], true)),
      'error' => $run['error'] ?? null,
      'created_at' => $run['created_at'],
      'started_at' => $run['started_at'] ?? null,
      'finished_at' => $run['finished_at'] ?? null,
    ];
  }

  /**
   * @param array|null $existing current row (update) or null (create)
   */
  private function values(array $data, ?array $existing): array
  {
    $errors = [];
    $values = [];
    if (null === $existing || array_key_exists('name', $data)) {
      $values['name'] = trim((string)($data['name'] ?? ''));
      if ('' === $values['name'] || mb_strlen($values['name']) > 100) {
        $errors['name'][] = I18n::t('Please enter a name (at most 100 characters).');
      }
    }
    // What it listens to: records of an entity, the media, the variables - or an event source of a plugin
    $source = (string)($data['source'] ?? $existing['source'] ?? 'entity');
    $pluginSources = $this->plugins?->registry()->eventSources() ?? [];
    $pluginSource = $pluginSources[$source] ?? null;
    if (!in_array($source, EventDispatcher::SOURCES, true) && null === $pluginSource) {
      $errors['source'][] = I18n::t('Please choose: {modes}.', ['modes' => implode(', ', [...EventDispatcher::SOURCES, ...array_keys($pluginSources)])]);
    }
    $values['source'] = $source;
    // Limited to a target of the plugin's source (e.g. one form) - or all
    if (null === $existing || array_key_exists('target', $data) || array_key_exists('source', $data)) {
      $target = trim((string)($data['target'] ?? ($source === ($existing['source'] ?? null) ? ($existing['source_target'] ?? '') : '')));
      if ('' !== $target && null !== ($pluginSource['targets'] ?? null)) {
        $targets = array_column((array)($pluginSource['targets'])($this->projectId()), 'value');
        if (!in_array($target, array_map('strval', $targets), true)) {
          $errors['target'][] = I18n::t('Please choose one of the list.');
        }
      }
      $values['source_target'] = null !== $pluginSource && '' !== $target ? $target : null;
    }
    $entity = 'entity' === $source ? $this->entities->findById((string)($existing['entity_id'] ?? '')) : null;
    if ('entity' !== $source) {
      $values['entity_id'] = null;
    } elseif (null === $existing || array_key_exists('entity', $data) || null === $entity) {
      $value = (string)($data['entity'] ?? '');
      $entity = $this->entities->findBySlug($value) ?? $this->entities->findById($value);
      if (null === $entity) {
        $errors['entity'][] = I18n::t('There is no entity "{entity}".', ['entity' => $value]);
      } else {
        $values['entity_id'] = $entity->id;
      }
    }
    if (null === $existing || array_key_exists('actions', $data) || array_key_exists('source', $data)) {
      $allowed = 'entity' === $source ? EventDispatcher::ACTIONS : (null !== $pluginSource ? array_map('strval', array_keys($pluginSource['actions'])) : EventDispatcher::DATA_ACTIONS);
      $actions = array_values(array_unique(array_map('strval', (array)($data['actions'] ?? json_decode((string)($existing['actions'] ?? '[]'), true)))));
      if ([] === $actions || [] !== array_diff($actions, $allowed)) {
        $errors['actions'][] = I18n::t('Please choose at least one action: {actions}.', ['actions' => implode(', ', $allowed)]);
      }
      $values['actions'] = json_encode($actions);
    }
    if (null === $existing || array_key_exists('mode', $data)) {
      $values['mode'] = (string)($data['mode'] ?? 'direct');
      if (!in_array($values['mode'], self::MODES, true)) {
        $errors['mode'][] = I18n::t('Please choose: {modes}.', ['modes' => implode(', ', self::MODES)]);
      }
    }
    if (array_key_exists('active', $data)) {
      $values['is_active'] = (bool)filter_var($data['active'], FILTER_VALIDATE_BOOL);
    }
    if (array_key_exists('condition', $data) || null === $existing) {
      $condition = self::json($data['condition'] ?? null);
      if (false === $condition) {
        $errors['condition'][] = I18n::t('This is no valid JSON.');
      } elseif (null !== $condition && [] !== $condition) {
        if (!is_array($condition) || array_is_list($condition)) {
          $errors['condition'][] = I18n::t('The condition must be an object, e.g. {"draft": false}.');
        } else {
          try {
            null !== $entity ? $this->conditions->validate($entity, $condition) : $this->conditions->validateData($condition);
          } catch (ValidationException $e) {
            $errors = array_merge_recursive($errors, $e->getErrors());
          }
        }
      }
      $values['condition'] = is_array($condition) && [] !== $condition ? json_encode($condition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }
    if (null === $existing || array_key_exists('steps', $data)) {
      $steps = self::json($data['steps'] ?? null);
      if (false === $steps || !is_array($steps) || [] === $steps || !array_is_list($steps)) {
        $errors['steps'][] = I18n::t('Please enter the steps as a JSON list, e.g. [{"type": "webhook", "url": "https://…"}].');
      } else {
        foreach ($steps as $index => $step) {
          foreach ($this->stepErrors(is_array($step) ? $step : []) as $message) {
            $errors['steps'][] = sprintf('%d: %s', $index + 1, $message);
          }
        }
        $values['steps'] = json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    return $values;
  }

  /**
   * @return list<string>
   */
  private function stepErrors(array $step): array
  {
    $type = (string)($step['type'] ?? '');
    // Steps of active plugins: checked by their fields
    $pluginStep = str_contains($type, '.') ? $this->plugins?->registry()->step($type) : null;
    if (null !== $pluginStep) {
      return $pluginStep->errors($step);
    }
    if (!in_array($type, self::STEPS, true)) {
      $allowed = [...self::STEPS, ...array_keys($this->plugins?->registry()->steps() ?? [])];
      return [I18n::t('Unknown step "{type}" (allowed: {types}).', ['type' => $type, 'types' => implode(', ', $allowed)])];
    }
    $errors = [];
    $placeholder = static fn(string $v): bool => str_contains($v, '{{');
    switch ($type) {
      case 'webhook':
        $url = (string)($step['url'] ?? '');
        if (!$placeholder($url) && (!preg_match('#^https?://#i', $url) || false === filter_var($url, FILTER_VALIDATE_URL))) {
          $errors[] = I18n::t('Please enter a URL starting with https:// (or http://).');
        }
        if ($placeholder($url) && !preg_match('#^https?://#i', $url)) {
          $errors[] = I18n::t('Please enter a URL starting with https:// (or http://).');
        }
        // $NAME in the URL: .env variables, resolved when sending
        foreach ($this->secrets->checkPlaceholders($url) as $problem) {
          $errors[] = 'url: '.$problem;
        }
        // Secrets: values or .env references ($EVENT_…)
        $auth = $step['auth'] ?? null;
        $secrets = ['secret' => $step['secret'] ?? null];
        if (null !== $auth && [] !== $auth) {
          $type = is_array($auth) ? ($auth['type'] ?? null) : null;
          if (!in_array($type, ['bearer', 'basic'], true)) {
            $errors[] = I18n::t('auth.type must be "bearer" (token) or "basic" (user name and password).');
          } elseif ('bearer' === $type) {
            $secrets['auth.token'] = $auth['token'] ?? null;
            if ('' === trim((string)($auth['token'] ?? ''))) {
              $errors[] = I18n::t('"{key}" is missing.', ['key' => 'auth.token']);
            }
          } else {
            $secrets['auth.username'] = $auth['username'] ?? null;
            $secrets['auth.password'] = $auth['password'] ?? null;
            foreach (['username', 'password'] as $key) {
              if ('' === trim((string)($auth[$key] ?? ''))) {
                $errors[] = I18n::t('"{key}" is missing.', ['key' => 'auth.'.$key]);
              }
            }
          }
        }
        foreach ($secrets as $key => $value) {
          if (null !== $value && !is_string($value)) {
            $errors[] = I18n::t('"{key}" must be a text.', ['key' => $key]);
          } elseif (null !== ($problem = $this->secrets->check($value))) {
            $errors[] = $key.': '.$problem;
          }
        }
        break;
      case 'email':
        foreach (['to', 'subject', 'body'] as $key) {
          if ('' === trim((string)(is_array($step[$key] ?? null) ? implode(',', $step[$key]) : ($step[$key] ?? '')))) {
            $errors[] = I18n::t('"{key}" is missing.', ['key' => $key]);
          }
        }
        break;
      default:
        if (null === $this->entities->findBySlug((string)($step['entity'] ?? ''))) {
          $errors[] = I18n::t('There is no entity "{entity}".', ['entity' => (string)($step['entity'] ?? '')]);
        }
        if (in_array($type, ['create', 'update'], true) && (!is_array($step['data'] ?? null) || [] === $step['data'])) {
          $errors[] = I18n::t('"{key}" is missing.', ['key' => 'data']);
        }
        if (in_array($type, ['update', 'delete'], true) && (!is_array($step['where'] ?? null) || [] === $step['where'])) {
          $errors[] = I18n::t('"{key}" is missing.', ['key' => 'where']);
        }
    }
    return $errors;
  }

  /**
   * JSON text or an already decoded value; false = invalid JSON.
   */
  private static function json(mixed $value): mixed
  {
    if (!is_string($value)) {
      return $value;
    }
    if ('' === trim($value)) {
      return null;
    }
    $decoded = json_decode($value, true);
    return JSON_ERROR_NONE === json_last_error() ? $decoded : false;
  }

  private function row(string $id): array
  {
    return $this->events->find($id, $this->projectId()) ?? throw UserFacingException::notFound(I18n::t('This event does not exist (any more).'));
  }

  private function projectId(): string
  {
    return $this->currentProject->get()->id;
  }
}
