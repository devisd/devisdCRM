<?php

declare(strict_types=1);

namespace DevisCrm\Api\Http;

use DevisCrm\Api\Exceptions\ApiException;

/**
 * Minimal immutable view of an HTTP request: method and route path relative to the API base.
 */
final class Request
{
    private const MAX_URI_LENGTH = 2048;

    private function __construct(
        private string $method,
        private string $path,
    ) {
    }

    /**
     * @param array<string, mixed> $server Usually $_SERVER.
     */
    public static function fromServer(array $server, string $basePath): self
    {
        $method = $server['REQUEST_METHOD'] ?? null;
        $uri = $server['REQUEST_URI'] ?? '/';

        if (!is_string($method) || !is_string($uri)) {
            throw self::badRequest();
        }

        return self::create($method, $uri, $basePath);
    }

    public static function create(string $method, string $uri, string $basePath): self
    {
        $method = strtoupper($method);
        if (preg_match('/^[A-Z]{1,16}$/', $method) !== 1) {
            throw self::badRequest();
        }

        return new self($method, self::routePath($uri, $basePath));
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
}
