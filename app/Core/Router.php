<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, array $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $path = $this->normalizePath($path);
        $method = strtoupper($method);
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || !preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            [$class, $action] = $route['handler'];
            (new $class())->$action(...array_values($params));
            return;
        }

        Response::json(['ok' => false, 'error' => 'Ruta no encontrada'], 404);
    }

    private function normalizePath(string $path): string
    {
        $basePath = rtrim(Env::get('APP_BASE_PATH', '') ?? '', '/');
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        if ($basePath !== '' && substr($basePath, -7) === '/public') {
            $parentBase = substr($basePath, 0, -7);
            if ($parentBase !== '' && strpos($path, $parentBase) === 0) {
                $path = substr($path, strlen($parentBase)) ?: '/';
            }
        }

        if ($path === '/public' || $path === '/public/') {
            return '/';
        }

        if (strpos($path, '/public/') === 0) {
            $path = substr($path, strlen('/public')) ?: '/';
        }

        return $path === '' ? '/' : $path;
    }
}
