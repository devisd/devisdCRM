<?php

declare(strict_types=1);

namespace DevisCrm\Api\Http;

use DevisCrm\Api\Auth\RequestGuard;
use DevisCrm\Api\Exceptions\ApiException;

/**
 * Path router. Unknown path -> 404, known path with another method -> 405.
 * HEAD is answered by the GET handler (the body is dropped when sending).
 *
 * Paths match exactly, except that a whole segment written as "{name}" in a route matches
 * any one non-empty segment. Exact routes are tried first and are kept apart from patterns,
 * so a request for the literal text "/clients/{id}" never selects a pattern route.
 *
 * Routes require an authenticated session unless registered as public. Every
 * state-changing request (anything but GET/HEAD) must pass the Origin check; on protected
 * routes it must also carry the session's CSRF token. Handlers receive the Request, the
 * AuthSession (null on public routes) and the path parameters (name => raw segment).
 */
final class Router
{
    /** @var array<string, array<string, array{handler: callable, public: bool}>> */
    private array $routes = [];

    /** @var array<string, array{regex: string, methods: array<string, array{handler: callable, public: bool}>}> */
    private array $patterns = [];

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

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler, bool $public = false): void
    {
        $path = '/' . trim($path, '/');
        $route = ['handler' => $handler, 'public' => $public];
        if (!str_contains($path, '{')) {
            $this->routes[$path][strtoupper($method)] = $route;

            return;
        }

        $this->patterns[$path] ??= ['regex' => self::compile($path), 'methods' => []];
        $this->patterns[$path]['methods'][strtoupper($method)] = $route;
    }

    public function dispatch(Request $request): JsonResponse
    {
        [$routes, $params] = $this->match($request->path());
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
            return ($route['handler'])($request, null, $params);
        }

        $session = $this->guard->requireSession($request);
        if ($changesState) {
            $this->guard->requireCsrf($request, $session);
        }

        return ($route['handler'])($request, $session, $params);
    }

    /**
     * @return array{0: array<string, array{handler: callable, public: bool}>|null, 1: array<string, string>}
     */
    private function match(string $path): array
    {
        if (isset($this->routes[$path])) {
            return [$this->routes[$path], []];
        }
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern['regex'], $path, $matches) === 1) {
                return [$pattern['methods'], array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }

        return [null, []];
    }

    private static function compile(string $path): string
    {
        $parts = [];
        foreach (explode('/', substr($path, 1)) as $segment) {
            if (preg_match('/^\{([a-z][A-Za-z0-9]*)\}$/', $segment, $name) === 1) {
                $parts[] = '(?P<' . $name[1] . '>[^/]+)';
            } elseif (!str_contains($segment, '{') && !str_contains($segment, '}')) {
                $parts[] = preg_quote($segment, '#');
            } else {
                throw new \LogicException('Invalid route pattern ' . $path);
            }
        }

        return '#\A/' . implode('/', $parts) . '\z#u';
    }
}
