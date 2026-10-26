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

// The private code ($apiRoot) and its parent (config/local.php, logs/) must both be outside the
// document root (e.g. not the whole repository uploaded to the site). If the document root
// cannot be resolved, this cannot be verified, so refuse to run.
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$realDocumentRoot = is_string($documentRoot) && $documentRoot !== '' ? @realpath($documentRoot) : false;
if ($realDocumentRoot === false) {
    $refuse('document root is unknown or cannot be resolved');
}

// Compares whole directory names ("/home/site-private" is not inside "/home/site");
// Windows paths are case-insensitive.
$isWithin = static function (string $path, string $root): bool {
    $normalize = static function (string $value): string {
        $value = rtrim($value, '/\\') . DIRECTORY_SEPARATOR;
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
    };
    return str_starts_with($normalize($path), $normalize($root));
};

foreach ([$apiRoot, dirname($apiRoot)] as $privatePath) {
    $realPrivatePath = @realpath($privatePath);
    if ($realPrivatePath === false) {
        $refuse('private API directory cannot be resolved');
    }
    if ($isWithin($realPrivatePath, $realDocumentRoot)) {
        $refuse('private API directory is inside the document root');
    }
}

require $apiRoot . '/src/bootstrap.php';

\DevisCrm\Api\run($apiRoot, $_SERVER);
