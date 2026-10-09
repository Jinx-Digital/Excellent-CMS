<?php

declare(strict_types=1);

namespace App\Application\Media;

use InvalidArgumentException;

/**
 * What an image should look like, from the query of GET /media/<path>:
 *
 *   w, h      width and height in pixels (1 - max)
 *   fit       cover   fills w × h, cuts off what sticks out (default with w and h)
 *             contain fits into w × h, the rest is filled with bg ("whitespace")
 *             inside  fits into w and/or h, keeps its proportions (default with only one of them)
 *   pos       which part stays with cover: center (default), top, bottom, left, right
 *   fp        focal point "x,y" (0 - 1 from the left and the top) that cover keeps in view - the
 *             transform_url of an image with a focal point carries it; it wins over pos
 *   bg        fill of contain: hex color (fff, ffffff, ffffff80) or transparent
 *             (default: transparent, white for jpg)
 *   format    jpg, png, webp, gif, avif (where the server can write it) - default: the original one
 *   q         quality 1 - 100 for jpg, webp and avif (default 82)
 *
 * Images are never made larger than they are: contain always gives w × h, cover gives originals
 * smaller than that in the proportions of w × h, inside the original size.
 * Equal transforms have the same key() - each one is made once and then cached (ImageVariants).
 */
final class ImageTransform
{
  public const PARAMETERS = ['w', 'h', 'fit', 'pos', 'bg', 'format', 'q'];
  public const FITS = ['cover', 'contain', 'inside'];
  public const POSITIONS = ['center', 'top', 'bottom', 'left', 'right'];
  public const DEFAULT_QUALITY = 82;

  /** Formats GD writes, as name => mime type */
  public const FORMATS = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'avif' => 'image/avif',
  ];

  private function __construct(
    public readonly ?int $width,
    public readonly ?int $height,
    public readonly string $fit,
    public readonly string $position,
    /** "transparent" or rrggbbaa */
    public readonly ?string $background,
    /** null: the format of the original */
    public readonly ?string $format,
    public readonly int $quality,
    /** @var array{x: float, y: float}|null */
    public readonly ?array $focalPoint = null,
  ) {
  }

  /**
   * "0.3,0.62" - as fp in addresses
   *
   * @param array{x: float, y: float} $point
   */
  public static function formatFocalPoint(array $point): string
  {
    $number = static fn(float $value): string => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    return $number($point['x']).','.$number($point['y']);
  }

  /**
   * Null without any of the parameters - the original file is served.
   *
   * @param array<string, mixed> $query
   * @throws InvalidArgumentException with a message for the reader
   */
  public static function fromQuery(array $query, int $maxSize): ?self
  {
    // The focal point alone changes nothing: the original is served
    if ([] === array_intersect(self::PARAMETERS, array_keys($query))) {
      return null;
    }
    $size = static function (string $name) use ($query, $maxSize): ?int {
      $value = $query[$name] ?? null;
      if (null === $value || '' === $value) {
        return null;
      }
      if (!is_string($value) || 1 !== preg_match('/^\d{1,5}$/', $value) || (int)$value < 1 || (int)$value > $maxSize) {
        throw new InvalidArgumentException("$name must be a number from 1 to $maxSize.");
      }
      return (int)$value;
    };
    $width = $size('w');
    $height = $size('h');

    $fit = self::text($query, 'fit') ?? (null !== $width && null !== $height ? 'cover' : 'inside');
    if (!in_array($fit, self::FITS, true)) {
      throw new InvalidArgumentException('fit must be one of '.implode(', ', self::FITS).'.');
    }
    if ('inside' !== $fit && (null === $width || null === $height)) {
      throw new InvalidArgumentException("fit=$fit needs w and h.");
    }

    $position = self::text($query, 'pos') ?? 'center';
    if (!in_array($position, self::POSITIONS, true)) {
      throw new InvalidArgumentException('pos must be one of '.implode(', ', self::POSITIONS).'.');
    }

    $format = self::text($query, 'format');
    $format = 'jpeg' === $format ? 'jpg' : $format;
    if (null !== $format && !in_array($format, self::writableFormats(), true)) {
      throw new InvalidArgumentException('format must be one of '.implode(', ', self::writableFormats()).'.');
    }

    $quality = $query['q'] ?? null;
    if (null !== $quality && '' !== $quality) {
      if (!is_string($quality) || 1 !== preg_match('/^\d{1,3}$/', $quality) || (int)$quality < 1 || (int)$quality > 100) {
        throw new InvalidArgumentException('q must be a number from 1 to 100.');
      }
    }

    $focal = null;
    $fp = self::text($query, 'fp');
    if (null !== $fp) {
      if (1 !== preg_match('/^(0(?:\.\d{1,4})?|1(?:\.0{1,4})?),(0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/', $fp, $m)) {
        throw new InvalidArgumentException('fp must be "x,y" with numbers from 0 to 1.');
      }
      $focal = ['x' => (float)$m[1], 'y' => (float)$m[2]];
    }

    return new self($width, $height, $fit, $position, self::background(self::text($query, 'bg')), $format, null !== $quality && '' !== $quality ? (int)$quality : self::DEFAULT_QUALITY, $focal);
  }

  /**
   * @return list<string>
   */
  public static function writableFormats(): array
  {
    return array_values(array_filter(array_keys(self::FORMATS), static fn(string $format): bool => match ($format) {
      'avif' => function_exists('imageavif'),
      'webp' => function_exists('imagewebp'),
      default => true,
    }));
  }

  /**
   * Format of the result: the requested one, or the one of the original if GD writes it (else webp).
   */
  public function formatFor(string $mimeType): string
  {
    if (null !== $this->format) {
      return $this->format;
    }
    $original = array_search($mimeType, self::FORMATS, true);
    return false !== $original && in_array($original, self::writableFormats(), true) ? $original : 'webp';
  }

  /**
   * File name of the result, the same for the same transform: "w400-h300-cover-center-transparent-q82.webp"
   */
  public function key(string $mimeType): string
  {
    $format = $this->formatFor($mimeType);
    return implode('-', [
      'w'.($this->width ?? 0),
      'h'.($this->height ?? 0),
      $this->fit,
      'cover' === $this->fit ? (null !== $this->focalPoint ? 'fp'.str_replace(',', 'x', self::formatFocalPoint($this->focalPoint)) : $this->position) : 'center',
      'contain' === $this->fit ? $this->backgroundFor($format) : 'none',
      'q'.(in_array($format, ['jpg', 'webp', 'avif'], true) ? $this->quality : 0),
    ]).'.'.$format;
  }

  /**
   * The fill of contain: rrggbbaa, "transparent" only where the format has transparency.
   */
  public function backgroundFor(string $format): string
  {
    $background = $this->background ?? ('jpg' === $format ? 'ffffffff' : 'transparent');
    if ('transparent' === $background && 'jpg' === $format) {
      return 'ffffffff';
    }
    return $background;
  }

  /**
   * @param array<string, mixed> $query
   */
  private static function text(array $query, string $name): ?string
  {
    $value = $query[$name] ?? null;
    if (null === $value || '' === $value) {
      return null;
    }
    if (!is_string($value)) {
      throw new InvalidArgumentException("$name must be a text.");
    }
    return strtolower(trim($value));
  }

  private static function background(?string $value): ?string
  {
    if (null === $value || 'transparent' === $value) {
      return $value;
    }
    $hex = ltrim($value, '#');
    if (1 !== preg_match('/^(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/', $hex)) {
      throw new InvalidArgumentException('bg must be a hex color (fff, ffffff, ffffff80) or transparent.');
    }
    if (3 === strlen($hex)) {
      $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    return str_pad($hex, 8, 'f');
  }
}
