<?php

declare(strict_types=1);

namespace Locally\Http;

/**
 * Exact routes plus optional single-segment slug routes (GET prefix/{slug}).
 */
final class Router
{
    /** @var array<string, callable(Request): Response> */
    private array $routes = [];

    /** @var list<array{method:string, prefix:string, handler:callable(Request, string): Response}> */
    private array $slugRoutes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST ' . $path] = $handler;
    }

    public function delete(string $path, callable $handler): void
    {
        $this->routes['DELETE ' . $path] = $handler;
    }

    public function put(string $path, callable $handler): void
    {
        $this->routes['PUT ' . $path] = $handler;
    }

    public function patch(string $path, callable $handler): void
    {
        $this->routes['PATCH ' . $path] = $handler;
    }

    /**
     * Registers GET {prefix}/{slug} where slug is a single path segment (no slashes).
     */
    public function getSlug(string $prefix, callable $handler): void
    {
        $this->registerSlugRoute('GET', $prefix, $handler);
    }

    /**
     * Registers PATCH {prefix}/{segment} for a single path segment (e.g. numeric id).
     */
    public function patchSlug(string $prefix, callable $handler): void
    {
        $this->registerSlugRoute('PATCH', $prefix, $handler);
    }

    /**
     * @param callable(Request, string): Response $handler
     */
    private function registerSlugRoute(string $method, string $prefix, callable $handler): void
    {
        $this->slugRoutes[] = [
            'method' => $method,
            'prefix' => rtrim($prefix, '/'),
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): ?Response
    {
        $key = $request->method . ' ' . $request->path;
        $handler = $this->routes[$key] ?? null;
        if ($handler !== null) {
            return $handler($request);
        }

        foreach ($this->slugRoutes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $prefix = $route['prefix'];
            if (!str_starts_with($request->path, $prefix . '/')) {
                continue;
            }
            $slug = substr($request->path, strlen($prefix) + 1);
            if ($slug === '' || str_contains($slug, '/')) {
                continue;
            }

            return $route['handler']($request, rawurldecode($slug));
        }

        return null;
    }
}
