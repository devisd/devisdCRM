<?php

declare(strict_types=1);

namespace DevisCrm\Api\Http;

/**
 * JSON envelope used by every API response:
 *   success: {"ok": true, "data": {...}}
 *   error:   {"ok": false, "error": {"code": "...", "message": "..."}}
 */
final class JsonResponse
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private const DEFAULT_HEADERS = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function __construct(
        private int $status,
        private array $payload,
        private array $headers,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data, int $status = 200): self
    {
        return new self($status, ['ok' => true, 'data' => $data === [] ? new \stdClass() : $data], []);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, string $message, array $headers = []): self
    {
        return new self($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $headers);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return array_merge($this->headers, self::DEFAULT_HEADERS);
    }

    public function body(): string
    {
        return json_encode($this->payload, self::JSON_FLAGS);
    }

    public function send(bool $includeBody = true): void
    {
        $body = $this->body();

        http_response_code($this->status);
        foreach ($this->headers() as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($includeBody) {
            echo $body;
        }
    }
}
