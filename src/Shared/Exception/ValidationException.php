<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use App\Shared\I18n;
use Yiisoft\Http\Status;

/**
 * Field-level input errors, e.g. ['username' => ['This user name is taken.']].
 */
final class ValidationException extends UserFacingException
{
  /**
   * @param array<string, string[]> $errors
   */
  public function __construct(
    private array $errors,
    ?string $message = null,
  ) {
    parent::__construct($message ?? I18n::t('Please check the marked fields.'), Status::UNPROCESSABLE_ENTITY, 'validation');
  }

  public static function field(string $field, string $message): self
  {
    return new self([$field => [$message]]);
  }

  /**
   * @return array<string, string[]>
   */
  public function getErrors(): array
  {
    return $this->errors;
  }
}
