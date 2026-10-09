<?php

declare(strict_types=1);

namespace App\Api\Controller\Content;

use App\Api\Input\ListRequest;
use App\Api\Input\JsonInput;
use App\Application\Content\ContentService;
use App\Application\Project\ProjectVariables;
use App\Application\Service\CurrentProject;
use App\Presentation\Api\Shared\ResponseFactory\Presenter\AsIsPresenter;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Headless content API - see ContentService for the parameters.
 */
final class ContentController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private ContentService $content,
  ) {
  }

  /**
   * POST /v1/<project>/content/{entity}?lang= - needs a token whose client may create
   */
  public function create(#[RouteArgument('entity')] string $slug, ServerRequestInterface $request, JsonInput $input): ResponseInterface
  {
    $entity = $this->content->entity($slug);
    $language = $this->content->language($entity, (string)($request->getQueryParams()['lang'] ?? ''));
    return $this->responseFactory->success($this->content->create($entity, $input->toArray(), $language))->withStatus(201);
  }

  /**
   * PUT|PATCH /v1/<project>/content/{entity}/{id}?lang= - only the sent fields change
   */
  public function update(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, ServerRequestInterface $request, JsonInput $input): ResponseInterface
  {
    $entity = $this->content->entity($slug);
    $language = $this->content->language($entity, (string)($request->getQueryParams()['lang'] ?? ''));
    return $this->responseFactory->success($this->content->update($entity, $id, $input->toArray(), $language));
  }

  /**
   * DELETE /v1/<project>/content/{entity}/{id}
   */
  public function delete(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id): ResponseInterface
  {
    $this->content->delete($this->content->entity($slug), $id);
    return $this->responseFactory->success(['deleted' => true]);
  }

  /**
   * GET /v1/<project>/variables?lang= - the project's variables ({{url}} ...), lang=all: every language
   */
  public function variables(ServerRequestInterface $request, ProjectVariables $variables, CurrentProject $project): ResponseInterface
  {
    $language = strtolower(trim((string)($request->getQueryParams()['lang'] ?? '')));
    if ('' !== $language && 'all' !== $language && !in_array($language, $project->get()->languages, true)) {
      return $this->responseFactory->fail(I18n::t('Unknown language "{language}".', ['language' => $language]), httpCode: 422, errorCode: 'validation');
    }
    return $this->responseFactory->success((object)$variables->values('' === $language ? null : $language));
  }

  /**
   * GET /v1/content
   */
  public function index(): ResponseInterface
  {
    return $this->responseFactory->success($this->content->entities());
  }

  /**
   * GET /v1/<project>/content/{entity}?page=&limit=&s=&filter[…]=&sort=&fields=&include=&tree=1&lang=en
   */
  public function list(#[RouteArgument('entity')] string $slug, ServerRequestInterface $request): ResponseInterface
  {
    $this->content->preview($request->getHeaderLine('X-Preview-Token') ?: (string)($request->getQueryParams()['preview'] ?? ''));
    $entity = $this->content->entity($slug);
    $list = ListRequest::from($request, 25);
    $params = $request->getQueryParams();
    $fields = $this->content->fields($entity, (string)($params['fields'] ?? ''));
    $include = $this->content->includes($entity, (string)($params['include'] ?? ''), $fields);
    $language = $this->content->language($entity, (string)($params['lang'] ?? ''));
    // ?render=html: blocks with the HTML of their templates ("_html")
    $this->content->renderHtml('html' === ($params['render'] ?? null), self::apiUrl($request));

    if (filter_var($params['tree'] ?? false, FILTER_VALIDATE_BOOL)) {
      return $this->noStore($this->responseFactory->success($this->content->tree($entity, $list->s, $list->filter, $list->sort, $fields, $include, $language)));
    }
    return $this->noStore($this->responseFactory->paginated(
      $list,
      $this->content->query($entity, $list->s, $list->filter, $list->sort, $language),
      new AsIsPresenter(),
      decorate: fn(array $rows): array => $this->content->present($entity, $rows, $fields, $include, $language),
    ));
  }

  /**
   * GET /v1/content/{entity}/{id}?fields=&include=
   */
  public function get(#[RouteArgument('entity')] string $slug, #[RouteArgument('id')] string $id, ServerRequestInterface $request): ResponseInterface
  {
    $this->content->preview($request->getHeaderLine('X-Preview-Token') ?: (string)($request->getQueryParams()['preview'] ?? ''));
    $entity = $this->content->entity($slug);
    $params = $request->getQueryParams();
    $fields = $this->content->fields($entity, (string)($params['fields'] ?? ''));
    $include = $this->content->includes($entity, (string)($params['include'] ?? ''), $fields);
    $language = $this->content->language($entity, (string)($params['lang'] ?? ''));
    // ?render=html: blocks with the HTML of their templates ("_html")
    $this->content->renderHtml('html' === ($params['render'] ?? null), self::apiUrl($request));

    return $this->noStore($this->responseFactory->success($this->content->get($entity, $id, $fields, $include, $language)));
  }

  /**
   * POST /v1/<project>/content/{entity}/render {field, blocks} - the HTML of unsaved blocks (live
   * editing): only with a preview token.
   */
  public function render(#[RouteArgument('entity')] string $slug, ServerRequestInterface $request): ResponseInterface
  {
    $this->content->preview($request->getHeaderLine('X-Preview-Token') ?: (string)($request->getQueryParams()['preview'] ?? ''));
    if (!$this->content->isPreview()) {
      throw \App\Shared\Exception\UserFacingException::forbidden(\App\Shared\I18n::t('Only previews can render unsaved blocks.'));
    }
    $entity = $this->content->entity($slug);
    $this->content->renderHtml(true, self::apiUrl($request));
    $body = json_decode((string)$request->getBody(), true);
    $body = is_array($body) ? $body : [];
    return $this->noStore($this->responseFactory->success($this->content->renderBlocks($entity, (string)($body['field'] ?? ''), array_values((array)($body['blocks'] ?? [])))));
  }

  /**
   * The content API of the project as absolute address (for templates: forms send there).
   */
  private static function apiUrl(ServerRequestInterface $request): string
  {
    $uri = $request->getUri();
    $path = (string)preg_replace('#/content(/.*)?$#', '', $uri->getPath());
    return $uri->getScheme().'://'.$uri->getAuthority().$path;
  }

  /**
   * Previews are never cached (by the browser, a CDN or the website).
   */
  private function noStore(ResponseInterface $response): ResponseInterface
  {
    return $this->content->isPreview() ? $response->withHeader('Cache-Control', 'no-store') : $response;
  }
}
