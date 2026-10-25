<?php

declare(strict_types=1);

namespace DevisCrm\Api;

interface DatabaseProbe
{
    /**
     * Read-only connectivity check.
     *
     * @throws Exceptions\ConfigException
     * @throws Exceptions\DatabaseUnavailableException
     */
    public function ping(): void;
}
