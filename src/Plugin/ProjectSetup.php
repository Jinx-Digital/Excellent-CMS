<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Application\Content\RecordService;
use App\Application\Event\EventService;
use App\Application\Schema\SchemaService;
use App\Domain\Project\Project;
use App\Repository\EntityRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * What a plugin can do when it sets up a project (PluginRegistry::setup): create entities, records
 * and events there - with the same checks as the admin app. The project is the current one meanwhile.
 */
final class ProjectSetup
{
  public function __construct(
    /** The plugin that sets up - what it creates is managed by it */
    public readonly string $plugin,
    public readonly Project $project,
    private SchemaService $schema,
    private EntityRepository $entities,
    private RecordService $records,
    private EventService $events,
    private ConnectionInterface $db,
  ) {
  }

  public function hasEntity(string $slug): bool
  {
    return null !== $this->entities->findBySlug($slug);
  }

  /**
   * An entity - created like in the schema (POST /admin/entities), or the one of this slug if there
   * is one already. A new one is managed by the plugin: its fields are locked (no deleting,
   * renaming, other types), it cannot be deleted or get another slug - until the plugin is
   * uninstalled. Editors may add fields and change labels. $managed false: an ordinary entity of the
   * project (e.g. an example).
   *
   * @param array<string, mixed> $data
   * @return array<string, mixed> the entity as the admin API shows it
   */
  public function entity(array $data, bool $managed = true): array
  {
    $existing = $this->entities->findBySlug((string)($data['slug'] ?? ''));
    if (null !== $existing) {
      return $existing->toArray(true);
    }
    $entity = $this->schema->createEntity($data);
    if (!$managed) {
      return $entity->toArray(true);
    }
    $this->db->createCommand()->update('entity', ['managed_by' => $this->plugin], ['id' => $entity->id])->execute();
    $this->db->createCommand()->update('entity_field', ['is_locked' => true], ['entity_id' => $entity->id])->execute();
    $this->entities->reset();
    return ($this->entities->findById($entity->id) ?? $entity)->toArray(true);
  }

  /**
   * A record of an entity (by slug), as POST /entities/{entity}/records takes it.
   *
   * @param array<string, mixed> $data
   * @return array<string, mixed>
   */
  public function record(string $entity, array $data): array
  {
    $definition = $this->entities->findBySlug($entity) ?? throw new \InvalidArgumentException(sprintf('There is no entity "%s".', $entity));
    return $this->records->create($definition, $data);
  }

  /**
   * An event, as POST /admin/events takes it.
   *
   * @param array<string, mixed> $data
   * @return array<string, mixed>
   */
  public function event(array $data): array
  {
    return $this->events->create($data);
  }

  /**
   * Names of the blocks of the project (kind "block") - e.g. to offer them besides the plugin's own.
   *
   * @return list<string>
   */
  public function blocks(): array
  {
    return array_values(array_map(static fn(\App\Domain\Schema\FieldGroup $group): string => $group->name, array_filter($this->entities->groups(), static fn(\App\Domain\Schema\FieldGroup $group): bool => $group->isBlock())));
  }

  /**
   * The active administrators - e.g. as recipients of e-mails ("user:<id>").
   *
   * @return list<array{id: string, name: string, email: string}>
   */
  public function admins(): array
  {
    return array_map(static fn(array $row): array => ['id' => (string)$row['id'], 'name' => (string)$row['name'], 'email' => (string)$row['email']],
      $this->db->createQuery()->from('user')->select(['id', 'name', 'email'])->where(['is_admin' => true, 'is_active' => true])->orderBy(['created_at' => SORT_ASC])->all());
  }
}
