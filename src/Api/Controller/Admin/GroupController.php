<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Schema\GroupService;
use App\Domain\Schema\FieldGroup;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\EntityRepository;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Field groups of the current project (admins).
 */
final class GroupController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private GroupService $groups,
    private EntityRepository $entities,
    private ?\App\Application\Service\CurrentProject $currentProject = null,
    private ?\Psr\Http\Message\ServerRequestInterface $request = null,
  ) {
  }

  /**
   * ?kind=group|block: only field groups or only blocks.
   */
  public function list(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
  {
    $kind = $request->getQueryParams()['kind'] ?? null;
    $kind = in_array($kind, [FieldGroup::KIND_GROUP, FieldGroup::KIND_BLOCK], true) ? $kind : null;
    return $this->responseFactory->success(array_map(fn(FieldGroup $group): array => $this->present($group), $this->groups->all($kind)));
  }

  public function get(#[RouteArgument('id')] string $id): ResponseInterface
  {
    return $this->responseFactory->success($this->present($this->groups->get($id)));
  }

  public function create(JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->present($this->groups->create($input->toArray())));
  }

  public function update(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->present($this->groups->update($id, $input->toArray())));
  }

  public function delete(#[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->groups->delete($id);
    return $this->responseFactory->success(['deleted' => true]);
  }

  public function addField(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->present($this->groups->addField($id, $input->toArray())));
  }

  public function updateField(#[RouteArgument('id')] string $id, #[RouteArgument('fieldId')] string $fieldId, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->present($this->groups->updateField($id, $fieldId, $input->toArray())));
  }

  public function deleteField(#[RouteArgument('id')] string $id, #[RouteArgument('fieldId')] string $fieldId): ResponseInterface
  {
    return $this->responseFactory->success($this->present($this->groups->deleteField($id, $fieldId)));
  }

  public function reorderFields(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->present($this->groups->reorderFields($id, (array)($input->toArray()['ids'] ?? []))));
  }

  /**
   * POST /v1/admin/entities/{id}/fields/group - {fields: [...], group?: existing, group_name?, group_label?, name?, label?}
   */
  public function fromFields(#[RouteArgument('id')] string $id, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->groups->fromFields($id, $input->toArray())->toArray());
  }

  /**
   * POST /v1/admin/groups/{id}/render {template?, block?} - the HTML of a block with its template
   * (or the sent one, not saved yet) and an example of its values (or the sent ones).
   */
  public function render(#[RouteArgument('id')] string $id, JsonInput $input, \App\Application\Content\BlockTemplates $templates): ResponseInterface
  {
    $group = clone $this->groups->get($id);
    $data = $input->toArray();
    if (array_key_exists('template', $data)) {
      $group->template = (string)$data['template'];
      if (null !== ($problem = $templates->check($group->template))) {
        throw new \App\Shared\Exception\ValidationException(['template' => [$problem]]);
      }
    }
    $block = is_array($data['block'] ?? null) ? $data['block'] : self::example($group);
    $project = $this->currentProject?->find();
    if (null !== $project && null !== $this->request) {
      $uri = $this->request->getUri();
      $templates->setApiUrl($uri->getScheme().'://'.$uri->getAuthority().'/api/v1/'.$project->slug);
    }
    try {
      $html = $templates->render($group, ['_type' => $group->name, '_key' => 'example'] + $block, true);
    } catch (\Throwable $e) {
      throw new \App\Shared\Exception\ValidationException(['template' => [$e->getMessage()]]);
    }
    return $this->responseFactory->success(['html' => $html, 'block' => $block]);
  }

  /**
   * Example values of the fields (labels as texts) - for trying a template.
   */
  private static function example(FieldGroup $group): array
  {
    $values = [];
    foreach ($group->fields as $field) {
      $values[$field->name] = match (true) {
        $field->isBlocks() => [],
        $field->repeatable => [$field->label],
        \App\Domain\Schema\FieldType::Boolean === $field->type => true,
        \App\Domain\Schema\FieldType::Integer === $field->type, \App\Domain\Schema\FieldType::Decimal === $field->type => 42,
        \App\Domain\Schema\FieldType::Media === $field->type => ['url' => 'https://placehold.co/1200x800/png', 'name' => 'example.png', 'width' => 1200, 'height' => 800, 'is_image' => true],
        \App\Domain\Schema\FieldType::Url === $field->type => 'https://example.com',
        \App\Domain\Schema\FieldType::Group === $field->type => null !== $field->group ? self::example($field->group) : null,
        \App\Domain\Schema\FieldType::Enum === $field->type => $field->options[0]['value'] ?? null,
        default => $field->label,
      };
    }
    return $values;
  }

  private function present(FieldGroup $group): array
  {
    return $group->toArray() + ['template' => $group->template, 'usage_count' => count($this->entities->fieldsUsingGroup($group->id))];
  }
}
