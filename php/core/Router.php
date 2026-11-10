<?php
declare(strict_types=1);

namespace Tatap\Core;

class Router
{
    /** @var array<array{method: string, pattern: string, regex: string, paramNames: string[], handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '([^/]+)';
        }, $pattern);

        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'paramNames' => $paramNames,
            'handler' => $handler,
        ];
    }

    public function resolve(string $method, string $uri): ?array
    {
        $method = strtoupper($method);
        // Strip query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = '/' . trim($uri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            if (preg_match($route['regex'], $uri, $matches)) {
                array_shift($matches); // Remove full match
                $params = [];
                foreach ($route['paramNames'] as $idx => $name) {
                    $params[$name] = isset($matches[$idx]) ? urldecode($matches[$idx]) : '';
                }

                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    public function dispatch(string $method, string $uri): void
    {
        if (strtoupper($method) === 'OPTIONS') {
            Response::corsHeaders();
            http_response_code(204);
            exit;
        }

        $match = $this->resolve($method, $uri);
        if ($match === null) {
            Response::error('Not Found', 404);
            return;
        }

        try {
            $res = call_user_func($match['handler'], $match['params']);
            if (is_array($res) || is_object($res)) {
                Response::json($res);
            } elseif (is_string($res)) {
                echo $res;
            }
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
