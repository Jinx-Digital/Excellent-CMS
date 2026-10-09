<?php

declare(strict_types=1);

namespace App\Repository;

use App\Application\Content\RecordPresenter;
use App\Application\Media\MediaService;
use App\Application\Service\CurrentProject;
use App\Domain\Schema\Access;
use App\Domain\Schema\EntityDefinition;
use App\Domain\Schema\CustomFieldTypes;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldGroup;
use App\Domain\Schema\FieldType;
use App\Domain\Schema\GroupValues;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\QueryInterface;

/**
 * Rows of the table `media` (the files themselves are in the MediaStorage).
 */
final class MediaRepository
{
  private const CHUNK = 1000;

  public function __construct(
    private ConnectionInterface $db,
    private EntityRepository $entityRepository,
    private CurrentProject $currentProject,
    private RevisionRepository $revisions,
    private WorkingCopyRepository $workingCopies,
  ) {
  }

  /**
   * Files of the current project and of the area "Global" (shared records use them in every
   * project) - all of them without a project, e.g. in the console.
   */
  private function query(): QueryInterface
  {
    $query = $this->db->createQuery()->from('media');
    if (!$this->currentProject->isScoped()) {
      return $query;
    }
    return $query->andWhere(['project_id' => array_values(array_unique(array_filter([$this->currentProject->id(), $this->entityRepository->globalProjectId()])))]);
  }

  public function find(string $id): ?array
  {
    $row = $this->query()->andWhere(['id' => $id])->one();
    return is_array($row) ? $row : null;
  }

  /**
   * @param list<string> $ids
   * @return array<string, array> id => row
   */
  public function findMany(array $ids): array
  {
    $result = [];
    foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
      foreach ($this->query()->andWhere(['id' => $chunk])->all() as $row) {
        $result[(string)$row['id']] = $row;
      }
    }
    return $result;
  }

  public function insert(array $row): void
  {
    $this->db->createCommand()->insert('media', $row + ['created_at' => date('Y-m-d H:i:s')])->execute();
  }

  public function setKept(string $id, bool $kept): void
  {
    $this->db->createCommand()->update('media', ['is_kept' => $kept], ['id' => $id])->execute();
  }

  /**
   * @param array{x: float, y: float}|null $point
   */
  public function setFocalPoint(string $id, ?array $point): void
  {
    $this->db->createCommand()->update('media', ['focal_x' => $point['x'] ?? null, 'focal_y' => $point['y'] ?? null], ['id' => $id])->execute();
  }

  public function rename(string $id, string $name): void
  {
    $this->db->createCommand()->update('media', ['name' => $name], ['id' => $id])->execute();
  }

  public function delete(string $id): void
  {
    $this->db->createCommand()->delete('media', ['id' => $id])->execute();
  }

  /**
   * Ids of all files a media field points to (records in the trash included).
   *
   * @return array<string, true>
   */
  public function usedIds(): array
  {
    $used = [];
    foreach ($this->mediaFields() as ['entity' => $entity, 'field' => $field]) {
      foreach (self::columns($entity, $field) as $column) {
        $values = $this->db->createQuery()->select($column)->distinct()->from($entity->tableName())->where(['not', [$column => null]])->column();
        foreach ($values as $value) {
          foreach (self::ids($field, $value) as $id) {
            $used[$id] = true;
          }
        }
      }
    }
    return $used;
  }

  /**
   * Is the file used by a record of a public entity (not in the trash)? Then everyone may read it.
   * Entities of all projects without a current project (e.g. GET /media/<path>).
   */
  public function isPublic(string $id): bool
  {
    foreach ($this->usages([$id])[$id] ?? [] as ['entity' => $entity, 'row' => $row]) {
      if (Access::Public === $entity->access && null === ($row[EntityDefinition::DELETED_AT] ?? null) && !RecordRepository::isDraft($entity, $row)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Where the given files are used: id => [{entity, field, record_id, row}].
   *
   * @param list<string> $ids
   * @return array<string, list<array{entity: \App\Domain\Schema\EntityDefinition, field: \App\Domain\Schema\FieldDefinition, row: array}>>
   */
  public function usages(array $ids): array
  {
    $result = [];
    if ([] === $ids) {
      return $result;
    }
    $wanted = array_flip($ids);
    foreach ($this->mediaFields() as ['entity' => $entity, 'field' => $field]) {
      $columns = self::columns($entity, $field);
      // Lists and groups are JSON text: find candidates by the quoted id, then check exactly
      $json = $field->isMultipleMedia() || FieldType::Group === $field->type || FieldType::Custom === $field->type;
      $condition = ['or'];
      foreach ($columns as $column) {
        $condition = array_merge($condition, $json ? array_map(static fn(string $id): array => ['like', $column, '"'.$id.'"'], $ids) : [[$column => $ids]]);
      }
      foreach ($this->db->createQuery()->from($entity->tableName())->where($condition)->all() as $row) {
        $found = [];
        foreach ($columns as $column) {
          foreach (self::ids($field, $row[$column] ?? null) as $id) {
            $found[$id] = true;
          }
        }
        foreach (array_keys(array_intersect_key($found, $wanted)) as $id) {
          $result[(string)$id][] = ['entity' => $entity, 'field' => $field, 'row' => $row];
        }
      }
    }
    return $result;
  }

  /**
   * Library: newest first. $kind "image" or "file", $usage "used" or "unused".
   */
  /**
   * @param list<string> $accept only these types ("image/*", "application/pdf" ...), e.g. for the picker of a field
   */
  public function search(string $search, ?string $kind, ?string $usage, array $accept = [], bool $keptOnly = false): QueryInterface
  {
    $query = $this->query()->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]);
    if ([] !== $accept) {
      $types = ['or'];
      foreach (array_values($accept) as $index => $type) {
        // "image/*": every type starting with "image/"
        $types[] = str_ends_with($type, '/*')
          ? new \Yiisoft\Db\Expression\Expression('mime_type LIKE :accept'.$index, [':accept'.$index => substr($type, 0, -1).'%'])
          : ['mime_type' => $type];
      }
      $query->andWhere($types);
    }
    if ($keptOnly) {
      $query->andWhere(['is_kept' => true]);
    }
    if ('' !== trim($search)) {
      $query->andWhere(['like', 'name', trim($search)]);
    }
    if ('image' === $kind) {
      $query->andWhere(['mime_type' => array_keys(MediaService::SHOWN_AS_IMAGE)]);
    } elseif ('file' === $kind) {
      $query->andWhere(['not', ['mime_type' => array_keys(MediaService::SHOWN_AS_IMAGE)]]);
    }
    if ('used' === $usage || 'unused' === $usage) {
      $used = array_keys($this->usedIds());
      if ('used' === $usage) {
        $query->andWhere([] === $used ? '0=1' : ['id' => $used]);
      } elseif ([] !== $used) {
        $query->andWhere(['not', ['id' => $used]]);
      }
    }
    return $query;
  }

  /**
   * Files no media field points to any more (replaced, record deleted, upload never saved),
   * uploaded before $before - younger ones may still be on their way into a record.
   *
   * @return list<array>
   */
  public function unused(string $before): array
  {
    // Files of older revisions stay too, so restoring them brings the file back - and the ones of working copies
    $used = $this->usedIds() + $this->revisions->mediaIds() + $this->workingCopies->mediaIds();
    // Files kept in the media library stay
    $candidates = $this->query()->andWhere(['<=', 'created_at', $before])->andWhere(['is_kept' => false])->all();
    return array_values(array_filter($candidates, static fn(array $row): bool => !isset($used[(string)$row['id']])));
  }

  /**
   * @return list<array{entity: \App\Domain\Schema\EntityDefinition, field: \App\Domain\Schema\FieldDefinition}>
   */
  private function mediaFields(): array
  {
    $result = [];
    foreach ($this->entityRepository->all() as $entity) {
      foreach ($entity->fields as $field) {
        // Rich text: images of the library (data-media-id)
        if (FieldType::Media === $field->type || CustomFieldTypes::usesMedia($field) || [] !== array_filter($field->groups(), static fn(FieldGroup $group): bool => self::groupHasMedia($group))) {
          $result[] = ['entity' => $entity, 'field' => $field];
        }
      }
    }
    return $result;
  }

  private static function groupHasMedia(FieldGroup $group, int $depth = 0): bool
  {
    foreach ($depth <= FieldGroup::MAX_DEPTH ? $group->fields : [] as $field) {
      if (FieldType::Media === $field->type || CustomFieldTypes::usesMedia($field) || [] !== array_filter($field->groups(), static fn(FieldGroup $inner): bool => self::groupHasMedia($inner, $depth + 1))) {
        return true;
      }
    }
    return false;
  }

  /**
   * The field's column and those of its translations.
   *
   * @return list<string>
   */
  private static function columns(EntityDefinition $entity, FieldDefinition $field): array
  {
    return array_merge([$field->name], $field->translatable ? array_map($field->translationColumn(...), $entity->otherLanguages()) : []);
  }

  /**
   * @return list<string>
   */
  private static function ids(FieldDefinition $field, mixed $value): array
  {
    if (FieldType::Group === $field->type) {
      // Groups and blocks: media ids, and the files of fields of plugins inside
      $ids = GroupValues::collect($field, $value, static fn(FieldDefinition $f): bool => FieldType::Media === $f->type);
      GroupValues::each($field, $value, static function (FieldDefinition $inner, mixed $innerValue) use (&$ids): void {
        if (FieldType::Custom === $inner->type) {
          array_push($ids, ...CustomFieldTypes::mediaIds($inner, CustomFieldTypes::fromStorage($inner, $innerValue)));
        }
      });
      return $ids;
    }
    return FieldType::Custom === $field->type
      ? CustomFieldTypes::mediaIds($field, CustomFieldTypes::fromStorage($field, $value))
      : RecordPresenter::mediaIds($field, $value);
  }
}
