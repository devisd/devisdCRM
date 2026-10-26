<?php

declare(strict_types=1);

namespace DevisCrm\Api\Exceptions;

/**
 * Expected client-facing error. Its message is safe to return in the JSON response.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $status,
        private string $errorCode,
        string $publicMessage,
        private array $headers = [],
    ) {
        parent::__construct($publicMessage);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
