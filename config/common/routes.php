<?php

declare(strict_types=1);

use App\Api\Controller\Admin\ClientController;
use App\Api\Controller\Admin\GroupController;
use App\Api\Controller\Admin\ProjectController;
use App\Api\Controller\Admin\SchemaController;
use App\Api\Controller\Admin\SettingsController;
use App\Api\Controller\Admin\EntityCopyController;
use App\Api\Controller\Admin\EnvController;
use App\Api\Controller\Admin\StorageController;
use App\Api\Controller\Admin\PluginController;
use App\Api\Controller\PluginApiController;
use App\Api\Controller\Admin\EventController;
use App\Api\Controller\Admin\RoleController;
use App\Api\Controller\Admin\SearchIndexController;
use App\Api\Controller\Cms\SearchController;
use App\Api\Controller\Admin\UserController;
use App\Api\Controller\Auth\AccountController;
use App\Api\Controller\Auth\AuthController;
use App\Api\Controller\Auth\OAuthController;
use App\Api\Controller\Auth\PreferenceController;
use App\Api\Controller\Cms\ImportController;
use App\Api\Controller\Cms\MediaController;
use App\Api\Controller\Content\ContentMediaController;
use App\Api\Controller\Content\MediaFileController;
use App\Api\Controller\Cms\RecordController;
use App\Api\Controller\Content\ContentController;
use App\Api\Middleware\AuthMiddleware;
use App\Api\Middleware\ContentAuthMiddleware;
use App\Api\Middleware\JsonRequestMiddleware;
use App\Api\Middleware\ProjectPathMiddleware;
use App\Api\Middleware\RateLimitMiddleware;
use App\Application\Project\ProjectService;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * Three areas under /api/v1:
 *   /<project>/content/…  headless content API of a project (public or OAuth bearer token, optional rate limit)
 *   /<project>/media/…    media of a project for OAuth clients with the media permissions
 *   /oauth/token, /<project>/oauth/token  token endpoint for OAuth clients
 *   /media/<path> (outside /api)  files of the local storage, see MediaFileController
 *   everything else: admin app (JWT of a CMS user; entity permissions are checked per request,
 *   /admin/… only for admins)
 */
$admin = static fn(AuthMiddleware $m) => $m->adminOnly();
// A project in the path - never one of the API's own names, so /admin/variables is not "the variables of project admin"
$project = '{project:(?!(?:'.implode('|', ProjectService::RESERVED).')(?:/|$))[^/]+}';

return [
  Group::create('/v1')
    ->middleware(JsonRequestMiddleware::class)
    ->routes(
      // Headless content API of a project
      Group::create('/'.$project.'/content')
        ->middleware(ProjectPathMiddleware::class)
        ->middleware(ContentAuthMiddleware::class)
        ->middleware(RateLimitMiddleware::class)
        ->routes(
          Route::get('')->action([ContentController::class, 'index'])->name('v1.content.index'),
          Route::get('/{entity}')->action([ContentController::class, 'list'])->name('v1.content.list'),
          Route::get('/{entity}/{id}')->action([ContentController::class, 'get'])->name('v1.content.get'),
          // Writing: OAuth clients with the permission for the entity
          // HTML of unsaved blocks (live editing, preview token)
          Route::post('/{entity}/render')->action([ContentController::class, 'render'])->name('v1.content.render'),
          Route::post('/{entity}')->action([ContentController::class, 'create'])->name('v1.content.create'),
          Route::put('/{entity}/{id}')->action([ContentController::class, 'update'])->name('v1.content.update'),
          Route::patch('/{entity}/{id}')->action([ContentController::class, 'update'])->name('v1.content.patch'),
          Route::delete('/{entity}/{id}')->action([ContentController::class, 'delete'])->name('v1.content.delete'),
        ),
      // Public routes of plugins in a project (e.g. sending a form) - no sign-in, rate limited
      Group::create('/'.$project.'/plugins')
        ->middleware(ProjectPathMiddleware::class)
        ->middleware(RateLimitMiddleware::class)
        ->routes(
          Route::methods(['GET', 'POST'], '/{plugin}/{path:.+}')->action([PluginApiController::class, 'publicCall'])->name('v1.plugins.public'),
        ),
      // Media of a project: clients with the media permissions
      Group::create('/'.$project.'/media')
        ->middleware(ProjectPathMiddleware::class)
        ->middleware(ContentAuthMiddleware::class)
        ->middleware(RateLimitMiddleware::class)
        ->routes(
          Route::post('')->action([ContentMediaController::class, 'upload'])->name('v1.content.media.upload'),
          Route::get('/{id}')->action([ContentMediaController::class, 'get'])->name('v1.content.media.get'),
          Route::delete('/{id}')->action([ContentMediaController::class, 'delete'])->name('v1.content.media.delete'),
        ),
      Route::post('/oauth/token')->action([OAuthController::class, 'token'])->name('v1.oauth.token'),
      Route::get('/'.$project.'/variables')->middleware(ProjectPathMiddleware::class)->action([ContentController::class, 'variables'])->name('v1.project.variables'),
      Route::post('/'.$project.'/oauth/token')->middleware(ProjectPathMiddleware::class)->action([OAuthController::class, 'token'])->name('v1.project.oauth.token'),

      // Admin app
      // Web cron (servers without cron jobs): scheduled publishing and cleanup, protected by CRON_KEY
      Route::methods(['GET', 'POST'], '/cron')->action([\App\Api\Controller\CronController::class, 'run'])->name('v1.cron'),
      Route::post('/auth/login')->action([AuthController::class, 'login'])->name('v1.auth.login'),
      Route::post('/auth/logout')->action([AuthController::class, 'logout'])->name('v1.auth.logout'),
      Route::post('/auth/password/forgot')->action([AccountController::class, 'forgotPassword'])->name('v1.auth.password.forgot'),
      Route::post('/auth/password/reset')->action([AccountController::class, 'resetPassword'])->name('v1.auth.password.reset'),
      Route::post('/auth/email/confirm')->action([AccountController::class, 'confirmEmail'])->name('v1.auth.email.confirm'),
      // Files of active plugins (their web components) - plain code, no secrets
      Route::get('/plugins/{plugin}/assets/{path:.+}')->action([PluginApiController::class, 'asset'])->name('v1.plugins.asset'),
      Group::create()
        ->middleware(AuthMiddleware::class)
        ->routes(
          Route::methods(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/plugins/{plugin}/api/{path:.+}')->action([PluginApiController::class, 'call'])->name('v1.plugins.api'),
          Route::get('/plugins/pages')->action([PluginApiController::class, 'pages'])->name('v1.plugins.pages'),
          Route::get('/auth/me')->action([AuthController::class, 'me'])->name('v1.auth.me'),
          Route::get('/auth/preferences/{key}')->action([PreferenceController::class, 'get'])->name('v1.auth.preferences.get'),
          Route::put('/auth/preferences/{key}')->action([PreferenceController::class, 'set'])->name('v1.auth.preferences.set'),
          Route::post('/auth/change-password')->action([AuthController::class, 'changePassword'])->name('v1.auth.change_password'),
          Route::post('/auth/email')->action([AccountController::class, 'requestEmailChange'])->name('v1.auth.email.request'),
          Route::delete('/auth/email')->action([AccountController::class, 'cancelEmailChange'])->name('v1.auth.email.cancel'),

          Route::get('/search')->action([SearchController::class, 'search'])->name('v1.search'),
          Route::get('/entities')->action([RecordController::class, 'entities'])->name('v1.entities.list'),
          Route::get('/entities/{entity}')->action([RecordController::class, 'entity'])->name('v1.entities.get'),
          Route::get('/entities/{entity}/options')->action([RecordController::class, 'options'])->name('v1.entities.options'),
          Route::get('/entities/{entity}/records')->action([RecordController::class, 'list'])->name('v1.records.list'),
          Route::post('/entities/{entity}/records')->action([RecordController::class, 'create'])->name('v1.records.create'),
          Route::post('/entities/{entity}/records/delete')->action([RecordController::class, 'deleteMany'])->name('v1.records.delete_many'),
          Route::post('/entities/{entity}/records/order')->action([RecordController::class, 'reorder'])->name('v1.records.order'),
          Route::post('/entities/{entity}/records/restore')->action([RecordController::class, 'restore'])->name('v1.records.restore'),
          Route::post('/entities/{entity}/trash/empty')->action([RecordController::class, 'emptyTrash'])->name('v1.records.trash_empty'),
          Route::get('/entities/{entity}/records/{id}')->action([RecordController::class, 'get'])->name('v1.records.get'),
          Route::put('/entities/{entity}/records/{id}')->action([RecordController::class, 'update'])->name('v1.records.update'),
          Route::delete('/entities/{entity}/records/{id}')->action([RecordController::class, 'delete'])->name('v1.records.delete'),
          Route::get('/entities/{entity}/records/{id}/revisions')->action([RecordController::class, 'revisions'])->name('v1.records.revisions'),
          Route::get('/entities/{entity}/records/{id}/revisions/{revision}')->action([RecordController::class, 'revision'])->name('v1.records.revision'),
          Route::post('/entities/{entity}/records/{id}/revisions/{revision}/restore')->action([RecordController::class, 'restoreRevision'])->name('v1.records.revision_restore'),
          Route::post('/entities/{entity}/records/{id}/preview')->action([RecordController::class, 'preview'])->name('v1.records.preview'),
          Route::put('/entities/{entity}/records/{id}/schedule')->action([RecordController::class, 'schedule'])->name('v1.records.schedule'),
          Route::post('/entities/{entity}/records/{id}/lock')->action([RecordController::class, 'lock'])->name('v1.records.lock'),
          Route::post('/entities/{entity}/records/{id}/lock/take-over')->action([RecordController::class, 'takeOverLock'])->name('v1.records.lock_take_over'),
          Route::delete('/entities/{entity}/records/{id}/lock')->action([RecordController::class, 'unlock'])->name('v1.records.unlock'),
          Route::put('/entities/{entity}/records/{id}/working-copy')->action([RecordController::class, 'saveWorkingCopy'])->name('v1.records.working_copy'),
          Route::post('/entities/{entity}/records/{id}/working-copy/publish')->action([RecordController::class, 'publishWorkingCopy'])->name('v1.records.working_copy_publish'),
          Route::delete('/entities/{entity}/records/{id}/working-copy')->action([RecordController::class, 'discardWorkingCopy'])->name('v1.records.working_copy_discard'),
          Route::get('/entities/{entity}/records/{id}/references')->action([RecordController::class, 'references'])->name('v1.records.references'),

          Route::post('/imports')->action([ImportController::class, 'upload'])->name('v1.imports.upload'),
          Route::post('/imports/{importId}/analyze')->action([ImportController::class, 'analyze'])->name('v1.imports.analyze'),
          Route::post('/imports/{importId}/preview')->action([ImportController::class, 'preview'])->name('v1.imports.preview'),
          Route::post('/imports/{importId}/run')->action([ImportController::class, 'run'])->name('v1.imports.run'),
          Route::delete('/imports/{importId}')->action([ImportController::class, 'discard'])->name('v1.imports.discard'),

          Route::get('/media')->action([MediaController::class, 'list'])->name('v1.media.list'),
          Route::post('/media')->action([MediaController::class, 'upload'])->name('v1.media.upload'),
          Route::get('/media/{id}')->action([MediaController::class, 'get'])->name('v1.media.get'),
          Route::patch('/media/{id}')->action([MediaController::class, 'update'])->name('v1.media.update'),
          Route::delete('/media/{id}')->action([MediaController::class, 'delete'])->name('v1.media.delete'),
        ),

      Group::create('/admin')
        ->middleware($admin)
        ->routes(
          Route::get('/schema-options')->action([SchemaController::class, 'options'])->name('v1.admin.schema_options'),
          Route::get('/entities')->action([SchemaController::class, 'list'])->name('v1.admin.entities.list'),
          Route::post('/entities')->action([SchemaController::class, 'create'])->name('v1.admin.entities.create'),
          Route::post('/entities/order')->action([SchemaController::class, 'reorder'])->name('v1.admin.entities.order'),
          Route::get('/entities/{id}')->action([SchemaController::class, 'get'])->name('v1.admin.entities.get'),
          Route::put('/entities/{id}')->action([SchemaController::class, 'update'])->name('v1.admin.entities.update'),
          Route::delete('/entities/{id}')->action([SchemaController::class, 'delete'])->name('v1.admin.entities.delete'),
          Route::post('/entities/{id}/copy')->action([EntityCopyController::class, 'copy'])->name('v1.admin.entities.copy'),
          Route::post('/entities/{id}/fields')->action([SchemaController::class, 'addField'])->name('v1.admin.fields.create'),
          Route::post('/entities/{id}/fields/group')->action([GroupController::class, 'fromFields'])->name('v1.admin.fields.to_group'),
          Route::post('/entities/{id}/fields/order')->action([SchemaController::class, 'reorderFields'])->name('v1.admin.fields.order'),
          Route::put('/entities/{id}/fields/{fieldId}')->action([SchemaController::class, 'updateField'])->name('v1.admin.fields.update'),
          Route::delete('/entities/{id}/fields/{fieldId}')->action([SchemaController::class, 'deleteField'])->name('v1.admin.fields.delete'),

          Route::get('/projects')->action([ProjectController::class, 'list'])->name('v1.admin.projects.list'),
          Route::get('/media-storages')->action([ProjectController::class, 'mediaStorages'])->name('v1.admin.media_storages'),
          Route::get('/storages')->action([StorageController::class, 'list'])->name('v1.admin.storages.list'),
          Route::get('/storages/types')->action([StorageController::class, 'types'])->name('v1.admin.storages.types'),
          Route::post('/storages')->action([StorageController::class, 'create'])->name('v1.admin.storages.create'),
          Route::get('/storages/{id}')->action([StorageController::class, 'get'])->name('v1.admin.storages.get'),
          Route::put('/storages/{id}')->action([StorageController::class, 'update'])->name('v1.admin.storages.update'),
          Route::delete('/storages/{id}')->action([StorageController::class, 'delete'])->name('v1.admin.storages.delete'),
          Route::post('/storages/{id}/test')->action([StorageController::class, 'test'])->name('v1.admin.storages.test'),
          Route::post('/projects')->action([ProjectController::class, 'create'])->name('v1.admin.projects.create'),
          Route::put('/projects/{id}')->action([ProjectController::class, 'update'])->name('v1.admin.projects.update'),
          Route::delete('/projects/{id}')->action([ProjectController::class, 'delete'])->name('v1.admin.projects.delete'),
          Route::get('/groups')->action([GroupController::class, 'list'])->name('v1.admin.groups.list'),
          Route::post('/groups')->action([GroupController::class, 'create'])->name('v1.admin.groups.create'),
          Route::get('/groups/{id}')->action([GroupController::class, 'get'])->name('v1.admin.groups.get'),
          Route::put('/groups/{id}')->action([GroupController::class, 'update'])->name('v1.admin.groups.update'),
          Route::delete('/groups/{id}')->action([GroupController::class, 'delete'])->name('v1.admin.groups.delete'),
          Route::post('/groups/{id}/render')->action([GroupController::class, 'render'])->name('v1.admin.groups.render'),
          Route::post('/groups/{id}/fields')->action([GroupController::class, 'addField'])->name('v1.admin.groups.fields.create'),
          Route::post('/groups/{id}/fields/order')->action([GroupController::class, 'reorderFields'])->name('v1.admin.groups.fields.order'),
          Route::put('/groups/{id}/fields/{fieldId}')->action([GroupController::class, 'updateField'])->name('v1.admin.groups.fields.update'),
          Route::delete('/groups/{id}/fields/{fieldId}')->action([GroupController::class, 'deleteField'])->name('v1.admin.groups.fields.delete'),

          Route::get('/variables')->action([ProjectController::class, 'variables'])->name('v1.admin.variables.get'),
          Route::put('/variables')->action([ProjectController::class, 'updateVariables'])->name('v1.admin.variables.update'),

          Route::get('/search-index')->action([SearchIndexController::class, 'status'])->name('v1.admin.search_index'),
          Route::put('/search-index/stopwords')->action([SearchIndexController::class, 'stopwords'])->name('v1.admin.search_index.stopwords'),
          Route::post('/search-index/rebuild')->action([SearchIndexController::class, 'rebuild'])->name('v1.admin.search_index.rebuild'),
          Route::post('/search-index/clear')->action([SearchIndexController::class, 'clear'])->name('v1.admin.search_index.clear'),

          Route::get('/roles')->action([RoleController::class, 'list'])->name('v1.admin.roles.list'),
          Route::post('/roles')->action([RoleController::class, 'create'])->name('v1.admin.roles.create'),
          Route::put('/roles/{slug}')->action([RoleController::class, 'update'])->name('v1.admin.roles.update'),
          Route::delete('/roles/{slug}')->action([RoleController::class, 'delete'])->name('v1.admin.roles.delete'),

          Route::get('/users')->action([UserController::class, 'list'])->name('v1.admin.users.list'),
          Route::post('/users')->action([UserController::class, 'create'])->name('v1.admin.users.create'),
          Route::get('/users/{id}')->action([UserController::class, 'get'])->name('v1.admin.users.get'),
          Route::put('/users/{id}')->action([UserController::class, 'update'])->name('v1.admin.users.update'),
          Route::delete('/users/{id}')->action([UserController::class, 'delete'])->name('v1.admin.users.delete'),

          Route::get('/plugins')->action([PluginController::class, 'list'])->name('v1.admin.plugins.list'),
          Route::get('/plugins/steps')->action([PluginController::class, 'steps'])->name('v1.admin.plugins.steps'),
          Route::get('/plugins/field-types')->action([PluginController::class, 'fieldTypes'])->name('v1.admin.plugins.field_types'),
          Route::get('/plugins/event-sources')->action([PluginController::class, 'eventSources'])->name('v1.admin.plugins.event_sources'),
          Route::post('/plugins/upload')->action([PluginController::class, 'upload'])->name('v1.admin.plugins.upload'),
          Route::get('/plugins/{name}')->action([PluginController::class, 'get'])->name('v1.admin.plugins.get'),
          Route::post('/plugins/{name}/install')->action([PluginController::class, 'install'])->name('v1.admin.plugins.install'),
          Route::post('/plugins/{name}/sync')->action([PluginController::class, 'sync'])->name('v1.admin.plugins.sync'),
          Route::post('/plugins/{name}/activate')->action([PluginController::class, 'activate'])->name('v1.admin.plugins.activate'),
          Route::post('/plugins/{name}/deactivate')->action([PluginController::class, 'deactivate'])->name('v1.admin.plugins.deactivate'),
          Route::delete('/plugins/{name}')->action([PluginController::class, 'uninstall'])->name('v1.admin.plugins.uninstall'),
          Route::put('/plugins/{name}/settings')->action([PluginController::class, 'settings'])->name('v1.admin.plugins.settings'),
          Route::get('/env-vars')->action([EnvController::class, 'names'])->name('v1.admin.env_vars'),
          Route::get('/events')->action([EventController::class, 'list'])->name('v1.admin.events.list'),
          Route::post('/events')->action([EventController::class, 'create'])->name('v1.admin.events.create'),
          Route::get('/events/{id}')->action([EventController::class, 'get'])->name('v1.admin.events.get'),
          Route::put('/events/{id}')->action([EventController::class, 'update'])->name('v1.admin.events.update'),
          Route::delete('/events/{id}')->action([EventController::class, 'delete'])->name('v1.admin.events.delete'),
          Route::get('/events/{id}/runs')->action([EventController::class, 'runs'])->name('v1.admin.events.runs'),
          Route::post('/events/{id}/test')->action([EventController::class, 'test'])->name('v1.admin.events.test'),
          Route::post('/event-runs/{id}/retry')->action([EventController::class, 'retry'])->name('v1.admin.event_runs.retry'),

          Route::get('/clients')->action([ClientController::class, 'list'])->name('v1.admin.clients.list'),
          Route::post('/clients')->action([ClientController::class, 'create'])->name('v1.admin.clients.create'),
          Route::get('/clients/{id}')->action([ClientController::class, 'get'])->name('v1.admin.clients.get'),
          Route::put('/clients/{id}')->action([ClientController::class, 'update'])->name('v1.admin.clients.update'),
          Route::post('/clients/{id}/secret')->action([ClientController::class, 'regenerateSecret'])->name('v1.admin.clients.secret'),
          Route::delete('/clients/{id}')->action([ClientController::class, 'delete'])->name('v1.admin.clients.delete'),

          Route::get('/settings/media-types')->action([SettingsController::class, 'getMediaTypes'])->name('v1.admin.settings.media_types.get'),
          Route::put('/settings/media-types')->action([SettingsController::class, 'updateMediaTypes'])->name('v1.admin.settings.media_types.update'),
          Route::get('/settings/rate-limit')->action([SettingsController::class, 'getRateLimit'])->name('v1.admin.settings.rate_limit.get'),
          Route::put('/settings/rate-limit')->action([SettingsController::class, 'updateRateLimit'])->name('v1.admin.settings.rate_limit.update'),
        ),
    ),

  // Files of the local storage: public ones for everyone, protected ones with a signed address
  Route::get('/media/{path:.+}')->action([MediaFileController::class, 'file'])->name('media.file'),
];
