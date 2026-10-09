<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Application\Event\EventHooks;
use App\Application\Service\CurrentProject;
use App\Application\Service\CurrentUser;
use App\Domain\Project\Project;
use App\Infrastructure\Media\ImageVariants;
use App\Infrastructure\Media\MediaStorages;
use App\Repository\MediaRepository;
use App\Shared\Exception\UserFacingException;
use App\Shared\I18n;
use App\Shared\Id;
use finfo;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use Yiisoft\RequestProvider\RequestNotSetException;
use Yiisoft\RequestProvider\RequestProviderInterface;

/**
 * Uploads of media fields: POST /media stores the file and returns its id, the record then saves
 * that id in the field. Files nothing points to any more are removed by `./yii cleanup`.
 *
 * The type is taken from the content (not the name or the browser), only the types below are
 * accepted, and the file gets the extension of its real type - so an upload can never become a
 * script or an HTML page on the media domain. SVG is left out for the same reason (it can carry
 * JavaScript).
 */
final class MediaService
{
  public const IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
    'image/tiff' => 'tif',
    'image/bmp' => 'bmp',
    'image/heic' => 'heic',
  ];

  /** Every type that may be uploaded at all (MIME type => extension of the stored file) */
  public const FILE_TYPES = self::IMAGE_TYPES + [
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/json' => 'json',
    'application/rtf' => 'rtf',
    'application/msword' => 'doc',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/vnd.oasis.opendocument.text' => 'odt',
    'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    'application/vnd.oasis.opendocument.presentation' => 'odp',
    'application/epub+zip' => 'epub',
    'application/zip' => 'zip',
    'application/gzip' => 'gz',
    'application/x-tar' => 'tar',
    'application/x-7z-compressed' => '7z',
    'application/x-rar' => 'rar',
    'audio/mpeg' => 'mp3',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
    'audio/ogg' => 'ogg',
    'audio/flac' => 'flac',
    'audio/aac' => 'aac',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/quicktime' => 'mov',
    'video/mpeg' => 'mpeg',
    'video/ogg' => 'ogv',
    'video/x-msvideo' => 'avi',
    'video/x-matroska' => 'mkv',
  ];

  /** Main types for "image/*" etc. in the allowed types of a field */
  public const GROUPS = ['image', 'audio', 'video', 'text', 'application'];
  /** Types an admin may allow in addition (Media library › File types) - off by default */
  public const OPTIONAL_TYPES = [
    // Can contain scripts: served with a sandbox (CSP), so they never run - in <img> they never do anyway
    'image/svg+xml' => 'svg',
  ];
  /** Every type there is: FILE_TYPES (allowed by default) and OPTIONAL_TYPES */
  public const CATALOG = self::FILE_TYPES + self::OPTIONAL_TYPES;
  /** Shown as images (thumbnails, "images" filter) - SVG too, but it is not transformed */
  public const SHOWN_AS_IMAGE = self::IMAGE_TYPES + ['image/svg+xml' => 'svg'];
  /** Setting (table setting) with the allowed types; none: FILE_TYPES */
  public const SETTING = 'media_types';

  /**
   * Name of a main type ("Images") - and of all of its files ("all images") for messages.
   */
  public static function groupLabel(string $main, bool $all = false): string
  {
    return match ($main) {
      'image' => $all ? I18n::t('all images') : I18n::t('Images'),
      'audio' => $all ? I18n::t('all audio files') : I18n::t('Audio'),
      'video' => $all ? I18n::t('all videos') : I18n::t('Video'),
      'text' => $all ? I18n::t('all text files') : I18n::t('Text'),
      'application' => $all ? I18n::t('all documents and archives') : I18n::t('Documents and archives'),
      default => $main,
    };
  }

  /** Unused uploads are kept this long - the record they belong to may not be saved yet */
  public const KEEP_UNUSED = 86400;

  public function __construct(
    private MediaStorages $storages,
    private MediaRepository $media,
    private CurrentUser $currentUser,
    private CurrentProject $currentProject,
    private int $maxSize,
    /** Web requests only: makes relative media URLs absolute */
    private ?RequestProviderInterface $requestProvider = null,
    /** Signs the addresses of protected files (served by the CMS) */
    private ?MediaUrlSigner $signer = null,
    /** Events on the media of the project */
    private ?EventHooks $events = null,
    /** Transformed images, deleted with their file */
    private ?ImageVariants $variants = null,
    /** The allowed types (Media library › File types) */
    private ?\App\Repository\SettingRepository $settings = null,
  ) {
  }

  /**
   * @param list<string>|null $accept allowed types of the field the file is meant for (checked at once)
   * @param bool $keep upload into the media library: the file stays even while nothing uses it
   * @param Project|null $project another project than the current one (fields of global entities)
   */
  public function upload(UploadedFileInterface $file, ?array $accept = null, bool $keep = false, ?Project $project = null): array
  {
    if (UPLOAD_ERR_INI_SIZE === $file->getError() || UPLOAD_ERR_FORM_SIZE === $file->getError() || (int)$file->getSize() > $this->maxSize) {
      throw new UserFacingException(I18n::t('The file is too large (at most {size} MB).', ['size' => round($this->maxSize / 1024 / 1024, 1)]));
    }
    if (UPLOAD_ERR_OK !== $file->getError()) {
      throw new UserFacingException(I18n::t('The file could not be uploaded. Please try again.'));
    }

    $temporary = tempnam(sys_get_temp_dir(), 'media');
    try {
      $file->moveTo($temporary);
      $size = (int)filesize($temporary);
      if (0 === $size) {
        throw new UserFacingException(I18n::t('The file is empty.'));
      }
      if ($size > $this->maxSize) {
        throw new UserFacingException(I18n::t('The file is too large (at most {size} MB).', ['size' => round($this->maxSize / 1024 / 1024, 1)]));
      }

      $mimeType = self::detect($temporary);
      if (!in_array($mimeType, $this->allowedTypes(), true)) {
        throw new UserFacingException(I18n::t('This file type is not allowed ({type}). Allowed: {types}.', [
          'type' => $mimeType,
          'types' => implode(', ', array_unique(array_map(static fn(string $type): string => strtoupper(self::CATALOG[$type]), $this->allowedTypes()))),
        ]), 422, 'media_type');
      }
      $extension = self::CATALOG[$mimeType];
      if (null !== $accept && !self::accepts($accept, $mimeType)) {
        throw new UserFacingException(sprintf('Hier sind nur diese Dateitypen erlaubt: %s.', self::describeAccept($accept)), 422, 'media_type');
      }
      [$width, $height] = isset(self::IMAGE_TYPES[$mimeType]) ? self::dimensions($temporary) : [null, null];

      $id = Id::new();
      // Files of global records belong to the area "Global"
      $project ??= $this->currentProject->get();
      // One folder per project: media/<project>/2026/10/<id>.png
      $path = $project->slug.'/'.date('Y/m').'/'.$id.'.'.$extension;
      // The storage the project picked (the default one otherwise)
      $storage = $this->storages->forProject($project);
      $stream = fopen($temporary, 'rb');
      try {
        $storage->put($path, $stream, $mimeType);
      } finally {
        fclose($stream);
      }

      $row = [
        'id' => $id,
        'disk' => $storage->disk(),
        'path' => $path,
        'name' => self::fileName((string)$file->getClientFilename(), $extension),
        'mime_type' => $mimeType,
        'size' => $size,
        'width' => $width,
        'height' => $height,
        'created_by' => $this->currentUser->getId(),
        'is_kept' => $keep,
        'project_id' => $project->id,
      ];
      try {
        $this->media->insert($row);
      } catch (Throwable $e) {
        $storage->delete($path);
        throw $e;
      }
      $presented = $this->present($row) + ['kept' => $keep];
      $this->events?->data('media', $project->id, 'create', $id, $presented);
      // Admin app: whether the media library keeps it
      return $presented;
    } finally {
      if (is_file($temporary)) {
        unlink($temporary);
      }
    }
  }

  public function find(string $id): ?array
  {
    return $this->media->find($id);
  }

  /**
   * @param list<string> $ids
   * @param bool $public the files belong to records of a public entity (unsigned addresses)
   * @return array<string, array> id => presented file
   */
  public function presentMany(array $ids, bool $public = false): array
  {
    return array_map(fn(array $row): array => $this->present($row, $public), $this->media->findMany($ids));
  }

  /**
   * Files of the local folder and of private buckets are served by GET /media/<path>
   * (MediaFileController): without $public the address is signed, so only who got it from the API can read the file.
   *
   * @return array{id: string, url: string, name: string, mime_type: string, size: int, width: ?int, height: ?int, is_image: bool, transform_url: ?string, focal_point: ?array{x: float, y: float}}
   */
  public function present(array $row, bool $public = false): array
  {
    // A storage that is not configured any more: the file has no address
    $storage = $this->storages->find((string)$row['disk']);
    $url = null !== $storage ? $this->absoluteUrl($storage->url((string)$row['path'])) : '';
    if (!$public && null !== $this->signer && null !== $storage && $storage->servedByCms()) {
      $url = $this->signer->sign($url, (string)$row['path']);
    }
    // Images: the address to ask for other sizes and formats (?w=400&format=webp, see ImageTransform).
    // It carries the focal point, so moving it gives new addresses (variants are cached for a year).
    $transformUrl = null;
    $focal = self::focalPoint($row);
    if (null !== $storage && ImageVariants::canRead((string)$row['mime_type'])) {
      $transformUrl = $storage->servedByCms() ? $url : $this->absoluteUrl($this->storages->cmsUrl((string)$row['path']));
      if (!$public && null !== $this->signer && !$storage->servedByCms()) {
        $transformUrl = $this->signer->sign($transformUrl, (string)$row['path']);
      }
      if (null !== $focal) {
        $transformUrl .= (str_contains($transformUrl, '?') ? '&' : '?').'fp='.ImageTransform::formatFocalPoint($focal);
      }
    }
    return [
      'id' => (string)$row['id'],
      'url' => $url,
      'name' => (string)$row['name'],
      'mime_type' => (string)$row['mime_type'],
      'size' => (int)$row['size'],
      'width' => null !== $row['width'] ? (int)$row['width'] : null,
      'height' => null !== $row['height'] ? (int)$row['height'] : null,
      'is_image' => self::isImage($row),
      'transform_url' => $transformUrl,
      'focal_point' => $focal,
    ];
  }

  /**
   * @return array{x: float, y: float}|null
   */
  public static function focalPoint(array $row): ?array
  {
    if (!is_numeric($row['focal_x'] ?? null) || !is_numeric($row['focal_y'] ?? null)) {
      return null;
    }
    return ['x' => (float)$row['focal_x'], 'y' => (float)$row['focal_y']];
  }

  public static function isImage(array $row): bool
  {
    return isset(self::SHOWN_AS_IMAGE[(string)$row['mime_type']]);
  }

  /**
   * The types that may be uploaded (Media library › File types): FILE_TYPES unless the admin chose others.
   *
   * @return list<string>
   */
  public function allowedTypes(): array
  {
    $stored = $this->settings?->get(self::SETTING);
    return is_array($stored) ? array_values(array_intersect(array_keys(self::CATALOG), $stored)) : array_keys(self::FILE_TYPES);
  }

  /**
   * MIME type of a file by its content - SVG also when it is detected as XML or text.
   */
  public static function detect(string $path): string
  {
    $type = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (in_array($type, ['image/svg', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true)) {
      $head = (string)file_get_contents($path, false, null, 0, 4096);
      if (1 === preg_match('/<svg[\s>]/i', $head) && 1 !== preg_match('/<html[\s>]/i', $head)) {
        return 'image/svg+xml';
      }
    }
    return $type;
  }

  /**
   * Is the type one of the allowed types of a field? Empty list = every type of FILE_TYPES.
   *
   * @param list<string> $accept e.g. ["image/*", "application/pdf"]
   */
  public static function accepts(array $accept, string $mimeType): bool
  {
    if ([] === $accept) {
      return true;
    }
    foreach ($accept as $entry) {
      if ($entry === $mimeType || (str_ends_with($entry, '/*') && str_starts_with($mimeType, substr($entry, 0, -1)))) {
        return true;
      }
    }
    return false;
  }

  /**
   * "image/*" (main type of FILE_TYPES) or one type of FILE_TYPES.
   */
  public static function isValidAccept(string $entry): bool
  {
    return isset(self::CATALOG[$entry]) || (str_ends_with($entry, '/*') && in_array(substr($entry, 0, -2), self::GROUPS, true));
  }

  /**
   * "all images, PDF" - for messages.
   *
   * @param list<string> $accept
   */
  public static function describeAccept(array $accept): string
  {
    return implode(', ', array_map(static fn(string $entry): string => str_ends_with($entry, '/*')
      ? self::groupLabel(substr($entry, 0, -2), true)
      : strtoupper(self::CATALOG[$entry] ?? $entry), $accept));
  }

  /**
   * Choices for the allowed types of a field, grouped by main type: "image/*" first, then each type.
   *
   * @return list<array{group: string, items: list<array{value: string, label: string}>}>
   */
  public static function acceptOptions(): array
  {
    $result = [];
    foreach (self::GROUPS as $main) {
      $label = self::groupLabel($main);
      $items = [['value' => $main.'/*', 'label' => I18n::t('All {group} ({type})', ['group' => $label, 'type' => $main.'/*'])]];
      foreach (self::CATALOG as $mime => $extension) {
        if (str_starts_with($mime, $main.'/')) {
          $items[] = ['value' => $mime, 'label' => sprintf('%s (%s)', strtoupper($extension), $mime)];
        }
      }
      $result[] = ['group' => $label, 'items' => $items];
    }
    return $result;
  }

  /**
   * Deletes files no record uses (also called by `./yii cleanup`).
   */
  public function removeUnused(int $olderThan = self::KEEP_UNUSED): int
  {
    $count = 0;
    foreach ($this->media->unused(date('Y-m-d H:i:s', time() - $olderThan)) as $row) {
      // A file of a storage that is not configured any more cannot be deleted - keep its row
      $storage = $this->storages->find((string)$row['disk']);
      if (null === $storage) {
        continue;
      }
      $storage->delete((string)$row['path']);
      $this->variants?->forget((string)$row['id']);
      $this->media->delete((string)$row['id']);
      $count++;
    }
    return $count;
  }

  /**
   * A relative MEDIA_URL (default /media) gets scheme and host of the current request, so API
   * consumers on other domains get working links. Absolute ones (CDN, bucket) stay as they are.
   */
  private function absoluteUrl(string $url): string
  {
    if (!str_starts_with($url, '/') || str_starts_with($url, '//') || null === $this->requestProvider) {
      return $url;
    }
    try {
      $uri = $this->requestProvider->get()->getUri();
    } catch (RequestNotSetException) {
      return $url;
    }
    if ('' === $uri->getHost()) {
      return $url;
    }
    $port = null !== $uri->getPort() ? ':'.$uri->getPort() : '';
    return sprintf('%s://%s%s%s', $uri->getScheme() ?: 'http', $uri->getHost(), $port, $url);
  }

  /**
   * @return array{0: ?int, 1: ?int}
   */
  private static function dimensions(string $file): array
  {
    $size = @getimagesize($file);
    return false !== $size ? [(int)$size[0], (int)$size[1]] : [null, null];
  }

  private static function fileName(string $clientName, string $extension): string
  {
    $name = trim(str_replace(['/', '\\', "\0"], '', basename($clientName)));
    if ('' === $name) {
      $name = 'datei.'.$extension;
    }
    return mb_strimwidth($name, 0, 255, '');
  }
}
