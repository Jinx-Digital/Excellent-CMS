<?php

declare(strict_types=1);

namespace App\Migration;

use App\Shared\Id;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder as C;

/**
 * The tables of Excellent CMS - one migration from the start. Not here: the content tables of the
 * entities (SchemaService creates them), the tables of yiisoft/rbac-db (roles and permissions) and
 * yiisoft/queue-db (events in queue mode) - their own migrations run with ours (config/console/params.php),
 * and the tables of plugins (their migrations). Starts with the projects "main" and "global" and the
 * role "editor".
 */
final class M261009000000Init implements RevertibleMigrationInterface
{
  private const OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

  public function up(MigrationBuilder $b): void
  {
    // Projects: each has its entities (content tables <table_prefix><slug>), languages, variables and media
    // storage. "global" holds entities shared by all projects.
    $b->createTable('project', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'slug' => C::string(40)->notNull(),
      'name' => C::string(100)->notNull(),
      'description' => C::text()->null(),
      'table_prefix' => C::string(20)->notNull(),
      'languages' => C::text()->null(),
      'sort_order' => C::integer()->notNull()->defaultValue(0),
      'variables' => C::text()->null(),
      'is_global' => C::boolean()->notNull()->defaultValue(false),
      'media_storage' => C::string(40)->null(),
      'stopwords' => C::text()->null(),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->createIndex('project', 'slug', ['slug'], 'UNIQUE');
    $b->createIndex('project', 'table_prefix', ['table_prefix'], 'UNIQUE');

    // Entities (schema): their content lives in tables of their own; tabs of the record form, preview address,
    // drafts, revisions, trash.
    $b->createTable('entity', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'slug' => C::string(40)->notNull(),
      'name' => C::string(100)->notNull(),
      'description' => C::text()->null(),
      'access' => C::string(10)->notNull()->defaultValue('public'),
      'label_field' => C::string(64)->null(),
      'unique_together' => C::text()->null(),
      'sort_order' => C::integer()->notNull()->defaultValue(0),
      'has_trash' => C::boolean()->notNull()->defaultValue(false),
      'tree_field' => C::string(64)->null(),
      'project_id' => C::string(22)->notNull(),
      'has_drafts' => C::boolean()->notNull()->defaultValue(false),
      'has_revisions' => C::boolean()->notNull()->defaultValue(false),
      'preview_url' => C::string(500)->null(),
      'form_tabs' => C::text()->null(),
      // Created by this plugin: its fields are locked, the entity cannot be deleted or renamed
      'managed_by' => C::string(40)->null(),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->createIndex('entity', 'idx_entity_project_slug', ['project_id', 'slug'], 'UNIQUE');
    $b->addForeignKey('entity', 'fk_entity_project', ['project_id'], 'project', ['id'], 'RESTRICT', 'CASCADE');

    // Field groups (kind "group", for group fields) and blocks (kind "block", items of block lists) of a
    // project.
    $b->createTable('field_group', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'project_id' => C::string(22)->notNull(),
      'name' => C::string(40)->notNull(),
      'label' => C::string(100)->notNull(),
      'description' => C::text()->null(),
      'sort_order' => C::integer()->notNull()->defaultValue(0),
      'kind' => C::string(10)->notNull()->defaultValue('group'),
      // Created by this plugin (see entity.managed_by)
      'managed_by' => C::string(40)->null(),
      // To organize them in the lists and the block picker (free text, e.g. "Media")
      'category' => C::string(60)->null(),
      // Blocks: their HTML (Twig, sandboxed) - delivered as "_html" with ?render=html
      'template' => C::text(16777215)->null(),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->createIndex('field_group', 'idx_field_group_project_name', ['project_id', 'name'], 'UNIQUE');
    $b->addForeignKey('field_group', 'fk_field_group_project', ['project_id'], 'project', ['id'], 'CASCADE', 'CASCADE');

    // Fields of entities (entity_id) and of field groups / blocks (group_id). A group field uses a field group
    // (field_group_id) or blocks (block_groups).
    $b->createTable('entity_field', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'entity_id' => C::string(22)->null(),
      'name' => C::string(64)->notNull(),
      'label' => C::string(100)->notNull(),
      'type' => C::string(20)->notNull(),
      'length' => C::integer()->null(),
      'scale' => C::integer()->null(),
      'required' => C::boolean()->notNull()->defaultValue(false),
      'is_unique' => C::boolean()->notNull()->defaultValue(false),
      'reference_entity_id' => C::string(22)->null(),
      'sort_order' => C::integer()->notNull()->defaultValue(0),
      'uuid_version' => C::smallint()->null(),
      'media_accept' => C::text()->null(),
      'slug_source' => C::string(64)->null(),
      'is_translatable' => C::boolean()->notNull()->defaultValue(false),
      'is_repeatable' => C::boolean()->notNull()->defaultValue(false),
      'is_sortable' => C::boolean()->notNull()->defaultValue(true),
      'repeat_min' => C::integer()->null(),
      'repeat_max' => C::integer()->null(),
      'group_id' => C::string(22)->null(),
      'field_group_id' => C::string(22)->null(),
      'pattern' => C::string(500)->null(),
      'pattern_message' => C::string(255)->null(),
      'options' => C::text()->null(),
      'read_roles' => C::text()->null(),
      'write_roles' => C::text()->null(),
      'is_searchable' => C::boolean()->notNull()->defaultValue(true),
      'is_filterable' => C::boolean()->notNull()->defaultValue(true),
      'search_weight' => C::smallint()->notNull()->defaultValue(1),
      'block_groups' => C::text()->null(),
      // Blocks: all blocks of these categories too (also ones added later)
      'block_categories' => C::text()->null(),
      'custom_type' => C::string(80)->null(),
      // Numbers: the allowed range, shown as a slider (needs both ends)
      'min_value' => C::decimal(20, 6)->null(),
      'max_value' => C::decimal(20, 6)->null(),
      'is_slider' => C::boolean()->notNull()->defaultValue(false),
      // A field a plugin relies on: cannot be deleted, renamed or change its type
      'is_locked' => C::boolean()->notNull()->defaultValue(false),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->createIndex('entity_field', 'idx_entity_field_group_name', ['group_id', 'name'], 'UNIQUE');
    $b->createIndex('entity_field', 'idx_entity_field_name', ['entity_id', 'name'], 'UNIQUE');
    $b->addForeignKey('entity_field', 'fk_entity_field_entity', ['entity_id'], 'entity', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('entity_field', 'fk_entity_field_group', ['group_id'], 'field_group', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('entity_field', 'fk_entity_field_reference', ['reference_entity_id'], 'entity', ['id'], 'RESTRICT', 'CASCADE');
    $b->addForeignKey('entity_field', 'fk_entity_field_uses_group', ['field_group_id'], 'field_group', ['id'], 'RESTRICT', 'CASCADE');

    // Events: react to changes (records, media, variables, sources of plugins) with steps - directly or through
    // the queue.
    $b->createTable('event', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'project_id' => C::string(22)->notNull(),
      'entity_id' => C::string(22)->null(),
      'name' => C::string(100)->notNull(),
      'actions' => C::text()->notNull(),
      'condition' => C::text()->null(),
      'steps' => C::text(16777215)->notNull(),
      'mode' => C::string(10)->notNull()->defaultValue('direct'),
      'is_active' => C::boolean()->notNull()->defaultValue(true),
      'source' => C::string(60)->notNull()->defaultValue('entity'),
      'source_target' => C::string(80)->null(),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->createIndex('event', 'idx_event_entity', ['entity_id', 'is_active']);
    $b->createIndex('event', 'idx_event_source', ['project_id', 'source', 'is_active']);
    $b->addForeignKey('event', 'fk_event_entity', ['entity_id'], 'entity', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('event', 'fk_event_project', ['project_id'], 'project', ['id'], 'CASCADE', 'CASCADE');

    // Runs of events with the progress of their steps.
    $b->createTable('event_run', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'event_id' => C::string(22)->notNull(),
      'project_id' => C::string(22)->notNull(),
      'status' => C::string(20)->notNull(),
      'depth' => C::integer()->notNull()->defaultValue(0),
      'origin' => C::text()->null(),
      'count' => C::integer()->notNull(),
      'records' => C::text(16777215)->notNull(),
      'steps' => C::text()->notNull(),
      'error' => C::string(1000)->null(),
      'started_at' => C::datetime()->null(),
      'finished_at' => C::datetime()->null(),
      'created_at' => C::datetime(6)->notNull(),
    ], self::OPTIONS);
    $b->createIndex('event_run', 'idx_event_run_event', ['event_id', 'created_at']);
    $b->addForeignKey('event_run', 'fk_event_run_event', ['event_id'], 'event', ['id'], 'CASCADE', 'CASCADE');

    // Uploaded files: the file lives in its storage (disk), used by media fields.
    $b->createTable('media', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'disk' => C::string(40)->notNull(),
      'path' => C::string(255)->notNull(),
      'name' => C::string(255)->notNull(),
      'mime_type' => C::string(100)->notNull(),
      'size' => C::bigint()->notNull(),
      'width' => C::integer()->null(),
      'height' => C::integer()->null(),
      'created_by' => C::string(22)->null(),
      'project_id' => C::string(22)->notNull(),
      'is_kept' => C::boolean()->notNull()->defaultValue(false),
      'focal_x' => C::decimal(5, 4)->null(),
      'focal_y' => C::decimal(5, 4)->null(),
      'created_at' => C::datetime()->notNull(),
    ], self::OPTIONS);
    $b->createIndex('media', 'idx_media_created', ['created_at']);
    $b->addForeignKey('media', 'fk_media_project', ['project_id'], 'project', ['id'], 'RESTRICT', 'CASCADE');

    // API clients of the content API (client credentials); their rights are RBAC permissions.
    $b->createTable('oauth_client', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'name' => C::string(100)->notNull(),
      'client_id' => C::string(40)->notNull(),
      'secret_hash' => C::string(255)->notNull(),
      'is_active' => C::boolean()->notNull()->defaultValue(true),
      'rate_limit' => C::integer()->null(),
      'last_used_at' => C::datetime()->null(),
      'project_id' => C::string(22)->notNull(),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->createIndex('oauth_client', 'client_id', ['client_id'], 'UNIQUE');
    $b->addForeignKey('oauth_client', 'fk_oauth_client_project', ['project_id'], 'project', ['id'], 'CASCADE', 'CASCADE');

    // Access tokens of API clients (hashed).
    $b->createTable('oauth_access_token', [
      'token_hash' => C::string(64)->notNull()->primaryKey(),
      'client_id' => C::string(22)->notNull(),
      'scopes' => C::text()->notNull(),
      'expires_at' => C::datetime()->notNull(),
      'created_at' => C::datetime()->notNull(),
    ], self::OPTIONS);
    $b->createIndex('oauth_access_token', 'idx_oauth_access_token_expires', ['expires_at']);
    $b->addForeignKey('oauth_access_token', 'fk_oat_client', ['client_id'], 'oauth_client', ['id'], 'CASCADE', 'CASCADE');

    // Installed plugins: version, active, settings, migrations that ran.
    $b->createTable('plugin', [
      'name' => C::string(40)->notNull()->primaryKey(),
      'version' => C::string(40)->notNull(),
      // Active in all projects (also new ones) - or only in the projects of "projects" (JSON list of ids)
      'is_active' => C::boolean()->notNull()->defaultValue(false),
      'projects' => C::text()->null(),
      'settings' => C::text()->null(),
      'migrations' => C::text()->null(),
      'error' => C::text()->null(),
      'installed_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);

    // Requests per client and minute (content API).
    $b->createTable('rate_limit', [
      'key' => C::string(120)->notNull()->primaryKey(),
      'window_start' => C::integer()->notNull(),
      'hits' => C::integer()->notNull()->defaultValue(0),
    ], self::OPTIONS);

    // Users of the admin app.
    $b->createTable('user', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'name' => C::string(100)->notNull(),
      'email' => C::string(255)->notNull(),
      'password_hash' => C::string(255)->notNull(),
      'is_admin' => C::boolean()->notNull()->defaultValue(false),
      'is_active' => C::boolean()->notNull()->defaultValue(true),
      'token_version' => C::integer()->notNull()->defaultValue(1),
      'last_login_at' => C::datetime()->null(),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->createIndex('user', 'email', ['email'], 'UNIQUE');

    // Who is editing a record right now.
    $b->createTable('record_lock', [
      'entity_id' => C::string(22)->notNull(),
      'record_id' => C::string(22)->notNull(),
      'user_id' => C::string(22)->notNull(),
      'locked_at' => C::datetime()->notNull(),
      'seen_at' => C::datetime()->notNull(),
    ], self::OPTIONS);
    $b->addPrimaryKey('record_lock', 'pk_record_lock', ['entity_id', 'record_id']);
    $b->addForeignKey('record_lock', 'fk_record_lock_entity', ['entity_id'], 'entity', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('record_lock', 'fk_record_lock_user', ['user_id'], 'user', ['id'], 'CASCADE', 'CASCADE');

    // Scheduled publishing and unpublishing of records.
    $b->createTable('record_schedule', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'project_id' => C::string(22)->notNull(),
      'entity_id' => C::string(22)->notNull(),
      'record_id' => C::string(22)->notNull(),
      'action' => C::string(20)->notNull(),
      'run_at' => C::datetime()->notNull(),
      'error' => C::text()->null(),
      'created_by' => C::string(40)->null(),
      'created_at' => C::datetime()->notNull(),
    ], self::OPTIONS);
    $b->createIndex('record_schedule', 'idx_record_schedule_due', ['run_at']);
    $b->createIndex('record_schedule', 'uq_record_schedule', ['entity_id', 'record_id', 'action'], 'UNIQUE');
    $b->addForeignKey('record_schedule', 'fk_record_schedule_entity', ['entity_id'], 'entity', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('record_schedule', 'fk_record_schedule_project', ['project_id'], 'project', ['id'], 'CASCADE', 'CASCADE');

    // Earlier versions of records.
    $b->createTable('revision', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'project_id' => C::string(22)->notNull(),
      'entity_id' => C::string(22)->notNull(),
      'record_id' => C::string(22)->notNull(),
      'action' => C::string(20)->notNull(),
      'data' => C::text(16777215)->notNull(),
      'changed' => C::text()->null(),
      'media' => C::text()->null(),
      'created_by' => C::string(40)->null(),
      'created_at' => C::datetime(6)->notNull(),
    ], self::OPTIONS);
    $b->createIndex('revision', 'idx_revision_record', ['entity_id', 'record_id', 'created_at']);
    $b->addForeignKey('revision', 'fk_revision_entity', ['entity_id'], 'entity', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('revision', 'fk_revision_project', ['project_id'], 'project', ['id'], 'CASCADE', 'CASCADE');

    // Search index: the words.
    $b->createTable('search_term', [
      'id' => C::bigPrimaryKey(),
      'project_id' => C::string(22)->notNull(),
      'term' => C::string(64)->notNull(),
    ], self::OPTIONS);
    $b->createIndex('search_term', 'uq_search_term', ['project_id', 'term'], 'UNIQUE');
    $b->addForeignKey('search_term', 'fk_search_term_project', ['project_id'], 'project', ['id'], 'CASCADE', 'CASCADE');

    // Search index: which record has a word, in which field and language, with which weight.
    $b->createTable('search_posting', [
      'term_id' => C::bigint()->notNull(),
      'entity_id' => C::string(22)->notNull(),
      'record_id' => C::string(22)->notNull(),
      'field_id' => C::string(22)->notNull(),
      'language' => C::string(10)->notNull()->defaultValue(''),
      'weight' => C::smallint()->notNull()->defaultValue(1),
    ], self::OPTIONS);
    $b->addPrimaryKey('search_posting', 'pk_search_posting', ['term_id', 'entity_id', 'record_id', 'field_id', 'language']);
    $b->createIndex('search_posting', 'idx_search_posting_record', ['entity_id', 'record_id']);
    $b->addForeignKey('search_posting', 'fk_search_posting_entity', ['entity_id'], 'entity', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('search_posting', 'fk_search_posting_term', ['term_id'], 'search_term', ['id'], 'CASCADE', 'CASCADE');

    // Search index: state per entity (ready, stale, building).
    $b->createTable('search_state', [
      'entity_id' => C::string(22)->notNull()->primaryKey(),
      'project_id' => C::string(22)->notNull(),
      'status' => C::string(10)->notNull(),
      'records' => C::integer()->notNull()->defaultValue(0),
      'built_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->addForeignKey('search_state', 'fk_search_state_entity', ['entity_id'], 'entity', ['id'], 'CASCADE', 'CASCADE');

    // Settings of the admin app (e.g. the rate limit).
    $b->createTable('setting', [
      'key' => C::string(100)->notNull()->primaryKey(),
      'value' => C::text()->null(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);

    // Storages of uploads defined in the admin app ("local" is built in).
    $b->createTable('storage', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'name' => C::string(40)->notNull(),
      'label' => C::string(100)->notNull()->defaultValue(''),
      'type' => C::string(20)->notNull(),
      'is_private' => C::boolean()->notNull()->defaultValue(true),
      'settings' => C::text()->notNull(),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->createIndex('storage', 'name', ['name'], 'UNIQUE');

    // Preferences of users (columns of lists, filters …).
    $b->createTable('user_preference', [
      'user_id' => C::string(22)->notNull(),
      'key' => C::string(100)->notNull(),
      'value' => C::text()->notNull(),
      'updated_at' => C::datetime()->null(),
    ], self::OPTIONS);
    $b->addPrimaryKey('user_preference', 'pk_user_preference', ['user_id', 'key']);
    $b->addForeignKey('user_preference', 'fk_user_preference_user', ['user_id'], 'user', ['id'], 'CASCADE', 'CASCADE');

    // Which projects a user may open.
    $b->createTable('user_project', [
      'user_id' => C::string(22)->notNull(),
      'project_id' => C::string(22)->notNull(),
    ], self::OPTIONS);
    $b->addPrimaryKey('user_project', 'pk_user_project', ['user_id', 'project_id']);
    $b->addForeignKey('user_project', 'fk_up_project', ['project_id'], 'project', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('user_project', 'fk_up_user', ['user_id'], 'user', ['id'], 'CASCADE', 'CASCADE');

    // Tokens of users: password reset, new e-mail address.
    $b->createTable('user_token', [
      'token_hash' => C::string(64)->notNull()->primaryKey(),
      'user_id' => C::string(22)->notNull(),
      'type' => C::string(20)->notNull(),
      'data' => C::string(255)->null(),
      'expires_at' => C::datetime()->notNull(),
      'created_at' => C::datetime()->notNull(),
    ], self::OPTIONS);
    $b->createIndex('user_token', 'idx_user_token_user', ['user_id', 'type']);
    $b->addForeignKey('user_token', 'fk_user_token_user', ['user_id'], 'user', ['id'], 'CASCADE', 'CASCADE');

    // Changes of published records saved for later.
    $b->createTable('working_copy', [
      'id' => C::string(22)->notNull()->primaryKey(),
      'project_id' => C::string(22)->notNull(),
      'entity_id' => C::string(22)->notNull(),
      'record_id' => C::string(22)->notNull(),
      'data' => C::text(16777215)->notNull(),
      'media' => C::text()->null(),
      'created_by' => C::string(40)->null(),
      'updated_by' => C::string(40)->null(),
      'created_at' => C::datetime()->notNull(),
      'updated_at' => C::datetime()->notNull(),
    ], self::OPTIONS);
    $b->createIndex('working_copy', 'uq_working_copy_record', ['entity_id', 'record_id'], 'UNIQUE');
    $b->addForeignKey('working_copy', 'fk_working_copy_entity', ['entity_id'], 'entity', ['id'], 'CASCADE', 'CASCADE');
    $b->addForeignKey('working_copy', 'fk_working_copy_project', ['project_id'], 'project', ['id'], 'CASCADE', 'CASCADE');

    // yiisoft/queue-db creates the queue with "int DEFAULT NULL" as text, which yiisoft/db turns into
    // DEFAULT 0 - the queue then never finds a job (it looks for reserved_at IS NULL)
    foreach (['reserved_at', 'attempt', 'done_at'] as $column) {
      $b->alterColumn('queue', $column, C::integer()->null()->defaultValue(null));
    }

    // RBAC (tables of yiisoft/rbac-db): the role "editor", which may take over locked records
    $time = time();
    $b->insert('yii_rbac_item', ['name' => 'records.take_over', 'type' => 'permission', 'description' => null, 'created_at' => $time, 'updated_at' => $time]);
    $b->insert('yii_rbac_item', ['name' => 'role.editor', 'type' => 'role', 'description' => 'Redakteur', 'created_at' => $time, 'updated_at' => $time]);
    $b->insert('yii_rbac_item_child', ['parent' => 'role.editor', 'child' => 'records.take_over']);

    // The first project and the area of entities shared by all projects
    $now = date('Y-m-d H:i:s');
    $b->insert('project', ['id' => Id::new(), 'slug' => 'main', 'name' => 'Main project', 'table_prefix' => 'main_', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $b->insert('project', ['id' => Id::new(), 'slug' => 'global', 'name' => 'Global', 'description' => 'Entities shared by all projects', 'table_prefix' => 'global_', 'sort_order' => -1, 'is_global' => true, 'created_at' => $now, 'updated_at' => $now]);
  }

  public function down(MigrationBuilder $b): void
  {
    $b->dropTable('working_copy');
    $b->dropTable('user_token');
    $b->dropTable('user_project');
    $b->dropTable('user_preference');
    $b->dropTable('storage');
    $b->dropTable('setting');
    $b->dropTable('search_state');
    $b->dropTable('search_posting');
    $b->dropTable('search_term');
    $b->dropTable('revision');
    $b->dropTable('record_schedule');
    $b->dropTable('record_lock');
    $b->dropTable('user');
    $b->dropTable('rate_limit');
    $b->dropTable('plugin');
    $b->dropTable('oauth_access_token');
    $b->dropTable('oauth_client');
    $b->dropTable('media');
    $b->dropTable('event_run');
    $b->dropTable('event');
    $b->dropTable('entity_field');
    $b->dropTable('field_group');
    $b->dropTable('entity');
    $b->dropTable('project');
  }
}
