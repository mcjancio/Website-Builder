<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Prosty router: wzorce z parametrami {id} lub {id:\d+}, grupy z prefiksem i middleware.
 * Handler: [Klasa::class, 'metoda'] albo Closure(Request): Response.
 * Middleware: callable(Request $r, callable $next): Response.
 */
final class Router
{
    private array $routes = [];
    private array $groupStack = [];
    /** Middleware wykonywane dla każdego żądania (także 404). */
    private array $global = [];

    public function get(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable|array $handler, array $middleware = []): void
    {
        $this->add(['POST'], $pattern, $handler, $middleware);
    }

    public function add(array $methods, string $pattern, callable|array $handler, array $middleware = []): void
    {
        $prefix = '';
        $groupMw = [];
        foreach ($this->groupStack as $g) {
            $prefix .= $g['prefix'];
            $groupMw = [...$groupMw, ...$g['middleware']];
        }
        $full = Request::normalizePath($prefix . '/' . ltrim($pattern, '/'));
        $this->routes[] = [
            'methods' => $methods,
            'pattern' => $full,
            'regex' => self::compile($full),
            'handler' => $handler,
            'middleware' => [...$groupMw, ...$middleware],
        ];
    }

    public function group(string $prefix, array $middleware, callable $define): void
    {
        $this->groupStack[] = ['prefix' => '/' . trim($prefix, '/'), 'middleware' => $middleware];
        $define($this);
        array_pop($this->groupStack);
    }

    public function middleware(callable $mw): void
    {
        $this->global[] = $mw;
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        $match = null;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if (!in_array($request->method, $route['methods'], true)) {
                $allowed = [...$allowed, ...$route['methods']];
                continue;
            }
            $match = $route;
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            break;
        }

        if ($match === null) {
            $final = $allowed !== []
                ? static fn() => Response::view('errors/405', [], 405)->withHeader('Allow', implode(', ', array_unique($allowed)))
                : static fn() => Response::view('errors/404', [], 404);
            return $this->pipeline($this->global, $final)($request);
        }

        $handler = $match['handler'];
        $final = static function (Request $r) use ($handler): Response {
            if (is_array($handler)) {
                [$class, $method] = $handler;
                $handler = [new $class(), $method];
            }
            $result = $handler($r);
            if (!$result instanceof Response) {
                throw new \LogicException('Kontroler musi zwrócić obiekt Response.');
            }
            return $result;
        };

        return $this->pipeline([...$this->global, ...$match['middleware']], $final)($request);
    }

    private function pipeline(array $middleware, callable $final): callable
    {
        $next = $final;
        foreach (array_reverse($middleware) as $mw) {
            $current = $next;
            $mwCallable = is_string($mw) ? new $mw() : $mw;
            $next = static fn(Request $r): Response => $mwCallable($r, $current);
        }
        return $next;
    }

    public static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#\{([a-z_][a-z0-9_]*)(?::([^}]+))?\}#i',
            static fn($m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $pattern
        );
        return '#^' . $regex . '$#u';
    }

    public function routes(): array
    {
        return $this->routes;
    }
}
