<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Schema\SchemaService;
use App\Domain\Access\EntityPermission;
use App\Domain\Schema\Access;
use App\Application\Media\MediaService;
use App\Domain\Schema\FieldType;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\EntityRepository;
use App\Repository\RecordRepository;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Schema editor (admins): entities and their fields.
 */
final class SchemaController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private SchemaService $schema,
    private EntityRepository $entityRepository,
    private RecordRepository $records,
  ) {
  }

  public function list(): ResponseInterface
  {
    return $this->responseFactory->success(array_map(
      fn($entity): array => $entity->toArray() + ['record_count' => $this->records->count($entity)],
      $this->entityRepository->all()
    ));
  }

  /**
   * Types, access modes and permissions with their German labels (for the forms).
   */
  public function options(): ResponseInterface
  {
    return $this->responseFactory->success([
      'field_types' => FieldType::options(),
      // Allowed files of media fields, grouped: "image/*" and every single type
      'media_types' => MediaService::acceptOptions(),
      'access' => array_map(static fn(Access $a): array => ['value' => $a->value, 'label' => $a->label()], Access::cases()),
      'permissions' => array_map(static fn(EntityPermission $p): array => ['value' => $p->value, 'label' => $p->label()], EntityPermission::cases()),
    ]);
  }

  public function get(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $entity = $this->schema->get($id);
    return $this->responseFactory->success($entity->toArray() + [
      'record_count' => $this->records->count($entity),
      'referenced_by' => array_map(static fn(array $ref): array => [
        'entity' => $ref['entity']->slug, 'entity_name' => $ref['entity']->name, 'field' => $ref['field']->name, 'field_label' => $ref['field']->label,
      ], $this->entityRepository->referencesTo($entity->id)),
    ]);
  }

  public function create(JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->schema->createEntity($input->toArray())->toArray());
  }

  public function update(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->schema->updateEntity($id, $input->toArray())->toArray());
  }

  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->schema->deleteEntity($id);
    return $this->responseFactory->success(['deleted' => true]);
  }

  /**
   * POST /v1/admin/entities/order - {ids: [...]}
   */
  public function reorder(JsonInput $input): ResponseInterface
  {
    $this->schema->reorderEntities(array_map('strval', $input->getArray('ids')));
    return $this->list();
  }

  public function addField(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->schema->addField($id, $input->toArray())->toArray());
  }

  public function updateField(#[RouteArgument('id')] string $id, #[RouteArgument('fieldId')] string $fieldId, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->schema->updateField($id, $fieldId, $input->toArray())->toArray());
  }

  public function deleteField(#[RouteArgument('id')] string $id, #[RouteArgument('fieldId')] string $fieldId): ResponseInterface
  {
    $this->schema->deleteField($id, $fieldId);
    return $this->responseFactory->success(['deleted' => true]);
  }

  /**
   * POST /v1/admin/entities/{id}/fields/order - {ids: [...]}
   */
  public function reorderFields(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->schema->reorderFields($id, array_map('strval', $input->getArray('ids')))->toArray());
  }
}
