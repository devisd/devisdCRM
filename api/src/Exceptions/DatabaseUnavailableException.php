<?php

declare(strict_types=1);

namespace DevisCrm\Api\Exceptions;

/**
 * Database cannot be reached or answered unexpectedly. The message is for the server log only.
 */
final class DatabaseUnavailableException extends \RuntimeException
{
}
