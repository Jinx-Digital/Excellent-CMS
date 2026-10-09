<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use App\Shared\I18n;

/**
 * Who may read an entity through the content API.
 */
enum Access: string
{
  /** Everybody, without authentication */
  case Public = 'public';
  /** Only OAuth clients that were given access to the entity */
  case OAuth = 'oauth';

  public function label(): string
  {
    return match ($this) {
      self::Public => I18n::t('Public'),
      self::OAuth => I18n::t('OAuth token only'),
    };
  }
}
