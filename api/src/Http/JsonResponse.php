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

    /** @var list<array{0: string, 1: string}> Header lines in order; names may repeat (Set-Cookie). */
    private array $headerLines = [];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function __construct(
        private int $status,
        private array $payload,
        array $headers,
    ) {
        foreach ($headers as $name => $value) {
            $this->headerLines = self::append($this->headerLines, (string) $name, $value);
        }
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

    /** Sets a header, replacing earlier values with the same name. */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headerLines = array_values(array_filter(
            $clone->headerLines,
            static fn (array $line): bool => strcasecmp($line[0], $name) !== 0,
        ));
        $clone->headerLines = self::append($clone->headerLines, $name, $value);

        return $clone;
    }

    /** Adds another header line with this name (e.g. a second Set-Cookie). */
    public function withAddedHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headerLines = self::append($clone->headerLines, $name, $value);

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
     * Last value per header name; see headerLines() for repeated headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->headerLines() as [$name, $value]) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * All header lines in sending order, including repeated names and the fixed defaults.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function headerLines(): array
    {
        $lines = $this->headerLines;
        foreach (self::DEFAULT_HEADERS as $name => $value) {
            $lines[] = [$name, $value];
        }

        return $lines;
    }

    public function body(): string
    {
        return json_encode($this->payload, self::JSON_FLAGS);
    }

    /**
     * @param (callable(string, bool): void)|null $emitHeader Defaults to header(); replaced in tests.
     */
    public function send(bool $includeBody = true, ?callable $emitHeader = null): void
    {
        $body = $this->body();
        $emitHeader ??= static function (string $line, bool $replace): void {
            header($line, $replace);
        };

        http_response_code($this->status);
        $seen = [];
        foreach ($this->headerLines() as [$name, $value]) {
            $key = strtolower($name);
            // The first line of a name replaces PHP defaults; later ones are added, not lost.
            $emitHeader($name . ': ' . $value, !isset($seen[$key]));
            $seen[$key] = true;
        }

        if ($includeBody) {
            echo $body;
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $lines
     * @return list<array{0: string, 1: string}>
     */
    private static function append(array $lines, string $name, string $value): array
    {
        foreach (array_keys(self::DEFAULT_HEADERS) as $fixed) {
            if (strcasecmp($fixed, $name) === 0) {
                return $lines;
            }
        }
        if (preg_match('/[\r\n]/', $name . $value) === 1) {
            throw new \InvalidArgumentException('Header names and values must not contain line breaks');
        }
        $lines[] = [$name, $value];

        return $lines;
    }
}
