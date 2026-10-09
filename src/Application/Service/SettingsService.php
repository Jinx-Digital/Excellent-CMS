<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Media\MediaService;
use App\Repository\SettingRepository;
use App\Shared\Exception\ValidationException;
use App\Shared\I18n;

/**
 * Settings changed in the admin app. The rate limiter of the content API is optional: off, or
 * N requests per window and client (OAuth) or IP address (public access).
 */
final class SettingsService
{
  private const RATE_LIMIT = 'rate_limit';

  /**
   * @param array{enabled: bool, requests: int, window: int} $rateLimitDefaults from .env
   */
  public function __construct(
    private SettingRepository $settings,
    private array $rateLimitDefaults,
  ) {
  }

  /**
   * File types of uploads (Media library › File types): every type there is, by group, and the allowed ones.
   *
   * @return array{types: list<array{type: string, extension: string, group: string, optional: bool}>, allowed: list<string>}
   */
  public function mediaTypes(): array
  {
    $stored = $this->settings->get(MediaService::SETTING);
    $types = [];
    foreach (MediaService::CATALOG as $type => $extension) {
      $main = explode('/', $type)[0];
      $group = match (true) {
        in_array($main, ['image', 'audio', 'video'], true) => $main,
        in_array($extension, ['zip', 'gz', 'tar', '7z', 'rar'], true) => 'archive',
        default => 'document',
      };
      $types[] = ['type' => $type, 'extension' => $extension, 'group' => $group, 'optional' => isset(MediaService::OPTIONAL_TYPES[$type])];
    }
    return [
      'types' => $types,
      'allowed' => is_array($stored) ? array_values(array_intersect(array_keys(MediaService::CATALOG), $stored)) : array_keys(MediaService::FILE_TYPES),
    ];
  }

  /**
   * @param array{allowed?: mixed} $data
   * @return array{types: list<array>, allowed: list<string>}
   */
  public function updateMediaTypes(array $data): array
  {
    $allowed = array_values(array_intersect(array_keys(MediaService::CATALOG), array_map('strval', (array)($data['allowed'] ?? []))));
    if ([] === $allowed) {
      throw ValidationException::field('allowed', I18n::t('Please allow at least one file type.'));
    }
    $this->settings->set(MediaService::SETTING, $allowed);
    return $this->mediaTypes();
  }

  /**
   * @return array{enabled: bool, requests: int, window: int}
   */
  public function rateLimit(): array
  {
    $stored = $this->settings->get(self::RATE_LIMIT);
    $value = (is_array($stored) ? $stored : []) + $this->rateLimitDefaults;
    return [
      'enabled' => (bool)$value['enabled'],
      'requests' => max(1, (int)$value['requests']),
      'window' => max(1, (int)$value['window']),
    ];
  }

  /**
   * @return array{enabled: bool, requests: int, window: int}
   */
  public function updateRateLimit(array $data): array
  {
    $value = $this->rateLimit();
    if (array_key_exists('enabled', $data)) {
      $value['enabled'] = (bool)filter_var($data['enabled'], FILTER_VALIDATE_BOOL);
    }
    $errors = [];
    foreach (['requests' => [1, 1_000_000], 'window' => [1, 86_400]] as $key => [$min, $max]) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      $number = filter_var($data[$key], FILTER_VALIDATE_INT);
      if (false === $number || $number < $min || $number > $max) {
        $errors[$key][] = I18n::t('Please enter a number from {min} to {max}.', ['min' => $min, 'max' => $max]);
        continue;
      }
      $value[$key] = $number;
    }
    if ([] !== $errors) {
      throw new ValidationException($errors);
    }
    $this->settings->set(self::RATE_LIMIT, $value);
    return $value;
  }
}
