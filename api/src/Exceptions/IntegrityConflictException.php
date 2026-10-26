<?php

declare(strict_types=1);

namespace DevisCrm\Api\Exceptions;

/**
 * A write violated a database constraint (SQLSTATE 23000): unique key, foreign key.
 * Like DatabaseUnavailableException it carries only SQLSTATE and the driver code.
 */
final class IntegrityConflictException extends \RuntimeException
{
}
