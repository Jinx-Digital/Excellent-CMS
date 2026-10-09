---
title: Plugins
summary: Field types, blocks, event steps and pages from plugins - and writing one.
parent: concepts
order: 150
---
# Plugins

Plugins extend the CMS without Composer and without a build: a plugin is a ZIP with a `plugin.json`, its PHP classes
and its migrations. Admins manage them under *Administration › Plugins*:

| Action | What happens |
| --- | --- |
| Upload | The ZIP is checked (no paths outside its folder, only code, data and asset files) and unpacked into `plugins/<name>/`. A new version replaces the files. |
| Install / Update | The migrations of the plugin that have not run yet run. Then it is installed but inactive. |
| Activate | Only a switch - **per project**: a plugin is active in the projects chosen for it, unless its `plugin.json` says `"scope": "global"` (pure admin plugins, e.g. storage types: in every project). The CMS loads in every request only the plugins of its project (field types, blocks, events, routes, pages); plugins active elsewhere only bring their storage types. Before activating, a plugin is started once on trial: one that fails is not switched on. Its `onActivate` hook runs for every project it enters. |
| Deactivate | Switch off. Its data and tables stay. |
| Uninstall | Its migrations run backwards, and its settings, row and files are removed. |

- **Safe mode:** a plugin that throws while starting is switched off, and the admin app shows the error.
  `PLUGINS_ENABLED=false` in the `.env` turns all plugins off (and their upload). `PLUGINS_DIR` is the folder
  (default `plugins/`, must be writable, outside the document root).
- **Dependencies:** `"requires": {"forms": "^1.0"}` in `plugin.json` (version constraints as in Composer, `"*"`: any).
  A plugin installs only when the plugins it needs are installed in a fitting version, activates only where they are
  active, and they are loaded before it. A plugin that others need cannot be deactivated (where they are active) or
  uninstalled. The plugin page shows "Needs …" and "Needed by …".
- **Settings** come from `plugin.json` and are edited on the page of the plugin. Like storages, they take values or
  `$NAME` `.env` variables, and secrets are stored encrypted and never returned.
- Plugins run with all rights on the server. Only install plugins you trust.
- API: `GET /api/v1/admin/plugins`, `POST …/plugins/upload` (multipart `file`), `GET …/plugins/{name}`,
  `POST …/plugins/{name}/install|activate|deactivate` (activate: `{"projects": [ids]}` - without: the current project), `PUT …/plugins/{name}/settings`, `DELETE …/plugins/{name}`,
  `GET …/plugins/steps` (event steps of the active plugins).

**Writing a plugin** (example: `notifier` of the plugins project (`excellent-plugins/notifier`, next to the CMS), "Chat notifications" for
Slack, Discord, Teams, Google Chat; `make plugin-zip name=notifier` builds the ZIP):

```
notifier/
  plugin.json                          name, label, version, class, autoload, settings
  src/Plugin.php                       implements App\Plugin\PluginInterface
  migrations/2026_10_08_000001_log.php returns a App\Plugin\PluginMigration (up/down), run in file name order
```

```json
{
  "name": "notifier", "label": "Chat notifications", "version": "1.0.0",
  "class": "ExcellentPlugins\\Notifier\\Plugin",
  "autoload": { "ExcellentPlugins\\Notifier\\": "src/" },
  "settings": [{ "key": "webhook_url", "label": "Incoming webhook", "kind": "secret", "required": true }]
}
```

```php
final class Plugin implements PluginInterface
{
    public function register(PluginRegistry $registry, PluginContext $context): void
    {
        // An event step "notifier.message": a form from its fields, run like the built-in steps
        $registry->eventStep('message', 'Chat message', fields: [
            ['key' => 'text', 'label' => 'Message', 'kind' => 'textarea', 'required' => true],
        ], handler: function (array $step, StepRun $run) use ($context): void {
            $run->postJson($context->setting('webhook_url'), ['text' => $run->render($step['text'])]);
        });
        // A table on the page of the plugin
        $registry->panel('log', 'Last messages', fn() => ['columns' => [...], 'rows' => [...]]);
    }
}
```

- Field kinds of settings and steps: `text` (value or `$NAME`; texts of steps also take `{{record.title}}` …),
  `secret`, `textarea`, `select` (`options`), `bool`.
  Settings with `"when": {"provider": "google"}` only show (and are only required) while the other settings have
  these values.

**Extension points** of `PluginRegistry`:

| Method | Adds |
| --- | --- |
| `eventStep($key, $label, fields, handler)` | a step for events (`<plugin>.<key>`), with a form in the event editor |
| `fieldType($key, $label, component, toStorage, fromStorage, present, text, mediaIds, config)` | a field type (`<plugin>.<key>`) for entities and field groups (blocks too). The hooks check and convert values, shape the output (e.g. fresh image addresses), feed the search and report the files they use. The admin app shows the plugin's **web component**: an ES module of its `assets/` that gets `value`, `config`, `disabled`, `field` and `host` (`pickMedia()`, `api()`, `locale()`) and sends `change` events. Without the active plugin, values are kept and delivered as stored. |
| `storageType($type, $label, fields, factory)` | a type of storage (Administration › Storages), whose factory returns the Flysystem adapter |
| `route($method, $path, handler, admin)` | `/api/v1/plugins/<plugin>/api/<path>` for signed-in users (or admins only) |
| `publicRoute($method, $path, handler)` | `/api/v1/<project>/plugins/<plugin>/<path>` for websites (no sign-in, rate limited) – e.g. sending a form. Paths take parameters: `submit/{form}` |
| `eventSource($key, $label, actions, targets, sample)` | something events react to (`<plugin>.<key>`, *Events › Reacts to*), optionally limited to a target (e.g. one form); the plugin starts them with `PluginContext::trigger()` |
| `blockGroup($name, $label, fields)` | a block the CMS creates as field group in every project (on activation and in new projects); it then belongs to the project |
| `onActivate(fn(ProjectSetup $project))` / `onDeactivate(…)` | hooks for every project the plugin is activated in / deactivated in: create entities, records, events (`entity()`, `record()`, `event()`, `blocks()`, `admins()`). Entities (`entity($data)`, unless `managed: false`) and blocks a plugin creates are **managed** by it: their fields are locked (no deleting, renaming, other types), they cannot be deleted or renamed - labels and own fields may change. Uninstalling removes the protection, the content stays. |
| `adminPage($key, $label, component)` | a page in the menu *Extensions*: the plugin's web component, with `host` (`api()`, `toast()`, `project()`, `pickMedia()`, `locale()`) |
| `panel($key, $title, provider)` | a table on the page of the plugin |

Files of `assets/` are served at `/api/v1/plugins/<plugin>/assets/…`. Plugins that need libraries ship them:
`make plugin-zip name=<plugin>` (`bin/plugin-zip.php`) runs `composer install` (if there is a `composer.json`, loaded
by `"bootstrap": "vendor/autoload.php"`) and `npm install && node build.mjs` (if there is a `package.json`, e.g. to
build a web component with esbuild) before packing. The server never runs Composer or Node.

**Plugins of the plugins project** (`excellent-plugins`, a folder next to the CMS - `make plugin-zip name=…` builds their ZIPs; another place: `PLUGINS_SOURCE=…`):

| Plugin | What it does |
| --- | --- |
| `notifier` | event step "Chat message" for Slack, Discord, Teams, Google Chat, with a log |
| `storage-servers` | storage types FTP/FTPS, SFTP, WebDAV (brings phpseclib, sabre/dav) |
| `richtext` | field type *Rich text (HTML)*: TipTap editor, cleaned HTML, images of the library, tables |
| `geo` | field type *Map position (GPS)*: OpenStreetMap or Google Maps (API key in the settings), address search |
| `user-field` | field type *User*: a user of the project, delivered as `{id, name}` |
| `seo` | field group *SEO* (title, description, image, canonical, noindex), redirects (`/old` and `/blog/*`, 301/302/410, counted) and `sitemap.xml` of the published records; `ExcellentCms\Sdk\Seo` in the PHP SDK |
| `forms` | forms as records of the entity "Forms" with a block editor (field blocks, columns), block "form", event source *Form submissions*, example "Contact" with event; templates render the forms for websites (`?render=html`) |
- `StepRun`: `records`, `event`, `render($text, $index)` (placeholders and `$NAME`), `env()`, `progress()`,
  `postJson()`.
- `PluginContext`: `setting()`, `settings()`, `db` (the database), `path`, `trigger()` (events of its sources).
- Namespaces of the CMS (`App\`) are reserved. Plugins load only classes from their own folder.
