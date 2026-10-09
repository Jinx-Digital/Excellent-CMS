<?php

declare(strict_types=1);

namespace App\Application\Access;

use Yiisoft\Rbac\Item;
use Yiisoft\Rbac\RuleContext;
use Yiisoft\Rbac\RuleInterface;

/**
 * Rule of the permissions entity.<id>.update_own / delete_own: only records the subject created
 * itself ("created_by" of the record is "user:<id>" or "client:<id>", the subject id of AccessControl).
 *
 *   $manager->userHasPermission('user:abc', 'entity.<id>.update_own', ['created_by' => $row['created_by']])
 */
final class OwnRecordRule implements RuleInterface
{
  public function execute(?string $userId, Item $item, RuleContext $context): bool
  {
    return null !== $userId && $context->getParameterValue('created_by') === $userId;
  }

  /**
   * The check AccessControl makes without asking the manager (permissions are cached per request).
   */
  public static function owns(string $subject, array $row): bool
  {
    return ($row['created_by'] ?? null) === $subject;
  }
}
