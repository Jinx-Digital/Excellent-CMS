<?php

declare(strict_types=1);

namespace App\Plugin;

use InvalidArgumentException;

/**
 * What the active plugins add. Each plugin registers under its own name: its event steps are
 * "<plugin>.<key>" (e.g. "notifier.message"), its panels show on its page in the admin app.
 *
 * Fields (of steps, like the settings of plugin.json): {key, label, kind: text|textarea|select|bool,
 * required?, placeholder?, help?, options?: [{value, label}], default?}. Texts of steps may use the
 * placeholders of events ({{record.title}}) and $NAME .env variables.
 */
final class PluginRegistry
{
  /** @var array<string, PluginStep> type => step */
  private array $steps = [];
  /** @var array<string, array{plugin: string, label: string, description: string, fields: list<array>, factory: \Closure}> type => storage type */
  private array $storageTypes = [];
  /** @var array<string, \App\Domain\Schema\CustomFieldType> type => field type */
  private array $fieldTypes = [];
  /** @var array<string, array<string, array{handler: \Closure, admin: bool}>> plugin => "METHOD path" => route */
  private array $routes = [];
  /** @var array<string, array<string, \Closure>> plugin => "METHOD path" => handler of public routes */
  private array $publicRoutes = [];
  /** @var array<string, array{plugin: string, label: string, actions: array<string, string>, targets: ?\Closure, sample: ?\Closure, targetLabel: string}> source => event source */
  private array $eventSources = [];
  /** @var array<string, array{plugin: string, key: string, label: string, icon: string, component: array{tag: string, script: string}, admin: bool}> "plugin/key" => page */
  private array $pages = [];
  /** @var array<string, array<string, array{title: string, provider: \Closure}>> plugin => key => panel */
  private array $panels = [];
  /** @var array<string, array{plugin: string, name: string, label: string, description: string, fields: list<array>, kind: string, category: ?string, template: ?string}> name => block */
  private array $blockGroups = [];
  /** @var array<string, \Closure(ProjectSetup): void> plugin => hook when it is activated in a project */
  private array $onActivate = [];
  /** @var array<string, \Closure(ProjectSetup): void> plugin => hook when it is deactivated in a project */
  private array $onDeactivate = [];
  private ?string $plugin = null;

  /**
   * @internal the manager sets the plugin that registers
   */
  public function for(string $plugin): self
  {
    $this->plugin = $plugin;
    return $this;
  }

  /**
   * An event step: shown in the event editor, run by events like the built-in steps.
   *
   * @param list<array<string, mixed>> $fields
   * @param \Closure(array<string, mixed>, StepRun): void $handler runs the step - throws on failure
   * @param (\Closure(array<string, mixed>, StepRun): mixed)|null $preview what a test run shows
   */
  public function eventStep(string $key, string $label, array $fields, \Closure $handler, string $description = '', string $icon = 'i-lucide-puzzle', ?\Closure $preview = null): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
      throw new InvalidArgumentException(sprintf('Plugin "%s": "%s" is no valid step key (a-z, 0-9, _).', $plugin, $key));
    }
    $type = $plugin.'.'.$key;
    $this->steps[$type] = new PluginStep($plugin, $type, $label, $description, $icon, array_values($fields), $handler, $preview);
  }

  /**
   * A type of storage for uploads (Administration › Storages), e.g. "sftp". Its settings are fields
   * like the ones of plugin.json (kinds text, secret, key = multi-line secret, bool); the factory gets
   * the name of the storage and the resolved settings and returns the Flysystem adapter. Private or
   * public (URL) is handled by the CMS.
   *
   * @param list<array<string, mixed>> $fields
   * @param \Closure(string, array<string, mixed>): \League\Flysystem\FilesystemAdapter $factory
   */
  public function storageType(string $type, string $label, array $fields, \Closure $factory, string $description = ''): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    if (1 !== preg_match('/^[a-z][a-z0-9_]{0,19}$/', $type)) {
      throw new InvalidArgumentException(sprintf('Plugin "%s": "%s" is no valid storage type (a-z, 0-9, _).', $plugin, $type));
    }
    $this->storageTypes[$type] = ['plugin' => $plugin, 'label' => $label, 'description' => $description, 'fields' => array_values($fields), 'factory' => $factory];
  }

  /**
   * @return array<string, array{plugin: string, label: string, description: string, fields: list<array>, factory: \Closure}>
   */
  public function storageTypes(): array
  {
    return $this->storageTypes;
  }

  /**
   * A field type ("<plugin>.<key>", e.g. "geo.point"): for entities and field groups (blocks too).
   * Values are stored as text; the hooks (all optional) decide what that means:
   *
   *   toStorage(mixed $value, FieldDefinition $field): ?string   check and convert - throw \InvalidArgumentException
   *   fromStorage(?string $stored): mixed                        the value of the API (default: JSON or text)
   *   present(mixed $value, array $files): mixed                 output (files: presented files by id)
   *   text(mixed $value): string                                 what the search finds
   *   mediaIds(mixed $value): list<string>                       files it uses
   *   config(): array                                            public config for the component
   *
   * The admin app shows the web component of the plugin: $component = ['tag' => 'geo-point-input',
   * 'script' => 'assets/geo-point.js'] - an ES module of the plugin's folder that defines the
   * element. It gets the properties value, config, disabled, field and host (pickMedia(), api(),
   * locale) and sends "change" events with the new value as detail.
   *
   * @param array{tag: string, script: string}|null $component
   */
  public function fieldType(string $key, string $label, ?array $component = null, ?\Closure $toStorage = null, ?\Closure $fromStorage = null, ?\Closure $present = null, ?\Closure $text = null, ?\Closure $mediaIds = null, ?\Closure $config = null, string $description = '', string $icon = 'i-lucide-puzzle'): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
      throw new InvalidArgumentException(sprintf('Plugin "%s": "%s" is no valid field type key (a-z, 0-9, _).', $plugin, $key));
    }
    if (null !== $component) {
      if (1 !== preg_match('/^[a-z][a-z0-9]*-[a-z0-9-]+$/', (string)($component['tag'] ?? '')) || !str_starts_with((string)($component['script'] ?? ''), 'assets/')) {
        throw new InvalidArgumentException(sprintf('Plugin "%s": the component needs a tag with "-" and a script in assets/.', $plugin));
      }
      $component['script'] = '/api/v1/plugins/'.$plugin.'/'.ltrim($component['script'], '/');
    }
    $type = $plugin.'.'.$key;
    $this->fieldTypes[$type] = new \App\Domain\Schema\CustomFieldType($type, $plugin, $label, $description, $icon, $component, $toStorage, $fromStorage, $present, $text, $mediaIds, $config);
  }

  /**
   * @return array<string, \App\Domain\Schema\CustomFieldType>
   */
  public function fieldTypes(): array
  {
    return $this->fieldTypes;
  }

  /**
   * A route of the plugin's API: /api/v1/plugins/<plugin>/api/<path> - for signed-in users of the
   * admin app (admin: true - admins only). The handler returns the data of the answer (JSON).
   *
   * @param \Closure(PluginRequest): mixed $handler
   */
  public function route(string $method, string $path, \Closure $handler, bool $admin = false): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    $this->routes[$plugin][strtoupper($method).' '.trim($path, '/')] = ['handler' => $handler, 'admin' => $admin];
  }

  /**
   * The route of a request - paths may have parameters: "forms/{id}" (one segment each).
   *
   * @return array{handler: \Closure, admin: bool, params: array<string, string>}|null
   */
  public function findRoute(string $plugin, string $method, string $path): ?array
  {
    $found = self::match($this->routes[$plugin] ?? [], $method, $path);
    return null !== $found ? $found[0] + ['params' => $found[1]] : null;
  }

  /**
   * A public route of the plugin, per project: /api/v1/<project>/plugins/<plugin>/<path> - for
   * websites (no sign-in, rate limited like the content API), e.g. to send a form.
   *
   * @param \Closure(PluginRequest): mixed $handler
   */
  public function publicRoute(string $method, string $path, \Closure $handler): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    $this->publicRoutes[$plugin][strtoupper($method).' '.trim($path, '/')] = $handler;
  }

  /**
   * @return array{handler: \Closure, params: array<string, string>}|null
   */
  public function findPublicRoute(string $plugin, string $method, string $path): ?array
  {
    $found = self::match(array_map(static fn(\Closure $handler): array => ['handler' => $handler], $this->publicRoutes[$plugin] ?? []), $method, $path);
    return null !== $found ? $found[0] + ['params' => $found[1]] : null;
  }

  /**
   * Something events can react to (Events › Reacts to), "<plugin>.<key>" - e.g. the submissions of
   * forms. $actions: action => label; $targets (optional): what an event may be limited to (e.g. one
   * form), fn(string $projectId): list<{value, label}>; $sample: the data of one item for the test
   * run, fn(string $id, string $projectId): ?array. The plugin starts events with
   * PluginContext::trigger().
   *
   * @param array<string, string> $actions
   */
  public function eventSource(string $key, string $label, array $actions, ?\Closure $targets = null, ?\Closure $sample = null, string $targetLabel = ''): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    $this->eventSources[$plugin.'.'.$key] = ['plugin' => $plugin, 'label' => $label, 'actions' => $actions, 'targets' => $targets, 'sample' => $sample, 'targetLabel' => $targetLabel];
  }

  /**
   * @return array<string, array{plugin: string, label: string, actions: array<string, string>, targets: ?\Closure, sample: ?\Closure, targetLabel: string}>
   */
  public function eventSources(): array
  {
    return $this->eventSources;
  }

  /**
   * A page of the admin app (in the menu under "Plugins"): the plugin's web component, with the
   * same properties as the one of a field type (config, host) - e.g. to manage forms.
   *
   * @param array{tag: string, script: string} $component
   */
  public function adminPage(string $key, string $label, array $component, string $icon = 'i-lucide-puzzle', bool $admin = true, ?\Closure $config = null): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    if (1 !== preg_match('/^[a-z][a-z0-9-]*-[a-z0-9-]+$/', (string)($component['tag'] ?? '')) || !str_starts_with((string)($component['script'] ?? ''), 'assets/')) {
      throw new InvalidArgumentException(sprintf('Plugin "%s": the page needs a tag with "-" and a script in assets/.', $plugin));
    }
    $component['script'] = '/api/v1/plugins/'.$plugin.'/'.$component['script'];
    $this->pages[$plugin.'/'.$key] = ['plugin' => $plugin, 'key' => $key, 'label' => $label, 'icon' => $icon, 'component' => $component, 'admin' => $admin, 'config' => $config];
  }

  /**
   * @return array<string, array{plugin: string, key: string, label: string, icon: string, component: array{tag: string, script: string}, admin: bool, config: ?\Closure}>
   */
  public function pages(): array
  {
    return $this->pages;
  }

  /**
   * A block the plugin brings along: a field group the CMS creates in every project (when the plugin
   * is activated and in new projects), e.g. "form" with the field type of the plugin. It belongs to
   * the project then - editors may change it; it is not removed with the plugin (content uses it).
   * $fields like the fields of POST /admin/groups ([{name, label, type, required?, …}]) - they may
   * use blocks and field groups registered before (by name). $kind "group": a field group instead
   * (e.g. a column that blocks use). $category organizes it in the lists and the block picker,
   * $template is its HTML (Twig, see BlockTemplates) - websites without a template of their own
   * get it as "_html".
   *
   * @param list<array<string, mixed>> $fields
   */
  public function blockGroup(string $name, string $label, array $fields, string $description = '', string $kind = 'block', ?string $category = null, ?string $template = null): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
      throw new InvalidArgumentException(sprintf('Plugin "%s": "%s" is no valid block name (a-z, 0-9, _).', $plugin, $name));
    }
    $this->blockGroups[$name] = ['plugin' => $plugin, 'name' => $name, 'label' => $label, 'description' => $description, 'fields' => array_values($fields), 'kind' => 'group' === $kind ? 'group' : 'block', 'category' => $category, 'template' => $template];
  }

  /**
   * @return array<string, array{plugin: string, name: string, label: string, description: string, fields: list<array>}>
   */
  public function blockGroups(): array
  {
    return $this->blockGroups;
  }

  /**
   * Called whenever the plugin is activated in a project (plugins of projects, not "global"), after
   * its blocks were created there - e.g. to create entities, example records and events (see
   * ProjectSetup). Also on a second activation: the hook checks what exists already. What it
   * creates belongs to the project.
   *
   * @param \Closure(ProjectSetup): void $hook
   */
  public function onActivate(\Closure $hook): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    $this->onActivate[$plugin] = $hook;
  }

  /**
   * Called whenever the plugin is deactivated in a project (before) - e.g. to switch off its events.
   * Content stays.
   *
   * @param \Closure(ProjectSetup): void $hook
   */
  public function onDeactivate(\Closure $hook): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    $this->onDeactivate[$plugin] = $hook;
  }

  /**
   * @return \Closure(ProjectSetup): void|null
   */
  public function activationHook(string $plugin, bool $activate = true): ?\Closure
  {
    return ($activate ? $this->onActivate : $this->onDeactivate)[$plugin] ?? null;
  }

  /**
   * @param array<string, array> $routes "METHOD path" => route
   * @return array{0: array, 1: array<string, string>}|null the route and its parameters
   */
  private static function match(array $routes, string $method, string $path): ?array
  {
    $path = trim($path, '/');
    $method = strtoupper($method);
    if (isset($routes[$method.' '.$path])) {
      return [$routes[$method.' '.$path], []];
    }
    foreach ($routes as $key => $route) {
      [$routeMethod, $pattern] = explode(' ', $key, 2) + [1 => ''];
      if ($routeMethod !== $method || !str_contains($pattern, '{')) {
        continue;
      }
      $regex = '#^'.preg_replace('#\\\{([a-z_][a-z0-9_]*)\\\}#i', '(?P<$1>[^/]+)', preg_quote($pattern, '#')).'$#';
      if (1 === preg_match($regex, $path, $matches)) {
        return [$route, array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)];
      }
    }
    return null;
  }

  /**
   * A panel on the page of the plugin: a table, read when the page is opened.
   *
   * @param \Closure(): array{columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>} $provider
   */
  public function panel(string $key, string $title, \Closure $provider): void
  {
    $plugin = $this->plugin ?? throw new InvalidArgumentException('No plugin is registering.');
    $this->panels[$plugin][$key] = ['title' => $title, 'provider' => $provider];
  }

  public function step(string $type): ?PluginStep
  {
    return $this->steps[$type] ?? null;
  }

  /**
   * @return array<string, PluginStep>
   */
  public function steps(): array
  {
    return $this->steps;
  }

  /**
   * @return array<string, array{title: string, provider: \Closure}>
   */
  public function panels(string $plugin): array
  {
    return $this->panels[$plugin] ?? [];
  }

  /**
   * @internal a plugin that is not active in the project of the request: only its storage types
   * stay (storages are shared by all projects)
   */
  public function onlyStorage(string $plugin): void
  {
    $types = array_filter($this->storageTypes, static fn(array $type): bool => $type['plugin'] === $plugin);
    $this->forget($plugin);
    $this->storageTypes += $types;
  }

  /**
   * @internal everything of a plugin that failed while registering
   */
  public function forget(string $plugin): void
  {
    $this->steps = array_filter($this->steps, static fn(PluginStep $step): bool => $step->plugin !== $plugin);
    $this->storageTypes = array_filter($this->storageTypes, static fn(array $type): bool => $type['plugin'] !== $plugin);
    $this->fieldTypes = array_filter($this->fieldTypes, static fn(\App\Domain\Schema\CustomFieldType $type): bool => $type->plugin !== $plugin);
    unset($this->routes[$plugin], $this->publicRoutes[$plugin]);
    $this->eventSources = array_filter($this->eventSources, static fn(array $source): bool => $source['plugin'] !== $plugin);
    $this->pages = array_filter($this->pages, static fn(array $page): bool => $page['plugin'] !== $plugin);
    $this->blockGroups = array_filter($this->blockGroups, static fn(array $block): bool => $block['plugin'] !== $plugin);
    unset($this->onActivate[$plugin], $this->onDeactivate[$plugin]);
    unset($this->panels[$plugin]);
  }
}
