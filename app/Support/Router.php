<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal exact-match router. Patterns support {name} segments matched
 * by the given regular-expression fragment.
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:callable}> */
    private array $routes = [];

    /**
     * Patterns support {name} (matched by [^/]+) or {name:regex} for a custom
     * per-segment constraint, e.g. /q/{public_id:[0-9a-f]{32}}/{slug}.
     */
    public function add(string $method, string $pattern, callable $handler, string $segmentRegex = '[^/]+'): void
    {
        $regex = '#^' . preg_replace_callback(
            '/\{(\w+)(?::((?:[^{}]|\{\d+(?:,\d+)?\})+))?\}/',
            fn (array $m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern
        ) . '$#u';
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'regex' => $regex, 'handler' => $handler];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function dispatch(string $method, string $path): array
    {
        $pathMatches = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            $pathMatches = true;
            if ($route['method'] === $method) {
                $params = [];
                foreach ($m as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }
        if ($pathMatches) {
            $allowed = [];
            foreach ($this->routes as $route) {
                if (preg_match($route['regex'], $path) === 1 && !in_array($route['method'], $allowed, true)) {
                    $allowed[] = $route['method'];
                }
            }
            return ['method_not_allowed' => true, 'allow' => implode(', ', $allowed)];
        }
        return ['not_found' => true];
    }
}
