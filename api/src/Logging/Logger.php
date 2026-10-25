<?php

declare(strict_types=1);

namespace DevisCrm\Api\Logging;

/**
 * Appends one JSON line per error to a private log file.
 * Falls back to PHP's error_log() (the hosting error log) when the file is not writable.
 * Never logs stack traces or function arguments, which could contain credentials.
 */
final class Logger
{
    private const MAX_MESSAGE_LENGTH = 1000;

    public function __construct(private ?string $file)
    {
    }

    /** Default location: <private root>/logs/api.log, outside the public webroot. */
    public static function forPrivateRoot(string $privateRoot): self
    {
        return new self($privateRoot . '/logs/api.log');
    }

    public function exception(string $requestId, string $code, \Throwable $exception): void
    {
        $this->log(
            $requestId,
            $code,
            $exception->getMessage(),
            $exception->getFile() . ':' . $exception->getLine(),
            $exception::class,
        );
    }

    public function log(
        string $requestId,
        string $code,
        string $message,
        ?string $location = null,
        ?string $exceptionClass = null,
    ): void {
        $entry = [
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => 'error',
            'request_id' => $requestId,
            'code' => $code,
            'message' => self::sanitize($message),
        ];
        if ($exceptionClass !== null) {
            $entry['exception'] = $exceptionClass;
        }
        if ($location !== null) {
            $entry['location'] = $location;
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($line)) {
            $line = '{"level":"error","code":"log_encoding_failed","request_id":"' . preg_replace('/[^a-f0-9]/', '', $requestId) . '"}';
        }

        if (!$this->appendToFile($line)) {
            error_log('devis-crm-api ' . $line);
        }
    }

    public static function sanitize(string $message): string
    {
        $message = preg_replace('/\b(password|passwd|pwd|secret|token)\b\s*[=:]\s*\S+/i', '$1=***', $message) ?? '[unloggable message]';

        return strlen($message) > self::MAX_MESSAGE_LENGTH ? substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…' : $message;
    }

    private function appendToFile(string $line): bool
    {
        if ($this->file === null) {
            return false;
        }

        $directory = dirname($this->file);
        if (!@is_dir($directory) && !@mkdir($directory, 0750, true) && !@is_dir($directory)) {
            return false;
        }

        return @file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }
}
