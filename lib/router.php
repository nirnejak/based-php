<?php

declare(strict_types=1);

defined('BASED') || exit;

final class Router
{
    /** @var array<string,array<int,array{regex:string,keys:string[],handler:callable,middleware:array}>> */
    private static array $routes = [];

    /** @return array{0:string,1:string[]} */
    public static function compile(string $pattern): array
    {
        $keys = [];
        $regex = '';

        foreach (explode('/', trim($pattern, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment[0] === ':') {
                $keys[] = substr($segment, 1);
                $regex .= '/([^/]+)';
            } else {
                $regex .= '/' . preg_quote($segment, '#');
            }
        }

        if ($regex === '') {
            $regex = '/';
        }

        // The D modifier makes `$` match only the absolute end, so a trailing
        // control byte (a decoded %0A that survived normalization) can't let
        // "/health\n" match the "/health" route.
        return ['#^' . $regex . '$#D', $keys];
    }

    public static function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        [$regex, $keys] = self::compile($path);

        self::$routes[strtoupper($method)][] = [
            'regex' => $regex,
            'keys' => $keys,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public static function get(string $path, callable $handler, array $middleware = []): void
    {
        self::add('GET', $path, $handler, $middleware);
    }

    public static function post(string $path, callable $handler, array $middleware = []): void
    {
        self::add('POST', $path, $handler, $middleware);
    }

    public static function put(string $path, callable $handler, array $middleware = []): void
    {
        self::add('PUT', $path, $handler, $middleware);
    }

    public static function patch(string $path, callable $handler, array $middleware = []): void
    {
        self::add('PATCH', $path, $handler, $middleware);
    }

    public static function delete(string $path, callable $handler, array $middleware = []): void
    {
        self::add('DELETE', $path, $handler, $middleware);
    }

    public static function match(string $method, string $path): ?array
    {
        foreach (self::$routes[strtoupper($method)] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $m) === 1) {
                $params = [];

                foreach ($route['keys'] as $i => $key) {
                    $params[$key] = $m[$i + 1];
                }

                return [
                    'handler' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /** @return string[] */
    public static function allowedMethods(string $path): array
    {
        $allowed = [];

        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    public static function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $route = self::match($method, $path);

        if ($route === null) {
            $allowed = self::allowedMethods($path);

            if ($allowed !== []) {
                header('Allow: ' . implode(', ', $allowed));
                Response::error('Method not allowed', 405);
            }

            Response::error('Not found', 404);
        }

        // FAIL CLOSED. Middleware must return true to continue.
        // `=== false` would be fail-open: a void middleware returns null,
        // and null === false is false, so the short-circuit would never trip.
        foreach ($route['middleware'] as $middleware) {
            if ($middleware($route['params']) !== true) {
                return;
            }
        }

        ($route['handler'])($route['params']);
    }

    public static function reset(): void
    {
        self::$routes = [];
    }
}
