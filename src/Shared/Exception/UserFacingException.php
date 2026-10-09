<?php

declare(strict_types=1);

namespace App\Shared\Exception;

use App\Shared\I18n;
use RuntimeException;
use Yiisoft\Http\Status;

/**
 * An error whose message is safe and meant to be shown to the workshop staff as is.
 * Services throw it for expected failures ("Benutzername ist schon vergeben"); anything
 * else that bubbles up is treated as a technical error, logged, and replaced by a generic text.
 */
class UserFacingException extends RuntimeException
{
  public function __construct(
    string $message,
    private int $httpStatus = Status::BAD_REQUEST,
    private ?string $errorCode = null,
  ) {
    parent::__construct($message);
  }

  public static function notFound(?string $message = null): self
  {
    return new self($message ?? I18n::t('This was not found. Maybe it was deleted.'), Status::NOT_FOUND, 'not_found');
  }

  public static function conflict(string $message, ?string $errorCode = null): self
  {
    return new self($message, Status::CONFLICT, $errorCode);
  }

  public static function forbidden(?string $message = null): self
  {
    return new self($message ?? I18n::t('You do not have permission for this.'), Status::FORBIDDEN, 'forbidden');
  }

  public function getHttpStatus(): int
  {
    return $this->httpStatus;
  }

  public function getErrorCode(): ?string
  {
    return $this->errorCode;
  }
}
