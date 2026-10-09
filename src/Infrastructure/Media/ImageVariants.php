<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Application\Media\ImageTransform;
use GdImage;
use RuntimeException;

/**
 * Transformed images (see ImageTransform), made with GD on the first request and kept in
 * runtime/media-variants/<file id>/<key> - files never change (a new upload gets a new id), so a
 * variant stays valid until its file is deleted (forget(), and `./yii cleanup` for leftovers).
 */
final class ImageVariants
{
  /** Originals larger than this are not loaded into memory (about 200 MB as truecolor image) */
  private const MAX_PIXELS = 50_000_000;

  public function __construct(
    private string $directory,
    /** Largest width and height that can be asked for */
    public readonly int $maxSize = 4000,
  ) {
  }

  /** Types GD can read */
  public static function canRead(string $mimeType): bool
  {
    return match ($mimeType) {
      'image/jpeg', 'image/png', 'image/gif' => true,
      'image/webp' => function_exists('imagecreatefromwebp'),
      'image/avif' => function_exists('imagecreatefromavif'),
      'image/bmp' => function_exists('imagecreatefrombmp'),
      default => false,
    };
  }

  /**
   * Path of the variant on disk - made now if it does not exist yet.
   *
   * @param callable(): (resource|null) $read opens the original
   * @throws RuntimeException if the original cannot be read or is too large
   */
  public function get(string $id, string $mimeType, ImageTransform $transform, callable $read): string
  {
    $path = $this->path($id, $transform->key($mimeType));
    if (is_file($path)) {
      return $path;
    }

    $stream = $read();
    if (null === $stream) {
      throw new RuntimeException('The file is missing in its storage.');
    }
    $data = (string)stream_get_contents($stream);
    fclose($stream);

    $size = @getimagesizefromstring($data);
    if (false === $size || $size[0] * $size[1] > self::MAX_PIXELS) {
      throw new RuntimeException('The image cannot be transformed.');
    }
    $image = @imagecreatefromstring($data);
    if (!$image instanceof GdImage) {
      throw new RuntimeException('The image cannot be transformed.');
    }
    $image = $this->orient($image, $data, $mimeType);
    $format = $transform->formatFor($mimeType);
    $result = $this->transform($image, $transform, $format);

    if (!is_dir(dirname($path))) {
      @mkdir(dirname($path), 0775, true);
    }
    // Written next to it and renamed: parallel requests never serve a half written file
    $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
    $this->write($result, $temporary, $format, $transform->quality);
    rename($temporary, $path);
    return $path;
  }

  /**
   * Deletes the variants of a file.
   */
  public function forget(string $id): void
  {
    $directory = $this->directory.'/'.$this->safe($id);
    foreach (glob($directory.'/*') ?: [] as $file) {
      @unlink($file);
    }
    @rmdir($directory);
  }

  /**
   * Variants of files that do not exist any more.
   *
   * @param callable(list<string>): list<string> $existing ids of the given ones that still exist
   * @return int number of deleted folders
   */
  public function removeOrphans(callable $existing): int
  {
    $ids = array_map('basename', glob($this->directory.'/*', GLOB_ONLYDIR) ?: []);
    $orphans = array_diff($ids, $existing($ids));
    foreach ($orphans as $id) {
      $this->forget($id);
    }
    return count($orphans);
  }

  private function path(string $id, string $key): string
  {
    return $this->directory.'/'.$this->safe($id).'/'.$key;
  }

  private function safe(string $id): string
  {
    if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
      throw new RuntimeException('Invalid file id.');
    }
    return $id;
  }

  /**
   * Photos from cameras are stored sideways with an EXIF orientation - turned the way they are seen.
   */
  private function orient(GdImage $image, string $data, string $mimeType): GdImage
  {
    if ('image/jpeg' !== $mimeType || !function_exists('exif_read_data')) {
      return $image;
    }
    $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($data));
    $orientation = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
    $rotated = match ($orientation) {
      3, 4 => imagerotate($image, 180, 0),
      5, 6 => imagerotate($image, -90, 0),
      7, 8 => imagerotate($image, 90, 0),
      default => $image,
    };
    if (!$rotated instanceof GdImage) {
      return $image;
    }
    if (in_array($orientation, [2, 4, 5, 7], true)) {
      imageflip($rotated, IMG_FLIP_HORIZONTAL);
    }
    return $rotated;
  }

  private function transform(GdImage $image, ImageTransform $transform, string $format): GdImage
  {
    $sourceWidth = imagesx($image);
    $sourceHeight = imagesy($image);
    $width = $transform->width;
    $height = $transform->height;

    if ('cover' === $transform->fit && null !== $width && null !== $height) {
      // The part of the original with the proportions of the result - smaller originals keep their size
      $scale = min(1, max($width / $sourceWidth, $height / $sourceHeight));
      $cropWidth = min($sourceWidth, (int)round($width / max($width / $sourceWidth, $height / $sourceHeight)));
      $cropHeight = min($sourceHeight, (int)round($height / max($width / $sourceWidth, $height / $sourceHeight)));
      if (null !== $transform->focalPoint) {
        // The focal point as near the middle of the part as the edges allow
        $x = (int)round(max(0, min($sourceWidth - $cropWidth, $transform->focalPoint['x'] * $sourceWidth - $cropWidth / 2)));
        $y = (int)round(max(0, min($sourceHeight - $cropHeight, $transform->focalPoint['y'] * $sourceHeight - $cropHeight / 2)));
      } else {
        $x = match ($transform->position) {
          'left' => 0,
          'right' => $sourceWidth - $cropWidth,
          default => intdiv($sourceWidth - $cropWidth, 2),
        };
        $y = match ($transform->position) {
          'top' => 0,
          'bottom' => $sourceHeight - $cropHeight,
          default => intdiv($sourceHeight - $cropHeight, 2),
        };
      }
      $targetWidth = $scale < 1 ? $width : $cropWidth;
      $targetHeight = $scale < 1 ? $height : $cropHeight;
      $result = $this->canvas($targetWidth, $targetHeight, $format, 'transparent');
      imagecopyresampled($result, $image, 0, 0, $x, $y, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
      return $result;
    }

    // inside and contain: the whole image, never enlarged
    $scale = min(1, null !== $width ? $width / $sourceWidth : INF, null !== $height ? $height / $sourceHeight : INF);
    $imageWidth = max(1, (int)round($sourceWidth * $scale));
    $imageHeight = max(1, (int)round($sourceHeight * $scale));
    if ('contain' === $transform->fit && null !== $width && null !== $height) {
      $result = $this->canvas($width, $height, $format, $transform->backgroundFor($format));
      imagecopyresampled($result, $image, intdiv($width - $imageWidth, 2), intdiv($height - $imageHeight, 2), 0, 0, $imageWidth, $imageHeight, $sourceWidth, $sourceHeight);
      return $result;
    }
    $result = $this->canvas($imageWidth, $imageHeight, $format, 'transparent');
    imagecopyresampled($result, $image, 0, 0, 0, 0, $imageWidth, $imageHeight, $sourceWidth, $sourceHeight);
    return $result;
  }

  /**
   * Empty image: filled with the color, or transparent (white for jpg, which has no transparency).
   */
  private function canvas(int $width, int $height, string $format, string $background): GdImage
  {
    $canvas = imagecreatetruecolor($width, $height);
    if ('transparent' === $background) {
      $background = 'jpg' === $format ? 'ffffffff' : 'ffffff00';
    }
    [$red, $green, $blue, $alpha] = array_map('hexdec', str_split($background, 2));
    // GD: 0 opaque - 127 transparent
    $color = (int)imagecolorallocatealpha($canvas, $red, $green, $blue, 127 - intdiv((int)$alpha * 127, 255));
    imagealphablending($canvas, false);
    imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $color);
    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);
    return $canvas;
  }

  private function write(GdImage $image, string $path, string $format, int $quality): void
  {
    $written = match ($format) {
      'jpg' => imagejpeg($image, $path, $quality),
      'png' => imagepng($image, $path, 6),
      'webp' => imagewebp($image, $path, $quality),
      'gif' => imagegif($image, $path),
      'avif' => imageavif($image, $path, $quality),
      default => false,
    };
    if (!$written) {
      throw new RuntimeException('The image could not be saved.');
    }
  }
}
