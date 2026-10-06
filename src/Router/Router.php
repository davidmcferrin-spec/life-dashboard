<?php

declare(strict_types=1);

namespace Dashboard\Router;

/**
 * Router — lightweight front-controller routing.
 *
 * Routes are matched in registration order.
 * Supports GET, POST, and named path parameters like {hash}.
 *
 * Usage:
 *   $router = new Router();
 *   $router->get('/', fn() => require VIEWS . '/dashboard.php');
 *   $router->get('/auto/{token}', fn($p) => handleAutoLogin($p['token']));
 *   $router->dispatch();
 */
class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, params: string[], handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function any(string $pattern, callable $handler): void
    {
        $this->addRoute('ANY', $pattern, $handler);
    }

    private function addRoute(string $method, string $pattern, callable $handler): void
    {
        [$regex, $params] = $this->compilePattern($pattern);

        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'regex'   => $regex,
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * Match current request and invoke handler, or send 404.
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path   = $this->currentPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            // Build named params array
            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name] ?? '';
            }

            ($route['handler'])($params);
            return;
        }

        $this->notFound();
    }

    /**
     * Convert route pattern to regex and extract param names.
     * {param} becomes (?P<param>[^/]+)
     *
     * @return array{0: string, 1: string[]}
     */
    private function compilePattern(string $pattern): array
    {
        $params = [];

        $regex = preg_replace_callback('/\{(\w+)\}/', function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);

        $regex = '#^' . $regex . '$#';

        return [$regex, $params];
    }

    private function currentPath(): string
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        return '/' . trim($path, '/') ?: '/';
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo '404 — Page not found';
    }
}
