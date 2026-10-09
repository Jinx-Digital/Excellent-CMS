<?php

declare(strict_types=1);

namespace App\Domain\Schema;

use InvalidArgumentException;

/**
 * A value does not fit the type of its field; the message is shown to the user as is.
 */
final class InvalidValueException extends InvalidArgumentException
{
}
