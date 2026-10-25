<?php

declare(strict_types=1);

namespace DevisCrm\Api\Http;

use DevisCrm\Api\Exceptions\ApiException;

/**
 * Exact-path router. Unknown path -> 404, known path with another method -> 405.
 * HEAD is answered by the GET handler (the body is dropped when sending).
 */
final class Router
{
    /** @var array<string, array<string, callable(Request): JsonResponse>> */
    private array $routes = [];

    /**
     * @param callable(Request): JsonResponse $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param callable(Request): JsonResponse $handler
     */
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes['/' . trim($path, '/')][strtoupper($method)] = $handler;
    }

    public function dispatch(Request $request): JsonResponse
    {
        $handlers = $this->routes[$request->path()] ?? null;
        if ($handlers === null) {
            throw new ApiException(404, 'not_found', 'Resource not found');
        }

        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $handler = $handlers[$method] ?? null;
        if ($handler === null) {
            $allowed = array_keys($handlers);
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            throw new ApiException(405, 'method_not_allowed', 'Method not allowed', ['Allow' => implode(', ', $allowed)]);
        }

        return $handler($request);
    }
}
