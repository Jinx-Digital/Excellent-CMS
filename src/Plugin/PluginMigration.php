<?php

declare(strict_types=1);

namespace App\Plugin;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * A migration of a plugin: migrations/<name>.php returns one. Run in the order of the file names
 * when the plugin is installed or updated, backwards when it is uninstalled.
 *
 *     return new class implements PluginMigration {
 *         public function up(ConnectionInterface $db): void { $db->createCommand()->createTable('plugin_x_log', [...])->execute(); }
 *         public function down(ConnectionInterface $db): void { $db->createCommand()->dropTable('plugin_x_log')->execute(); }
 *     };
 */
interface PluginMigration
{
  public function up(ConnectionInterface $db): void;

  public function down(ConnectionInterface $db): void;
}
