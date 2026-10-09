<?php

declare(strict_types=1);

namespace App\Application\Content;

use App\Application\Event\EventDispatcher;
use App\Application\Event\EventRunner;
use App\Application\Service\CurrentActor;
use App\Application\Service\CurrentProject;
use App\Domain\Schema\EntityDefinition;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use App\Repository\RecordRepository;
use App\Repository\RecordScheduleRepository;
use App\Repository\WorkingCopyRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Throwable;

/**
 * Scheduled publishing and unpublishing (entities with drafts), run every minute by
 * `./yii schedule:run`:
 *
 *   publish    a draft goes live - a published record with a working copy publishes that
 *   unpublish  a published record becomes a draft again
 *
 * The change is made in the name of whoever scheduled it, so revisions and events show it like any
 * other change. If it cannot be done (required fields of the draft are empty), the record stays as
 * it is and the schedule keeps the error until it is set again.
 */
final class RecordSchedules
{
  public function __construct(
    private RecordScheduleRepository $schedules,
    private RecordRepository $records,
    private RecordService $recordService,
    private WorkingCopyRepository $workingCopies,
    private EntityRepository $entities,
    private ProjectRepository $projects,
    private CurrentProject $currentProject,
    private CurrentActor $actor,
    private EventDispatcher $dispatcher,
    private EventRunner $runner,
  ) {
  }

  /**
   * @return array{publish_at: ?string, unpublish_at: ?string, errors: array<string, string>}|null
   */
  public function get(EntityDefinition $entity, string $recordId): ?array
  {
    return $this->withSchedules($entity, [['id' => $recordId]])[0]['_schedule'];
  }

  /**
   * Records of a list: "_schedule" with the times (ISO 8601) and errors, null without one.
   *
   * @param list<array<string, mixed>> $records
   * @return list<array<string, mixed>>
   */
  public function withSchedules(EntityDefinition $entity, array $records): array
  {
    $schedules = $entity->drafts ? $this->schedules->forRecords($entity, array_map(static fn(array $r): string => (string)$r['id'], $records)) : [];
    return array_map(static function (array $record) use ($schedules): array {
      $schedule = $schedules[(string)$record['id']] ?? null;
      $record['_schedule'] = null === $schedule ? null : [
        'publish_at' => isset($schedule['publish']) ? date(DATE_ATOM, (int)strtotime($schedule['publish']['run_at'])) : null,
        'unpublish_at' => isset($schedule['unpublish']) ? date(DATE_ATOM, (int)strtotime($schedule['unpublish']['run_at'])) : null,
        'errors' => (object)array_filter(array_map(static fn(array $s): ?string => $s['error'], $schedule)),
      ];
      return $record;
    }, $records);
  }

  /**
   * Sets both times (null removes one): {publish_at, unpublish_at} as ISO 8601 or "Y-m-d H:i".
   */
  public function set(EntityDefinition $entity, string $recordId, array $data): ?array
  {
    if (!$entity->drafts) {
      throw new UserFacingException(I18n::t('"{entity}" has no drafts, so its records cannot be published or unpublished on a schedule.', ['entity' => $entity->name]));
    }
    if (null === $this->records->find($entity, $recordId)) {
      throw UserFacingException::notFound(I18n::t('This record does not exist (any more).'));
    }
    $errors = [];
    $times = [];
    foreach (RecordScheduleRepository::ACTIONS as $action) {
      $key = $action.'_at';
      if (!array_key_exists($key, $data)) {
        continue;
      }
      $value = $data[$key];
      if (null === $value || '' === $value) {
        $times[$action] = null;
        continue;
      }
      $time = is_string($value) ? strtotime($value) : false;
      if (false === $time) {
        $errors[$key][] = I18n::t('Please enter a date and time.');
      } elseif ($time <= time()) {
        $errors[$key][] = I18n::t('Please choose a time in the future.');
      } else {
        $times[$action] = $time;
      }
    }
    $current = $this->schedules->forRecords($entity, [$recordId])[$recordId] ?? [];
    $publish = array_key_exists('publish', $times) ? $times['publish'] : (isset($current['publish']) ? strtotime($current['publish']['run_at']) : null);
    $unpublish = array_key_exists('unpublish', $times) ? $times['unpublish'] : (isset($current['unpublish']) ? strtotime($current['unpublish']['run_at']) : null);
    if ([] === $errors && null !== $publish && null !== $unpublish && $unpublish <= $publish) {
      $errors['unpublish_at'][] = I18n::t('Unpublishing has to come after publishing.');
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    foreach ($times as $action => $time) {
      $this->schedules->set($entity, $recordId, $action, null !== $time ? date('Y-m-d H:i:s', $time) : null, $this->actor->id());
    }
    return $this->get($entity, $recordId);
  }

  /**
   * Carries out what is due.
   *
   * @return array{done: int, failed: int}
   */
  public function run(): array
  {
    $result = ['done' => 0, 'failed' => 0];
    foreach ($this->schedules->due(date('Y-m-d H:i:s')) as $schedule) {
      $entity = $this->entities->findById((string)$schedule['entity_id']);
      $record = null !== $entity ? $this->records->find($entity, (string)$schedule['record_id']) : null;
      // Gone, in the trash, or drafts switched off: nothing to do any more
      if (null === $entity || null === $record || !$entity->drafts) {
        $this->schedules->done((string)$schedule['id']);
        continue;
      }
      $this->currentProject->set($this->projects->find($entity->projectId));
      try {
        $this->actor->as((string)($schedule['created_by'] ?? 'schedule'), fn() => $this->carryOut($entity, $record, (string)$schedule['action']));
        $this->schedules->done((string)$schedule['id']);
        $result['done']++;
      } catch (Throwable $e) {
        $this->dispatcher->reset();
        $this->schedules->failed((string)$schedule['id'], $e instanceof ValidationException
          ? implode(' ', array_map(static fn(string $field, array $messages): string => $field.': '.implode(' ', $messages), array_keys($e->getErrors()), $e->getErrors()))
          : ($e instanceof UserFacingException ? $e->getMessage() : I18n::t('Sorry, something went wrong. Please try again.')));
        $result['failed']++;
        continue;
      }
      // Events of the change (publish, unpublish) run like after a request
      foreach ($this->dispatcher->flush() as $run) {
        $this->runner->run($run);
      }
    }
    return $result;
  }

  private function carryOut(EntityDefinition $entity, array $record, string $action): void
  {
    $isDraft = RecordRepository::isDraft($entity, $record);
    if ('unpublish' === $action) {
      if (!$isDraft) {
        $this->recordService->update($entity, (string)$record['id'], [EntityDefinition::DRAFT => true]);
      }
      return;
    }
    if ($isDraft) {
      $this->recordService->update($entity, (string)$record['id'], [EntityDefinition::DRAFT => false]);
    } elseif (null !== $this->workingCopies->find($entity, (string)$record['id'])) {
      $this->recordService->publishStoredWorkingCopy($entity, (string)$record['id']);
    }
  }
}
