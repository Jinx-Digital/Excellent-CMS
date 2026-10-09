<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Shared\I18n;

/**
 * What a user may do with the records of one entity. Admins may always do everything.
 */
enum EntityPermission: string
{
  case Read = 'read';
  case Create = 'create';
  case Update = 'update';
  case Delete = 'delete';
  /** Upload CSV/Excel files into the entity (creates and updates records) */
  case Import = 'import';
  /** Edit / delete only the records one created oneself (RBAC rule OwnRecordRule) */
  case UpdateOwn = 'update_own';
  case DeleteOwn = 'delete_own';

  /**
   * The "only own records" variant of update and delete.
   */
  public function own(): ?self
  {
    return match ($this) {
      self::Update => self::UpdateOwn,
      self::Delete => self::DeleteOwn,
      default => null,
    };
  }

  public function column(): string
  {
    return 'can_'.$this->value;
  }

  public function label(): string
  {
    return match ($this) {
      self::Read => I18n::t('Read'),
      self::Create => I18n::t('Create'),
      self::Update => I18n::t('Edit'),
      self::Delete => I18n::t('Delete'),
      self::Import => I18n::t('Import'),
      self::UpdateOwn => I18n::t('Edit own'),
      self::DeleteOwn => I18n::t('Delete own'),
    };
  }

  /**
   * @return array<string, bool> all permissions set to $value
   */
  public static function all(bool $value): array
  {
    $result = [];
    foreach (self::cases() as $case) {
      $result[$case->value] = $value;
    }
    return $result;
  }
}
