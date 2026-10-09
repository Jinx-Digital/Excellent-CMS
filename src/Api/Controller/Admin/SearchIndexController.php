<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Application\Search\SearchIndex;
use App\Application\Service\CurrentProject;
use App\Application\Schema\SchemaService;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Repository\EntityRepository;
use Psr\Http\Message\ResponseInterface;

/**
 * Search index of the current project (admins): stop words, status per entity, rebuild and clear
 * (all entities or one: {"entity": "pages"}).
 */
final class SearchIndexController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private SearchIndex $index,
    private EntityRepository $entities,
    private SchemaService $schema,
    private CurrentProject $currentProject,
  ) {
  }

  public function status(): ResponseInterface
  {
    return $this->responseFactory->success($this->overview());
  }

  /**
   * PUT /v1/admin/search-index/stopwords - {stopwords: {"*": ["1", "2"], "": ["der", "die" …], "en": ["the" …]}}
   * per language ("*" = all languages, "" = the default one; words as list or one text), or one list
   * for the default language
   */
  public function stopwords(JsonInput $input): ResponseInterface
  {
    $value = $input->toArray()['stopwords'] ?? [];
    $lists = is_array($value) && !array_is_list($value) ? $value : ['' => $value];
    $words = static fn(mixed $list): array => array_values(array_filter(is_array($list) ? $list : (array)preg_split('/[\s,;]+/u', (string)$list), 'is_scalar'));
    $project = $this->currentProject->get();
    // Only languages of the project
    $allowed = ['*', '', ...$project->otherLanguages()];
    $this->index->setStopwords($project->id, array_map($words, array_intersect_key($lists, array_flip($allowed))));
    return $this->responseFactory->success($this->overview());
  }

  public function rebuild(JsonInput $input): ResponseInterface
  {
    @set_time_limit(0);
    foreach ($this->targets($input) as $entity) {
      $this->index->rebuild($entity);
    }
    $this->index->pruneTerms($this->currentProject->get()->id);
    return $this->responseFactory->success($this->overview());
  }

  public function clear(JsonInput $input): ResponseInterface
  {
    foreach ($this->targets($input) as $entity) {
      $this->index->clear($entity);
    }
    $this->index->pruneTerms($this->currentProject->get()->id);
    return $this->responseFactory->success($this->overview());
  }

  /**
   * @return list<\App\Domain\Schema\EntityDefinition>
   */
  private function targets(JsonInput $input): array
  {
    $slug = trim((string)($input->toArray()['entity'] ?? ''));
    return '' !== $slug ? [$this->schema->get($slug)] : $this->projectEntities();
  }

  /**
   * @return list<\App\Domain\Schema\EntityDefinition>
   */
  private function projectEntities(): array
  {
    $projectId = $this->currentProject->get()->id;
    return array_values(array_filter($this->entities->all(), static fn($entity): bool => $entity->projectId === $projectId));
  }

  private function overview(): array
  {
    return [
      'stopwords' => (object)$this->index->stopwordLists($this->currentProject->get()->id),
      'entities' => $this->index->status($this->projectEntities()),
    ];
  }
}
