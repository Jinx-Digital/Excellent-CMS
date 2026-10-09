<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Application\Service\CurrentProject;
use App\Plugin\PluginManager;
use App\Repository\EntityRepository;
use App\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What plugins bring into projects:
 *
 * - their blocks (PluginRegistry::blockGroup): created as field groups in the projects where the
 *   plugin is active and that do not have a group of that name yet - existing ones stay as they are
 *   (ensure(): when a plugin is activated and when a project is created);
 * - their hooks (PluginRegistry::onActivate / onDeactivate): called for every project a plugin is
 *   activated in or deactivated in.
 */
final class PluginSetup
{
  public function __construct(
    private PluginManager $plugins,
    private GroupService $groups,
    private EntityRepository $entities,
    private ProjectRepository $projects,
    private CurrentProject $currentProject,
    private SchemaService $schema,
    private \App\Application\Content\RecordService $records,
    private \App\Application\Event\EventService $events,
    private \Yiisoft\Db\Connection\ConnectionInterface $db,
    private ?LoggerInterface $logger = null,
  ) {
  }

  /**
   * @param string|null $projectId one project - or all
   * @param string|null $plugin only the blocks of this plugin
   * @param bool $templates blocks managed by the plugin get its template again (also changed ones - e.g. after an update of the plugin)
   * @return int how many groups were created (or, with $templates, got the plugin's template)
   */
  public function ensure(?string $projectId = null, ?string $plugin = null, bool $templates = false): int
  {
    $previous = $this->currentProject->find();
    $created = 0;
    try {
      foreach ($this->projects->all() as $project) {
        if (null !== $projectId && $project->id !== $projectId) {
          continue;
        }
        $this->currentProject->set($project);
        // The plugins active in this project
        $registry = $this->plugins->registry();
        // The blocks of the CMS itself (Columns) first - blocks of plugins may use them
        $blocks = [...(null === $plugin || CoreBlocks::MANAGED_BY === $plugin ? CoreBlocks::definitions() : []), ...$registry->blockGroups()];
        foreach ($blocks as $block) {
          if (null !== $plugin && $block['plugin'] !== $plugin) {
            continue;
          }
          $existing = $this->entities->findGroup($block['name']);
          if (null !== $existing) {
            // An unused field group of its name (e.g. from before blocks were a kind of their own) becomes the block
            if ('block' === $block['kind'] && !$existing->isBlock() && [] === $this->entities->fieldsUsingGroup($existing->id)) {
              $this->groups->update($existing->id, ['kind' => \App\Domain\Schema\FieldGroup::KIND_BLOCK]);
            }
            // A block of the plugin from before its template: gets it (templates changed by editors stay)
            if ($existing->managedBy === $block['plugin'] && null !== ($block['template'] ?? null)
              && (null === $existing->template || ($templates && $existing->template !== $block['template']))) {
              $this->groups->update($existing->id, ['template' => $block['template']]);
              $created += null !== $existing->template ? 1 : 0;
            }
            continue;
          }
          try {
            $new = $this->groups->create(['name' => $block['name'], 'label' => $block['label'], 'kind' => $block['kind'], 'category' => $block['category'] ?? null, 'template' => $block['template'] ?? null, 'description' => $block['description'] ?: null, 'fields' => $block['fields']]);
            // Managed by the plugin: its fields locked, the group not deleted or renamed
            $this->db->createCommand()->update('field_group', ['managed_by' => $block['plugin']], ['id' => $new->id])->execute();
            $this->db->createCommand()->update('entity_field', ['is_locked' => true], ['group_id' => $new->id])->execute();
            $this->entities->reset();
            $created++;
          } catch (Throwable $e) {
            // A block that does not fit (e.g. a field type of another, inactive plugin) is left out
            $this->logger?->warning(sprintf('Block "%s" of plugin "%s" not created in project "%s": %s', $block['name'], $block['plugin'], $project->slug, $e->getMessage()));
          }
        }
      }
    } finally {
      $this->currentProject->set($previous);
      $this->entities->reset();
      // The registry of the request's project again
      $this->plugins->registry();
    }
    return $created;
  }

  /**
   * Calls the hook of a plugin for projects it was activated in (after: its blocks are created
   * first) or is deactivated in (before - the plugin is still active there).
   *
   * @param list<string> $projectIds
   */
  public function hook(string $plugin, array $projectIds, bool $activate): void
  {
    if ([] === $projectIds) {
      return;
    }
    $previous = $this->currentProject->find();
    try {
      foreach ($this->projects->all() as $project) {
        if (!in_array($project->id, $projectIds, true) || $project->isGlobal) {
          continue;
        }
        if ($activate) {
          $this->ensure($project->id, $plugin);
        }
        $this->currentProject->set($project);
        $hook = $this->plugins->registry()->activationHook($plugin, $activate);
        if (null === $hook) {
          continue;
        }
        try {
          $hook(new \App\Plugin\ProjectSetup($plugin, $project, $this->schema, $this->entities, $this->records, $this->events, $this->db));
        } catch (Throwable $e) {
          $this->logger?->warning(sprintf('%s hook of plugin "%s" in project "%s" failed: %s', $activate ? 'Activation' : 'Deactivation', $plugin, $project->slug, $e->getMessage()));
        } finally {
          $this->entities->reset();
        }
      }
    } finally {
      $this->currentProject->set($previous);
      $this->plugins->registry();
    }
  }
}
