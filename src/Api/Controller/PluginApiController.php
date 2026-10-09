<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Application\Service\CurrentProject;
use App\Application\Service\CurrentUser;
use App\Plugin\PluginManager;
use App\Plugin\PluginRequest;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * What active plugins serve:
 *
 *   GET  /v1/plugins/{plugin}/assets/{path}             files of their assets/ (web components of the admin app)
 *   *    /v1/plugins/{plugin}/api/{path}                their routes (PluginRegistry::route), signed-in users
 *   *    /v1/{project}/plugins/{plugin}/{path}          their public routes (publicRoute), e.g. to send a form
 *   GET  /v1/plugins/pages                              their pages of the admin app the user may open
 */
final class PluginApiController
{
  private const TYPES = [
    'js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'html' => 'text/html; charset=utf-8', 'txt' => 'text/plain; charset=utf-8',
  ];

  public function __construct(
    private PluginManager $plugins,
    private ResponseFactory $responseFactory,
    private ResponseFactoryInterface $responses,
    private StreamFactoryInterface $streams,
  ) {
  }

  public function asset(#[RouteArgument('plugin')] string $plugin, #[RouteArgument('path')] string $path): ResponseInterface
  {
    $file = $this->plugins->asset($plugin, $path);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (null === $file || !isset(self::TYPES[$extension])) {
      throw UserFacingException::notFound(I18n::t('This file does not exist.'));
    }
    return $this->responses->createResponse(200)
      ->withHeader('Content-Type', self::TYPES[$extension])
      ->withHeader('Cache-Control', 'no-cache')
      ->withHeader('X-Content-Type-Options', 'nosniff')
      ->withBody($this->streams->createStreamFromFile($file));
  }

  public function call(
    #[RouteArgument('plugin')] string $plugin,
    #[RouteArgument('path')] string $path,
    ServerRequestInterface $request,
    CurrentUser $user,
    CurrentProject $project,
  ): ResponseInterface {
    $route = $this->plugins->registry()->findRoute($plugin, $request->getMethod(), $path);
    $context = $this->plugins->context($plugin);
    if (null === $route || null === $context) {
      throw UserFacingException::notFound(I18n::t('This URL does not exist.'));
    }
    if ($route['admin'] && !$user->isAdmin()) {
      throw UserFacingException::forbidden(I18n::t('Only administrators may do this.'));
    }
    $body = $request->getParsedBody();
    if (!is_array($body)) {
      $decoded = json_decode((string)$request->getBody(), true);
      $body = is_array($decoded) ? $decoded : [];
    }
    $data = ($route['handler'])(new PluginRequest($request->getMethod(), $path, $request->getQueryParams(), $body, $project->find()?->id, $user->getId(), $user->isAdmin(), $context, $route['params']));
    return $data instanceof ResponseInterface ? $data : $this->responseFactory->success(self::data($data));
  }

  /**
   * What a handler returned as data of the answer - plain values as {"value": …}.
   */
  private static function data(mixed $data): array|object|null
  {
    return null === $data || is_array($data) || is_object($data) ? $data : ['value' => $data];
  }

  /**
   * A public route of a plugin in a project (no sign-in): JSON or form data.
   */
  public function publicCall(
    #[RouteArgument('plugin')] string $plugin,
    #[RouteArgument('path')] string $path,
    ServerRequestInterface $request,
    CurrentProject $project,
  ): ResponseInterface {
    $route = $this->plugins->registry()->findPublicRoute($plugin, $request->getMethod(), $path);
    $context = $this->plugins->context($plugin);
    if (null === $route || null === $context) {
      throw UserFacingException::notFound(I18n::t('This URL does not exist.'));
    }
    $body = $request->getParsedBody();
    if (!is_array($body) || [] === $body) {
      $decoded = json_decode((string)$request->getBody(), true);
      $body = is_array($decoded) ? $decoded : (is_array($body) ? $body : []);
    }
    $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
    $data = ($route['handler'])(new PluginRequest($request->getMethod(), $path, $request->getQueryParams(), $body, $project->find()?->id, null, false, $context, $route['params'], '' !== $ip ? $ip : null));
    if ($data instanceof ResponseInterface) {
      return $data;
    }
    // A plain HTML form (no JavaScript): back to the page - or to the address the plugin names
    if ('POST' === $request->getMethod() && str_contains($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded') && str_contains($request->getHeaderLine('Accept'), 'text/html')) {
      $target = is_array($data) && is_string($data['redirect'] ?? null) && '' !== $data['redirect'] ? $data['redirect'] : $request->getHeaderLine('Referer');
      if (1 === preg_match('#^(https?://|/)#', $target)) {
        $target .= (str_contains($target, '?') ? '&' : '?').'sent='.rawurlencode($plugin);
        return $this->responses->createResponse(303)->withHeader('Location', $target);
      }
    }
    return $this->responseFactory->success(self::data($data));
  }

  /**
   * The pages of the active plugins the user may open (menu of the admin app).
   */
  public function pages(CurrentUser $user): ResponseInterface
  {
    $pages = [];
    foreach ($this->plugins->registry()->pages() as $page) {
      if ($page['admin'] && !$user->isAdmin()) {
        continue;
      }
      $pages[] = ['plugin' => $page['plugin'], 'key' => $page['key'], 'label' => $page['label'], 'icon' => $page['icon'], 'component' => $page['component'], 'config' => (object)(null !== $page['config'] ? ($page['config'])() : [])];
    }
    return $this->responseFactory->success($pages);
  }
}
