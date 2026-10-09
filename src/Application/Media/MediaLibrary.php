<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Application\Content\RecordPresenter;
use App\Application\Event\EventHooks;
use App\Application\Service\CurrentUser;
use App\Domain\Access\EntityPermission;
use App\Domain\Schema\EntityDefinition;
use App\Infrastructure\Media\ImageVariants;
use App\Infrastructure\Media\MediaStorages;
use App\Repository\MediaRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use Yiisoft\Db\Query\QueryInterface;

/**
 * Media library of the admin app: all uploaded files and where they are used. Usages in entities
 * the user may not read are only counted, not named.
 */
final class MediaLibrary
{
  public function __construct(
    private MediaRepository $media,
    private MediaService $files,
    private MediaStorages $storages,
    private RecordPresenter $presenter,
    private CurrentUser $currentUser,
    private EventHooks $events,
    private ImageVariants $variants,
  ) {
  }

  public function search(string $search, ?string $kind, ?string $usage, array $accept = [], bool $keptOnly = false): QueryInterface
  {
    return $this->media->search($search, $kind, $usage, $accept, $keptOnly);
  }

  /**
   * Keep in the media library (or not), and the name shown for the file.
   */
  public function update(string $id, array $data): array
  {
    $file = $this->media->find($id) ?? throw UserFacingException::notFound(I18n::t('This file does not exist (any more).'));
    $before = $this->get((string)$file['id']);
    if (array_key_exists('kept', $data)) {
      $this->media->setKept((string)$file['id'], (bool)filter_var($data['kept'], FILTER_VALIDATE_BOOL));
    }
    if (array_key_exists('name', $data)) {
      $name = trim(str_replace(['/', '\\', "\0"], '', (string)$data['name']));
      if ('' === $name || mb_strlen($name) > 255) {
        throw \App\Shared\Exception\ValidationException::field('name', I18n::t('Please enter a name (at most 255 characters).'));
      }
      $this->media->rename((string)$file['id'], $name);
    }
    // Images: the point cropped variants keep in view, {x, y} from 0 to 1 - null removes it
    if (array_key_exists('focal_point', $data)) {
      $this->media->setFocalPoint((string)$file['id'], self::focalPointInput($data['focal_point'], $file));
    }
    $after = $this->get((string)$file['id']);
    if ($after['name'] !== $before['name'] || $after['kept'] !== $before['kept'] || $after['focal_point'] !== $before['focal_point']) {
      $this->events->data('media', (string)$file['project_id'], 'update', (string)$file['id'], self::eventData($after), self::eventData($before));
    }
    return $after;
  }

  /**
   * @param list<array> $rows rows of the table `media`
   * @return list<array> presented files with `uploaded_at`, `usage_count`, `usages` and `hidden_usages`
   */
  public function present(array $rows): array
  {
    $usages = $this->media->usages(array_map(static fn(array $row): string => (string)$row['id'], $rows));
    return array_map(function (array $row) use ($usages): array {
      $visible = [];
      $hidden = 0;
      foreach ($usages[(string)$row['id']] ?? [] as ['entity' => $entity, 'field' => $field, 'row' => $record]) {
        /** @var EntityDefinition $entity */
        if (!$this->currentUser->can(EntityPermission::Read, $entity)) {
          $hidden++;
          continue;
        }
        $visible[] = [
          'entity' => $entity->slug,
          'entity_name' => $entity->name,
          'field' => $field->name,
          'field_label' => $field->label,
          'record_id' => (string)$record['id'],
          'record_label' => $this->presenter->label($entity, $record),
          'in_trash' => null !== ($record[EntityDefinition::DELETED_AT] ?? null),
        ];
      }
      return $this->files->present($row) + [
        'uploaded_at' => (string)$row['created_at'],
        // Kept in the media library: not removed by the cleanup while unused
        'kept' => (bool)($row['is_kept'] ?? false),
        'usage_count' => count($visible) + $hidden,
        'usages' => $visible,
        'hidden_usages' => $hidden,
      ];
    }, $rows);
  }

  public function get(string $id): array
  {
    $row = $this->media->find($id) ?? throw UserFacingException::notFound(I18n::t('This file does not exist (any more).'));
    return $this->present([$row])[0];
  }

  /**
   * Admins, only files nothing uses - the others are protected by the foreign keys anyway.
   */
  public function delete(string $id): void
  {
    $this->currentUser->assertAdmin();
    $this->deleteUnused($id);
  }

  /**
   * Without the admin check - the content API checks the client's permission itself.
   */
  public function deleteUnused(string $id): void
  {
    $file = $this->get($id);
    if ($file['usage_count'] > 0) {
      throw UserFacingException::conflict(I18n::t('"{name}" is still used {count, plural, one{once} other{# times}} and cannot be deleted.', ['name' => $file['name'], 'count' => $file['usage_count']]), 'media_in_use');
    }
    $row = (array)$this->media->find($id);
    // Events see the file before it is gone
    $this->events->data('media', (string)$row['project_id'], 'delete', $id, self::eventData($file));
    $this->storages->find((string)$row['disk'])?->delete((string)$row['path']);
    $this->variants->forget($id);
    $this->media->delete($id);
  }

  /**
   * A file as events get it: as the API presents it, without where it is used.
   */
  /**
   * @return array{x: float, y: float}|null
   */
  private static function focalPointInput(mixed $value, array $file): ?array
  {
    if (null === $value) {
      return null;
    }
    if (!MediaService::isImage($file)) {
      throw \App\Shared\Exception\ValidationException::field('focal_point', I18n::t('Only images have a focal point.'));
    }
    $x = is_array($value) ? ($value['x'] ?? null) : null;
    $y = is_array($value) ? ($value['y'] ?? null) : null;
    if (!is_numeric($x) || !is_numeric($y) || (float)$x < 0 || (float)$x > 1 || (float)$y < 0 || (float)$y > 1) {
      throw \App\Shared\Exception\ValidationException::field('focal_point', I18n::t('The focal point needs x and y from 0 to 1.'));
    }
    return ['x' => round((float)$x, 4), 'y' => round((float)$y, 4)];
  }

  private static function eventData(array $file): array
  {
    return array_diff_key($file, ['usages' => true, 'usage_count' => true]);
  }
}
