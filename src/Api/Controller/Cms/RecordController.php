<?php

declare(strict_types=1);

namespace App\Api\Controller\Cms;

use App\Application\Content\RevisionService;
use App\Api\Input\JsonInput;
use App\Api\Input\ListRequest;
use App\Application\Content\PreviewService;
use App\Application\Content\RecordPresenter;
use App\Application\Access\FieldAccess;
use App\Application\Content\RecordLocks;
use App\Application\Content\RecordSchedules;
use App\Application\Content\RecordService;
use App\Application\Content\TreeService;
use App\Application\Schema\SchemaService;
use App\Application\Service\CurrentUser;
use App\Domain\Access\EntityPermission;
use App\Domain\Schema\EntityDefinition;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\CallbackPresenter;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\EntityRepository;
use App\Repository\RecordRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records in the admin app, checked against the user's permissions for the entity.
 */
final class RecordController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private SchemaService $schema,
    private RecordService $records,
    private RecordRepository $recordRepository,
    private EntityRepository $entityRepository,
    private RecordPresenter $presenter,
    private CurrentUser $currentUser,
    private TreeService $tree,
    private RevisionService $revisions,
    private RecordLocks $locks,
    private RecordSchedules $schedules,
    private FieldAccess $fieldAccess,
  ) {
  }

  /**
   * The entity with what the user may do with each field (fields limited to roles)
   */
  private function entityForUser(\App\Domain\Schema\EntityDefinition $entity): array
  {
    $data = $entity->toArray();
    $byName = array_column(array_map(static fn($field): array => ['name' => $field->name, 'field' => $field], $entity->fields), 'field', 'name');
    $data['fields'] = array_map(fn(array $field): array => $field + [
      'can_read' => isset($byName[$field['name']]) ? $this->fieldAccess->canRead($byName[$field['name']]) : true,
      'can_write' => isset($byName[$field['name']]) ? $this->fieldAccess->canWrite($byName[$field['name']]) : true,
    ], $data['fields'] ?? []);
    return $data;
  }

  /**
   * GET /v1/entities - entities the user may see, with fields, permissions and number of records.
   */
  public function entities(): ResponseInterface
  {
    $result = [];
    foreach ($this->schemaEntities() as $entity) {
      $permissions = $this->currentUser->getUser()->permissionsFor($entity->id);
      $result[] = $this->entityForUser($entity) + [
        'permissions' => $permissions,
        'record_count' => $permissions['read'] ? $this->recordRepository->count($entity) : null,
        'trash_count' => $permissions['delete'] ? $this->recordRepository->countTrashed($entity) : null,
      ];
    }
    return $this->responseFactory->success($result);
  }

  /**
   * GET /v1/entities/{entity} - definition of one entity.
   */
  public function entity(#[RouteArgument('entity')] string $slug): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Read);
    $permissions = $this->currentUser->getUser()->permissionsFor($entity->id);
    return $this->responseFactory->success($this->entityForUser($entity) + [
      'permissions' => $permissions,
      'trash_count' => $permissions['delete'] ? $this->recordRepository->countTrashed($entity) : null,
    ]);
  }

  /**
   * GET /v1/entities/{entity}/records?s=&filter[…]=&sort=&page=&limit=&trash=1
   * trash=1: the records in the trash (needs the delete permission)
   */
  public function list(#[RouteArgument('entity')] string $slug, ServerRequestInterface $request): ResponseInterface
  {
    $trash = filter_var($request->getQueryParams()['trash'] ?? false, FILTER_VALIDATE_BOOL);
    $entity = $this->entityFor($slug, $trash ? EntityPermission::Delete : EntityPermission::Read);
    $list = ListRequest::from($request, 25);
    return $this->responseFactory->paginated(
      $list,
      $this->records->search($entity, $list->s, $list->filter, $list->sort, $trash),
      new CallbackPresenter(fn(array $row): array => $this->presenter->presentForAdmin($entity, $row)),
      decorate: fn(array $records): array => $this->schedules->withSchedules($entity, $this->locks->withLocks($entity, $this->records->withWorkingCopyFlags($entity, $this->presenter->withActors($this->tree->withChildCounts($entity, $this->presenter->withReferenceLabels($entity, $this->presenter->withMedia($entity, $records, true))))))),
    );
  }

  /**
   * GET /v1/entities/{entity}/options?s=&ids[]= - choices for reference fields
   */
  public function options(#[RouteArgument('entity')] string $slug, ServerRequestInterface $request): ResponseInterface
  {
    // Whoever edits a record pointing here may see the labels, even without read permission
    $entity = $this->schema->get($slug);
    $params = $request->getQueryParams();
    $ids = isset($params['ids']) ? array_values(array_filter(array_map('strval', (array)$params['ids']))) : [];
    return $this->responseFactory->success($this->records->options($entity, (string)($params['s'] ?? ''), 20, $ids));
  }

  public function get(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Read);
    return $this->responseFactory->success($this->records->get($entity, $id) + ['_lock' => $this->locks->get($entity, $id), '_schedule' => $this->schedules->get($entity, $id)]);
  }

  /**
   * POST /v1/entities/{entity}/records/{id}/preview {lang?} - a preview token and the preview address
   * of the entity filled in (see PreviewService)
   */
  public function preview(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, JsonInput $input, PreviewService $previews): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Read);
    $language = trim((string)($input->toArray()['lang'] ?? ''));
    return $this->responseFactory->success($previews->issue($entity, $this->records->get($entity, $id), in_array($language, $entity->languages, true) ? $language : null));
  }

  /**
   * GET /v1/entities/{entity}/records/{id}/references - who points to this record
   */
  public function references(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Read);
    $references = array_values(array_filter(
      $this->records->referencedBy($entity, $id),
      fn(array $ref): bool => !$ref['other_project'] && $this->currentUser->can(EntityPermission::Read, $this->schema->get($ref['entity']))
    ));
    return $this->responseFactory->success($references);
  }

  public function create(#[RouteArgument('entity')] string $slug, JsonInput $input): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Create);
    return $this->responseFactory->success($this->records->create($entity, $input->toArray()));
  }

  public function update(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->locks->assertNotLockedByOthers($entity, $id);
    return $this->responseFactory->success($this->records->update($entity, $id, $input->toArray()));
  }

  /**
   * PUT /v1/entities/{entity}/records/{id}/working-copy - changes of a published record saved for
   * later, the record stays live as it is; POST …/working-copy/publish makes them live (body: the
   * values, like PUT …/records/{id}); DELETE …/working-copy discards them
   */
  public function saveWorkingCopy(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->locks->assertNotLockedByOthers($entity, $id);
    return $this->responseFactory->success($this->records->saveWorkingCopy($entity, $id, $input->toArray()));
  }

  public function publishWorkingCopy(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->locks->assertNotLockedByOthers($entity, $id);
    return $this->responseFactory->success($this->records->publishWorkingCopy($entity, $id, $input->toArray()));
  }

  public function discardWorkingCopy(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->locks->assertNotLockedByOthers($entity, $id);
    return $this->responseFactory->success($this->records->discardWorkingCopy($entity, $id));
  }

  /**
   * POST /v1/entities/{entity}/records/{id}/lock - locks the record for editing (or renews the own
   * lock; the admin app does every 30 s). Locked by someone else: their lock, "mine": false.
   * POST …/lock/take-over (admins, editors) takes it over, DELETE …/lock releases the own one.
   */
  public function lock(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->records->get($entity, $id);
    return $this->responseFactory->success($this->locks->acquire($entity, $id));
  }

  public function takeOverLock(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->records->get($entity, $id);
    return $this->responseFactory->success($this->locks->takeOver($entity, $id));
  }

  public function unlock(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->locks->release($entity, $id);
    return $this->responseFactory->success(['released' => true]);
  }

  /**
   * PUT /v1/entities/{entity}/records/{id}/schedule - {publish_at?, unpublish_at?} (ISO 8601, null
   * removes it): publish a draft (or the working copy of a published record) / unpublish it later
   */
  public function schedule(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->locks->assertNotLockedByOthers($entity, $id);
    return $this->responseFactory->success($this->schedules->set($entity, $id, $input->toArray()));
  }

  public function delete(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Delete);
    $this->locks->assertNotLockedByOthers($entity, $id);
    $this->records->delete($entity, $id);
    return $this->responseFactory->success(['deleted' => true]);
  }

  /**
   * POST /v1/entities/{entity}/records/delete - {ids: [...], permanent?: bool}
   * Into the trash (if the entity has one) or deleted; records in use are reported in `failed`.
   */
  public function deleteMany(#[RouteArgument('entity')] string $slug, JsonInput $input): ResponseInterface
  {
    $entity = $this->schema->get($slug);
    $data = $input->toArray();
    // Only own records: every one of them must be the user's
    if (!$this->currentUser->can(EntityPermission::Delete, $entity)) {
      $this->currentUser->assertCan(EntityPermission::DeleteOwn, $entity);
      foreach (self::ids($data) as $id) {
        $row = $this->recordRepository->find($entity, $id, true);
        if (null === $row || !$this->currentUser->canRecord(EntityPermission::Delete, $entity, $row)) {
          throw UserFacingException::forbidden(I18n::t('You may only change records you created yourself.'));
        }
      }
    }
    $this->locks->assertNoneLockedByOthers($entity, self::ids($data));
    return $this->responseFactory->success($this->records->deleteMany(
      $entity,
      self::ids($data),
      (bool)filter_var($data['permanent'] ?? false, FILTER_VALIDATE_BOOL),
    ));
  }

  /**
   * GET /v1/entities/{entity}/records/{id}/revisions - the history, newest first
   */
  public function revisions(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Read);
    return $this->responseFactory->success($this->revisions->history($entity, $id));
  }

  /**
   * GET /v1/entities/{entity}/records/{id}/revisions/{revision} - the record as it was
   */
  public function revision(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, #[RouteArgument('revision')] string $revision): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Read);
    return $this->responseFactory->success($this->revisions->show($entity, $id, $revision));
  }

  /**
   * POST /v1/entities/{entity}/records/{id}/revisions/{revision}/restore - {fields?: [...]} (needs "update")
   */
  public function restoreRevision(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, #[RouteArgument('revision')] string $revision, JsonInput $input): ResponseInterface
  {
    $entity = $this->entityForRecord($slug, $id, EntityPermission::Update);
    $this->locks->assertNotLockedByOthers($entity, $id);
    $fields = $input->toArray()['fields'] ?? null;
    return $this->responseFactory->success($this->revisions->restore($entity, $id, $revision, is_array($fields) ? array_map('strval', $fields) : null));
  }

  /**
   * POST /v1/entities/{entity}/records/order - {ids: [...]} in the new order (needs "update")
   */
  public function reorder(#[RouteArgument('entity')] string $slug, JsonInput $input): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Update);
    $this->records->reorder($entity, self::ids($input->toArray()));
    return $this->responseFactory->success(['ordered' => true]);
  }

  /**
   * POST /v1/entities/{entity}/records/restore - {ids: [...]}
   */
  public function restore(#[RouteArgument('entity')] string $slug, JsonInput $input): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Delete);
    return $this->responseFactory->success(['restored' => $this->records->restore($entity, self::ids($input->toArray()))]);
  }

  /**
   * POST /v1/entities/{entity}/trash/empty
   */
  public function emptyTrash(#[RouteArgument('entity')] string $slug): ResponseInterface
  {
    $entity = $this->entityFor($slug, EntityPermission::Delete);
    return $this->responseFactory->success($this->records->emptyTrash($entity));
  }

  /**
   * @return list<string>
   */
  private static function ids(array $data): array
  {
    $ids = array_values(array_filter(array_map('strval', is_array($data['ids'] ?? null) ? $data['ids'] : []), static fn(string $id): bool => '' !== $id));
    if ([] === $ids) {
      throw ValidationException::field('ids', I18n::t('Please choose at least one record.'));
    }
    if (count($ids) > 1000) {
      throw ValidationException::field('ids', I18n::t('At most 1000 records at once.'));
    }
    return $ids;
  }

  private function entityFor(string $slug, EntityPermission $permission): EntityDefinition
  {
    $entity = $this->schema->get($slug);
    $this->currentUser->assertCan($permission, $entity);
    return $entity;
  }

  /**
   * Update / delete one record: the permission for all records, or for own ones if the user created
   * this one (OwnRecordRule).
   */
  private function entityForRecord(string $slug, string $id, EntityPermission $permission): EntityDefinition
  {
    $entity = $this->schema->get($slug);
    if ($this->currentUser->can($permission, $entity)) {
      return $entity;
    }
    $row = $this->recordRepository->find($entity, $id, true);
    if (null !== $row && $this->currentUser->canRecord($permission, $entity, $row)) {
      return $entity;
    }
    if (null !== $permission->own() && $this->currentUser->can($permission->own(), $entity)) {
      throw UserFacingException::forbidden(I18n::t('You may only change records you created yourself.'));
    }
    $this->currentUser->assertCan($permission, $entity);
    return $entity;
  }

  /**
   * @return list<EntityDefinition>
   */
  private function schemaEntities(): array
  {
    $user = $this->currentUser->getUser();
    return array_values(array_filter(
      $this->entityRepository->all(),
      static fn(EntityDefinition $e): bool => $user->can(EntityPermission::Read, $e->id) || $user->can(EntityPermission::Import, $e->id)
    ));
  }
}
