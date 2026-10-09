<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Content\RecordQuery;
use App\Application\Content\RecordService;
use App\Application\Service\CurrentActor;
use App\Application\Service\CurrentProject;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Webhook\WebhookSender;
use App\Repository\EntityRepository;
use App\Repository\EventRepository;
use App\Repository\ProjectRepository;
use App\Repository\RecordRepository;
use App\Shared\Exception\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Executes a run: the steps of its event in their order, for the records of the run. The progress
 * is saved after every step and record ("create 37/100"); the first failing step stops the run
 * (status failed, the error at the step). A retry skips the steps that are done.
 *
 * Steps:
 *   webhook  {url, secret?, digest?}        one call with all records of the run (digest, default) or one
 *                                          per record; with the entity and its fields; signed with the secret
 *   email    {to, subject, body, digest?}   one mail per record, or one for all (digest)
 *   create   {entity, data}                 a record per record of the run
 *   update   {entity, where, data}          the records that match "where" (filter), per record of the run
 *   delete   {entity, where}                the same, into the trash or deleted
 * Values may use placeholders (see Template).
 */
final class EventRunner
{
  public function __construct(
    private EventRepository $events,
    private EventDispatcher $dispatcher,
    private ProjectRepository $projects,
    private EntityRepository $entities,
    private CurrentProject $currentProject,
    private CurrentActor $actor,
    private RecordService $recordService,
    private RecordRepository $records,
    private RecordQuery $recordQuery,
    private WebhookSender $sender,
    private Mailer $mailer,
    private \App\Application\Service\EnvVariables $secrets,
    private ?\App\Plugin\PluginManager $plugins = null,
    private ?\App\Repository\UserRepository $users = null,
  ) {
  }

  /**
   * Runs it and then the runs its steps started (chains) that are not queued.
   */
  public function run(string $runId): void
  {
    $run = $this->events->findRun($runId);
    $event = null !== $run ? $this->events->find((string)$run['event_id']) : null;
    if (null === $run || null === $event || in_array($run['status'], ['running', 'done'], true)) {
      return;
    }
    $project = $this->projects->find((string)$run['project_id']);
    if (null === $project) {
      return;
    }
    $this->currentProject->set($project);
    $this->entities->reset();

    $records = (array)json_decode((string)$run['records'], true);
    $steps = (array)json_decode((string)$event['steps'], true);
    $progress = (array)json_decode((string)$run['steps'], true);
    $this->events->updateRun($runId, ['status' => 'running', 'started_at' => date('Y-m-d H:i:s'), 'error' => null]);
    $failed = null;
    $origin = array_merge((array)json_decode((string)($run['origin'] ?? '[]'), true), [(string)$event['id']]);

    $this->dispatcher->within((int)$run['depth'] + 1, $origin, function () use ($runId, $event, $project, $records, $steps, &$progress, &$failed): void {
      $this->actor->as('event:'.$event['id'], function () use ($runId, $event, $project, $records, $steps, &$progress, &$failed): void {
        foreach ($steps as $index => $step) {
          if ('done' === ($progress[$index]['status'] ?? null)) {
            continue;
          }
          $stop = false;
          $progress[$index] = ['type' => (string)($step['type'] ?? ''), 'status' => 'running', 'done' => 0, 'total' => 0, 'error' => null];
          $this->events->updateRun($runId, ['steps' => json_encode($progress)]);
          try {
            $this->step((array)$step, $event, $project, $records, function (int $done, int $total) use ($runId, $index, &$progress): void {
              $progress[$index]['done'] = $done;
              $progress[$index]['total'] = $total;
              $this->events->updateRun($runId, ['steps' => json_encode($progress)]);
            });
            $progress[$index]['status'] = 'done';
          } catch (Throwable $e) {
            $progress[$index]['status'] = 'failed';
            $progress[$index]['error'] = self::message($e);
            // The run fails - with "continue_on_error" the next steps run anyway (a retry repeats this one)
            $failed ??= $progress[$index]['error'];
            $stop = !($step['continue_on_error'] ?? false);
          }
          $this->events->updateRun($runId, ['steps' => json_encode($progress)]);
          if ($stop) {
            break;
          }
        }
      });
    });

    $this->events->updateRun($runId, [
      'status' => null === $failed ? 'done' : 'failed',
      'error' => null !== $failed ? mb_substr($failed, 0, 1000) : null,
      'finished_at' => date('Y-m-d H:i:s'),
    ]);
    // Events the steps started: queued ones wait for the worker, the others run now
    foreach ($this->dispatcher->flush() as $next) {
      $this->run($next);
    }
  }

  /**
   * Runs the steps once without saving anything: what would happen (admin app, "test run").
   *
   * @return list<array{type: string, preview: mixed}>
   */
  public function preview(array $event, array $records): array
  {
    $project = $this->currentProject->get();
    $result = [];
    foreach ((array)json_decode((string)$event['steps'], true) as $step) {
      $step = (array)$step;
      $type = (string)($step['type'] ?? '');
      $contexts = array_map(fn(array $record): array => $this->context($event, $project, $record, count($records)), $records);
      $result[] = ['type' => $type, 'preview' => match ($type) {
        'webhook' => ['url' => $step['url'] ?? null, 'body' => $this->webhookBody($event, $project->slug, $records)],
        'email' => array_map(fn(array $ctx): array => ['to' => implode(', ', $this->recipients(Template::render($step['to'] ?? '', $ctx))), 'subject' => Template::render($step['subject'] ?? '', $ctx), 'body' => Template::render($step['body'] ?? '', $ctx)], ($step['digest'] ?? false) ? array_slice($contexts, 0, 1) : $contexts),
        'create', 'update' => array_map(static fn(array $ctx): array => ['entity' => $step['entity'] ?? null, 'where' => Template::render($step['where'] ?? null, $ctx), 'data' => Template::render($step['data'] ?? [], $ctx)], $contexts),
        'delete' => array_map(static fn(array $ctx): array => ['entity' => $step['entity'] ?? null, 'where' => Template::render($step['where'] ?? null, $ctx)], $contexts),
        default => $this->pluginPreview($step, $event, $records, $contexts),
      }];
    }
    return $result;
  }

  /**
   * @param callable(int, int): void $progress
   */
  private function step(array $step, array $event, $project, array $records, callable $progress): void
  {
    $type = (string)($step['type'] ?? '');
    $count = count($records);
    switch ($type) {
      case 'webhook':
        // One call with all records of the run (digest, the default) or one per record
        $calls = false !== ($step['digest'] ?? true) ? [$records] : array_map(static fn(array $r): array => [$r], $records);
        $progress(0, count($calls));
        foreach ($calls as $i => $group) {
          $body = (string)json_encode($this->webhookBody($event, $project->slug, $group), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Excellent-CMS-Event'] + $this->authHeaders((array)($step['auth'] ?? []));
          // Secrets may be .env references ($EVENT_…), resolved only now
          $secret = $this->secrets->resolve((string)($step['secret'] ?? ''));
          if ('' !== $secret) {
            $headers['X-Excellent-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
          }
          // Placeholders of the records first, then $NAME from the .env (URL-encoded)
          $url = $this->secrets->interpolate((string)Template::render((string)($step['url'] ?? ''), $this->context($event, $project, $group[0] ?? [], $count)), true);
          $result = $this->sender->send($url, $body, $headers);
          if (null === $result['status'] || $result['status'] >= 400) {
            throw new RuntimeException(sprintf('HTTP %s: %s', $result['status'] ?? '–', (string)$result['error']));
          }
          $progress($i + 1, count($calls));
        }
        return;

      case 'email':
        $digest = (bool)($step['digest'] ?? false);
        $mails = $digest ? [$records] : array_map(static fn(array $r): array => [$r], $records);
        $progress(0, count($mails));
        foreach ($mails as $i => $group) {
          $contexts = array_map(fn(array $record): array => $this->context($event, $project, $record, $count), $group);
          $subject = (string)Template::render((string)($step['subject'] ?? ''), $contexts[0]);
          $body = implode("\n\n---\n\n", array_map(static fn(array $ctx): string => (string)Template::render((string)($step['body'] ?? ''), $ctx), $contexts));
          foreach ($this->recipients(Template::render($step['to'] ?? '', $contexts[0])) as $to) {
            $this->mailer->send($to, $subject, $body);
          }
          $progress($i + 1, count($mails));
        }
        return;

      case 'create':
      case 'update':
      case 'delete':
        $target = $this->entities->findBySlug((string)($step['entity'] ?? '')) ?? throw new RuntimeException(sprintf('There is no entity "%s".', (string)($step['entity'] ?? '')));
        $progress(0, $count);
        foreach ($records as $i => $record) {
          $context = $this->context($event, $project, $record, $count);
          if ('create' === $type) {
            $this->recordService->create($target, (array)Template::render($step['data'] ?? [], $context));
          } else {
            $where = (array)Template::render($step['where'] ?? [], $context);
            if ([] === $where) {
              throw new RuntimeException('"where" is missing - which records?');
            }
            $ids = array_map('strval', $this->recordQuery->apply($this->records->query($target), $target, '', $where, 'id')->select('id')->column());
            if ('update' === $type) {
              foreach ($ids as $id) {
                $this->recordService->update($target, $id, (array)Template::render($step['data'] ?? [], $context));
              }
            } elseif ([] !== $ids) {
              $this->recordService->deleteMany($target, $ids);
            }
          }
          $progress($i + 1, $count);
        }
        return;
    }
    // Steps of plugins ("<plugin>.<step>")
    $pluginStep = $this->plugins?->registry()->step($type);
    if (null !== $pluginStep) {
      $contexts = array_map(fn(array $record): array => $this->context($event, $project, $record, $count), $records);
      ($pluginStep->handler)($step, $this->pluginRun($pluginStep, $event, $records, $contexts, $progress));
      return;
    }
    throw new RuntimeException(sprintf('Unknown step "%s" - is its plugin active?', $type));
  }

  private function pluginRun(\App\Plugin\PluginStep $step, array $event, array $records, array $contexts, callable $progress): \App\Plugin\StepRun
  {
    $context = $this->plugins?->context($step->plugin) ?? throw new RuntimeException(sprintf('The plugin "%s" is not active.', $step->plugin));
    return new \App\Plugin\StepRun($event, array_values($records), array_values($contexts), \Closure::fromCallable($progress), $this->sender, $this->secrets, $context);
  }

  /**
   * Test run of a plugin's step: its preview, otherwise its fields with the placeholders filled in.
   */
  private function pluginPreview(array $step, array $event, array $records, array $contexts): mixed
  {
    $pluginStep = $this->plugins?->registry()->step((string)($step['type'] ?? ''));
    if (null === $pluginStep) {
      return null;
    }
    if (null !== $pluginStep->preview) {
      return ($pluginStep->preview)($step, $this->pluginRun($pluginStep, $event, $records, $contexts, static function (): void {
      }));
    }
    return array_map(static fn(array $ctx): array => Template::render(array_diff_key($step, ['type' => true]), $ctx), array_slice($contexts, 0, 3));
  }

  /**
   * The payload of a webhook: project, event, the entity (with its fields) and the records with
   * their data and their state before.
   */
  /**
   * Auth of a webhook: {type: "bearer", token} or {type: "basic", username, password} - each value
   * may be a .env reference.
   *
   * @return array<string, string>
   */
  private function authHeaders(array $auth): array
  {
    return match ($auth['type'] ?? null) {
      'bearer' => ['Authorization' => 'Bearer '.$this->secrets->resolve((string)($auth['token'] ?? ''))],
      'basic' => ['Authorization' => 'Basic '.base64_encode($this->secrets->resolve((string)($auth['username'] ?? '')).':'.$this->secrets->resolve((string)($auth['password'] ?? '')))],
      default => [],
    };
  }

  private function webhookBody(array $event, string $project, array $records): array
  {
    $entity = null !== $event['entity_id'] ? $this->entities->findById((string)$event['entity_id']) : null;
    return [
      'project' => $project,
      'event' => ['id' => (string)$event['id'], 'name' => (string)$event['name']],
      // entity, media or variables
      'source' => (string)($event['source'] ?? 'entity'),
      'entity' => null !== $entity ? [
        'slug' => $entity->slug,
        'name' => $entity->name,
        'fields' => array_map(static fn($f): array => ['name' => $f->name, 'label' => $f->label, 'type' => $f->type->value], $entity->fields),
      ] : null,
      'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
      'count' => count($records),
      'records' => $records,
    ];
  }

  private function context(array $event, $project, array $record, int $count): array
  {
    $variables = [];
    foreach ($project->variables as $variable) {
      $variables[$variable['name']] = $project->variable($variable['name']);
    }
    return [
      'record' => $record['data'] ?? [],
      'old' => $record['old'] ?? null,
      'event' => ['id' => (string)$event['id'], 'name' => (string)$event['name'], 'action' => $record['action'] ?? null, 'entity' => $record['entity'] ?? null],
      'project' => $variables + ['slug' => $project->slug, 'name' => $project->name],
      'count' => $count,
    ];
  }

  /**
   * @return list<string>
   */
  /**
   * Addresses of an e-mail step: e-mail addresses (also from placeholders, {{record.email}}) and
   * users of the CMS ("user:<id>") - their address at the time of sending, active users only.
   *
   * @return list<string>
   */
  private function recipients(mixed $to): array
  {
    $list = is_array($to) ? $to : preg_split('/[,;\s]+/', (string)$to);
    $result = [];
    foreach (array_map('trim', array_map('strval', (array)$list)) as $entry) {
      if (str_starts_with($entry, 'user:')) {
        $user = $this->users?->get(substr($entry, 5));
        $entry = null !== $user && $user->isActive() ? $user->getEmail() : '';
      }
      if (false !== filter_var($entry, FILTER_VALIDATE_EMAIL)) {
        $result[] = $entry;
      }
    }
    return array_values(array_unique($result));
  }

  private static function message(Throwable $e): string
  {
    if ($e instanceof ValidationException) {
      $parts = [];
      foreach ($e->getErrors() as $field => $messages) {
        $parts[] = $field.': '.implode(' ', (array)$messages);
      }
      return implode(' · ', $parts);
    }
    return $e->getMessage();
  }
}
