<?php

declare(strict_types=1);

namespace DevisCrm\Api;

use DevisCrm\Api\Http\JsonResponse;
use DevisCrm\Api\Logging\Logger;

/*
 * Shared bootstrap for the front controller and tests.
 * Loading this file never reads configuration and never opens a database connection.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'DevisCrm\\Api\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Handles one HTTP request and always answers with JSON.
 *
 * @param array<string, mixed> $server Usually $_SERVER.
 */
function run(string $apiRoot, array $server): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('zend.exception_ignore_args', '1');
    error_reporting(E_ALL);

    $requestId = bin2hex(random_bytes(8));
    $logger = Logger::forPrivateRoot(dirname($apiRoot));
    $responseSent = false;

    set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    register_shutdown_function(static function () use ($logger, $requestId, &$responseSent): void {
        $error = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($responseSent || $error === null || !in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        $logger->log($requestId, 'internal_error', 'Fatal error: ' . $error['message'], $error['file'] . ':' . $error['line']);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            JsonResponse::error(500, 'internal_error', 'Internal server error')
                ->withHeader('X-Request-Id', $requestId)
                ->send();
        }
    });

    ob_start();
    try {
        $response = Application::create($apiRoot, $logger)->handle($server, $requestId);
        // Encoding can fail on invalid UTF-8; fail here, not halfway through send().
        $response->body();
    } catch (\Throwable $exception) {
        $logger->exception($requestId, 'internal_error', $exception);
        $response = JsonResponse::error(500, 'internal_error', 'Internal server error')
            ->withHeader('X-Request-Id', $requestId);
    }

    // Stray output (notices from extensions, accidental echo) must never reach the client.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $isHead = strtoupper((string) ($server['REQUEST_METHOD'] ?? '')) === 'HEAD';
    $response->send(!$isHead);
    $responseSent = true;
}
