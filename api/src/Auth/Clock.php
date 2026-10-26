<?php

declare(strict_types=1);

namespace DevisCrm\Api\Auth;

interface Clock
{
    /** Current time in UTC. */
    public function now(): \DateTimeImmutable;
}
