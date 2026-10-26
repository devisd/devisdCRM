<?php

declare(strict_types=1);

namespace DevisCrm\Api\Http;

use DevisCrm\Api\Exceptions\ApiException;

/**
 * Immutable view of an HTTP request: method, route path relative to the API base, headers,
 * cookies, client address and a lazily read, size-limited JSON body.
 */
final class Request
{
    private const MAX_URI_LENGTH = 2048;
    private const MAX_HEADER_LENGTH = 8192;

    /** Upper limit for JSON request bodies. */
    public const MAX_BODY_BYTES = 16384;

    /** @var \Closure(int): string */
    private \Closure $bodyReader;

    /**
     * @param array<string, string> $headers Lower-case names.
     * @param callable(int): string $bodyReader Returns at most the given number of bytes.
     */
    private function __construct(
        private string $method,
        private string $path,
        private array $headers,
        private string $remoteAddress,
        callable $bodyReader,
    ) {
        $this->bodyReader = \Closure::fromCallable($bodyReader);
    }

    /**
     * @param array<string, mixed> $server Usually $_SERVER.
     * @param (callable(int): string)|null $bodyReader Defaults to reading php://input.
     */
    public static function fromServer(array $server, string $basePath, ?callable $bodyReader = null): self
    {
        $method = $server['REQUEST_METHOD'] ?? null;
        $uri = $server['REQUEST_URI'] ?? '/';

        if (!is_string($method) || !is_string($uri)) {
            throw self::badRequest();
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = $key;
            } else {
                continue;
            }
            $headers[strtolower(str_replace('_', '-', $name))] = $value;
        }

        $remote = $server['REMOTE_ADDR'] ?? '';
        $bodyReader ??= static fn (int $maxBytes): string => (string) @file_get_contents('php://input', false, null, 0, $maxBytes);

        return self::create($method, $uri, $basePath, $headers, is_string($remote) ? $remote : '', $bodyReader);
    }

    /**
     * @param array<string, string> $headers Header names are matched case-insensitively.
     * @param (callable(int): string)|null $bodyReader Defaults to an empty body.
     */
    public static function create(
        string $method,
        string $uri,
        string $basePath,
        array $headers = [],
        string $remoteAddress = '',
        ?callable $bodyReader = null,
    ): self {
        $method = strtoupper($method);
        if (preg_match('/^[A-Z]{1,16}$/', $method) !== 1) {
            throw self::badRequest();
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (strlen($value) > self::MAX_HEADER_LENGTH) {
                throw self::badRequest();
            }
            $normalized[strtolower((string) $name)] = $value;
        }

        $address = filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false ? $remoteAddress : '';

        return new self($method, self::routePath($uri, $basePath), $normalized, $address, $bodyReader ?? (static fn (int $maxBytes): string => ''));
    }

    public function method(): string
    {
        return $this->method;
    }

    /** Route path without the API base, e.g. "/health". Never has a trailing slash. */
    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** First cookie with this exact name from the Cookie header, or null. */
    public function cookie(string $name): ?string
    {
        $header = $this->header('cookie');
        if ($header === null) {
            return null;
        }
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && trim($parts[0]) === $name) {
                return trim($parts[1]);
            }
        }

        return null;
    }

    /**
     * Client address as reported by the web server (REMOTE_ADDR); '' when absent or invalid.
     * Forwarding headers (X-Forwarded-For etc.) are deliberately ignored.
     */
    public function remoteAddress(): string
    {
        return $this->remoteAddress;
    }

    /**
     * Decoded JSON object body.
     *
     * @return array<string, mixed>
     */
    public function jsonBody(): array
    {
        $contentType = strtolower(trim(explode(';', (string) $this->header('content-type'))[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException(415, 'unsupported_media_type', 'Content-Type must be application/json');
        }

        $declared = $this->header('content-length');
        if ($declared !== null && (preg_match('/^\d{1,10}$/', $declared) !== 1 || (int) $declared > self::MAX_BODY_BYTES)) {
            throw self::tooLarge();
        }

        $raw = ($this->bodyReader)(self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw self::tooLarge();
        }
        if (trim($raw) === '') {
            throw new ApiException(400, 'invalid_json', 'Request body must be a JSON object');
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ApiException(400, 'invalid_json', 'Request body must be a JSON object');
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ApiException(400, 'invalid_json', 'Request body must be a JSON object');
        }

        return $decoded;
    }

    private static function routePath(string $uri, string $basePath): string
    {
        if ($uri === '' || strlen($uri) > self::MAX_URI_LENGTH || $uri[0] !== '/' || str_starts_with($uri, '//')) {
            throw self::badRequest();
        }

        $path = parse_url('http://localhost' . $uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            throw self::badRequest();
        }

        $path = rawurldecode($path);
        if (str_contains($path, "\0") || preg_match('//u', $path) !== 1) {
            throw self::badRequest();
        }

        $basePath = rtrim($basePath, '/');
        if ($basePath !== '') {
            // "/api/index.php/health" also works when URL rewriting is unavailable.
            foreach ([$basePath . '/index.php', $basePath] as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    $path = substr($path, strlen($prefix));
                    break;
                }
            }
        }

        return '/' . trim($path, '/');
    }

    private static function badRequest(): ApiException
    {
        return new ApiException(400, 'bad_request', 'Malformed request');
    }

    private static function tooLarge(): ApiException
    {
        return new ApiException(413, 'payload_too_large', 'Request body is too large');
    }
}
