<?php

declare(strict_types=1);

namespace App\Api\Controller\Admin;

use App\Api\Input\JsonInput;
use App\Plugin\PluginManager;
use App\Plugin\PluginStep;
use App\Presentation\Api\Shared\ResponseFactory\ResponseFactory;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Plugins (see PluginManager): list, upload (ZIP), install / update, activate, deactivate,
 * uninstall, settings, panels - and the event steps of the active plugins for the event editor.
 */
final class PluginController
{
  public function __construct(
    private ResponseFactory $responseFactory,
    private PluginManager $plugins,
  ) {
  }

  public function list(): ResponseInterface
  {
    return $this->responseFactory->success(['enabled' => $this->plugins->isEnabled(), 'plugins' => $this->plugins->all()]);
  }

  public function get(#[RouteArgument('name')] string $name): ResponseInterface
  {
    return $this->responseFactory->success($this->plugins->get($name) + ['panels' => $this->plugins->panels($name)]);
  }

  public function upload(ServerRequestInterface $request): ResponseInterface
  {
    $file = $request->getUploadedFiles()['file'] ?? null;
    if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
      throw ValidationException::field('file', I18n::t('Please choose a ZIP file.'));
    }
    return $this->responseFactory->success($this->plugins->upload($file));
  }

  public function install(#[RouteArgument('name')] string $name): ResponseInterface
  {
    return $this->responseFactory->success($this->plugins->install($name));
  }

  /**
   * POST /v1/admin/plugins/{name}/sync - {"templates": bool}: creates the plugin's blocks missing in its
   * projects (e.g. new ones of an update); with templates, its blocks get the plugin's templates again
   * (also ones changed in the admin app). As `./yii plugins:sync --plugin=…`.
   */
  public function sync(#[RouteArgument('name')] string $name, JsonInput $input, \App\Application\Schema\PluginSetup $setup): ResponseInterface
  {
    $this->plugins->get($name);
    $count = $setup->ensure(null, $name, (bool)($input->toArray()['templates'] ?? false));
    return $this->responseFactory->success(['count' => $count]);
  }

  /**
   * POST /v1/admin/plugins/{name}/activate - global plugins: in every project; plugins of projects:
   * {"projects": [ids]} exactly these, without: the current project too. The hooks of the plugin run
   * for the projects it leaves (before) and enters (after).
   */
  public function activate(#[RouteArgument('name')] string $name, JsonInput $input, \App\Application\Schema\PluginSetup $setup): ResponseInterface
  {
    $wanted = $input->toArray()['projects'] ?? null;
    $wanted = is_array($wanted) ? array_values(array_map('strval', $wanted)) : null;
    $before = $this->plugins->get($name)['projects'];
    if (null !== $wanted && !$this->plugins->isGlobal($name)) {
      $setup->hook($name, array_values(array_diff($before, $wanted)), false);
    }
    $this->plugins->activate($name, $wanted);
    $after = $this->plugins->get($name)['projects'];
    // Blocks of global plugins in every project; the others get theirs with their hook
    $setup->ensure();
    $setup->hook($name, array_values(array_diff($after, $before)), true);
    return $this->responseFactory->success($this->plugins->get($name));
  }

  /**
   * POST /v1/admin/plugins/{name}/deactivate - everywhere (the hooks run for its projects before).
   */
  public function deactivate(#[RouteArgument('name')] string $name, \App\Application\Schema\PluginSetup $setup): ResponseInterface
  {
    $setup->hook($name, $this->plugins->get($name)['projects'], false);
    return $this->responseFactory->success($this->plugins->deactivate($name));
  }

  public function uninstall(#[RouteArgument('name')] string $name): ResponseInterface
  {
    $this->plugins->uninstall($name);
    return $this->responseFactory->success(['uninstalled' => true]);
  }

  public function settings(#[RouteArgument('name')] string $name, JsonInput $input): ResponseInterface
  {
    return $this->responseFactory->success($this->plugins->saveSettings($name, $input->toArray()));
  }

  /**
   * GET /v1/admin/plugins/field-types - field types of the active plugins (field dialog)
   */
  public function fieldTypes(): ResponseInterface
  {
    $this->plugins->registry();
    return $this->responseFactory->success(array_values(array_map(static fn(\App\Domain\Schema\CustomFieldType $type): ?array => \App\Domain\Schema\CustomFieldTypes::describe($type->type), \App\Domain\Schema\CustomFieldTypes::all())));
  }

  /**
   * GET /v1/admin/plugins/event-sources - event sources of the active plugins (event editor)
   */
  public function eventSources(\App\Application\Event\EventService $events): ResponseInterface
  {
    return $this->responseFactory->success($events->pluginSources());
  }

  /**
   * GET /v1/admin/plugins/steps - event steps of the active plugins (event editor)
   */
  public function steps(): ResponseInterface
  {
    return $this->responseFactory->success(array_values(array_map(static fn(PluginStep $step): array => $step->toArray(), $this->plugins->registry()->steps())));
  }
}
