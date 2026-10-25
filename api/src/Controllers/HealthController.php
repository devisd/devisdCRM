<?php

declare(strict_types=1);

namespace DevisCrm\Api\Controllers;

use DevisCrm\Api\DatabaseProbe;
use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Http\Request;

/**
 * GET /api/health — confirms that PHP runs and the database answers SELECT 1.
 * Configuration and database failures are mapped to 503 by Application.
 */
final class HealthController
{
    public function __construct(private DatabaseProbe $database)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->database->ping();

        return JsonResponse::success(['status' => 'ok', 'db' => true]);
    }
}
