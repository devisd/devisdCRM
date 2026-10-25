<?php

declare(strict_types=1);

/*
 * Front controller for /api/*.
 *
 * This file is the only PHP file that must be reachable over HTTP. It locates the
 * private API code (outside the public webroot in production) and hands over.
 */

ini_set('display_errors', '0');

$refuse = static function (string $reason): void {
    error_log('devis-crm-api: ' . $reason);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '{"ok":false,"error":{"code":"configuration_error","message":"Service is not configured"}}';
    exit;
};

$apiRootCandidates = [
    // Repository / local layout: api/public/index.php -> api/
    dirname(__DIR__),
    // REG.RU layout: <home>/www/crm.devisd.online/api/index.php -> <home>/devis-crm/api/
    dirname(__DIR__, 3) . '/devis-crm/api',
];

$apiRoot = null;
foreach ($apiRootCandidates as $candidate) {
    if (@is_file($candidate . '/src/bootstrap.php')) {
        $apiRoot = $candidate;
        break;
    }
}

if ($apiRoot === null) {
    $refuse('private API directory not found');
}

// Private code, config/local.php and logs/ live next to each other. If they ended up inside
// the document root (e.g. the whole repository uploaded to the site), refuse to run.
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$realDocumentRoot = is_string($documentRoot) && $documentRoot !== '' ? @realpath($documentRoot) : false;
$realPrivateRoot = @realpath(dirname($apiRoot));
if (
    $realDocumentRoot !== false
    && $realPrivateRoot !== false
    && str_starts_with($realPrivateRoot . DIRECTORY_SEPARATOR, rtrim($realDocumentRoot, '/\\') . DIRECTORY_SEPARATOR)
) {
    $refuse('private API directory is inside the document root');
}

require $apiRoot . '/src/bootstrap.php';

\DevisCrm\Api\run($apiRoot, $_SERVER);
