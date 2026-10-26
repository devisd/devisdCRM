<?php

declare(strict_types=1);

namespace DevisCrm\Api\Http;

use DevisCrm\Api\Auth\RequestGuard;
use DevisCrm\Api\Exceptions\ApiException;

/**
 * Exact-path router. Unknown path -> 404, known path with another method -> 405.
 * HEAD is answered by the GET handler (the body is dropped when sending).
 *
 * Routes require an authenticated session unless registered as public. Every
 * state-changing request (anything but GET/HEAD) must pass the Origin check; on protected
 * routes it must also carry the session's CSRF token. Handlers receive the Request and the
 * AuthSession (null on public routes).
 */
final class Router
{
    /** @var array<string, array<string, array{handler: callable, public: bool}>> */
    private array $routes = [];

    public function __construct(private ?RequestGuard $guard = null)
    {
    }

    public function get(string $path, callable $handler, bool $public = false): void
    {
        $this->add('GET', $path, $handler, $public);
    }

    public function post(string $path, callable $handler, bool $public = false): void
    {
        $this->add('POST', $path, $handler, $public);
    }

    public function add(string $method, string $path, callable $handler, bool $public = false): void
    {
        $this->routes['/' . trim($path, '/')][strtoupper($method)] = ['handler' => $handler, 'public' => $public];
    }

    public function dispatch(Request $request): JsonResponse
    {
        $routes = $this->routes[$request->path()] ?? null;
        if ($routes === null) {
            throw new ApiException(404, 'not_found', 'Resource not found');
        }

        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $route = $routes[$method] ?? null;
        if ($route === null) {
            $allowed = array_keys($routes);
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            throw new ApiException(405, 'method_not_allowed', 'Method not allowed', ['Allow' => implode(', ', $allowed)]);
        }

        $changesState = $method !== 'GET';
        if (($changesState || !$route['public']) && $this->guard === null) {
            // Fail closed: a route that needs checks never runs without them.
            throw new \LogicException('Route ' . $method . ' ' . $request->path() . ' requires a request guard');
        }

        if ($changesState) {
            $this->guard->requireSameOrigin($request);
        }
        if ($route['public']) {
            return ($route['handler'])($request, null);
        }

        $session = $this->guard->requireSession($request);
        if ($changesState) {
            $this->guard->requireCsrf($request, $session);
        }

        return ($route['handler'])($request, $session);
    }
}
