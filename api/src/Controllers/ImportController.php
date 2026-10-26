<?php

declare(strict_types=1);

namespace DevisCrm\Api\Controllers;

use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Http\Request;
use DevisCrm\Api\Import\BackupAnalyzer;
use DevisCrm\Api\Import\BackupImporter;

/**
 * Owner-only import of an app backup file (the JSON body is the file as exported);
 * both are POST, so Origin, session and CSRF are checked by the Router:
 *   POST /api/import/preview — validation and money report, writes nothing;
 *   POST /api/import         — validates again and imports everything in one transaction.
 * The file is never stored; only its rows end up in the database.
 */
final class ImportController
{
    public function __construct(private BackupImporter $importer)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        return JsonResponse::success($this->importer->preview($request->jsonBody(BackupAnalyzer::MAX_BODY_BYTES)));
    }

    public function import(Request $request): JsonResponse
    {
        return JsonResponse::success(['import' => $this->importer->import($request->jsonBody(BackupAnalyzer::MAX_BODY_BYTES))], 201);
    }
}
