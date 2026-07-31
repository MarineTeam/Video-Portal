<?php

declare(strict_types=1);

namespace Portal\Http;

use Portal\Container;
use Throwable;

/**
 * Route table and dispatcher.
 *
 * Routes are registered by core first, then by plugins on the
 * `routes_register` hook. Registration order is match order, so a plugin
 * cannot silently shadow a core route it registered later — but it *can*
 * deliberately register a more specific pattern, which is the intended
 * extension point.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, callable(Request, array<string,string>): (Response|null)> */
    private array $middleware = [];

    /** @var list<string> Middleware applied to every route. */
    private array $globalMiddleware = [];

    /** @var array<string, Route> */
    private array $named = [];

    /**
     * @param list<string> $middleware
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function add(
        string|array $methods,
        string $pattern,
        mixed $handler,
        array $middleware = [],
        ?string $name = null
    ): Route {
        $methods = array_map('strtoupper', (array) $methods);

        // A GET route always answers HEAD; the response body is discarded by
        // the client and by us.
        if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
            $methods[] = 'HEAD';
        }

        $route = new Route($methods, '/' . trim($pattern, '/'), $handler, $middleware, $name);
        $this->routes[] = $route;

        if ($name !== null) {
            $this->named[$name] = $route;
        }

        return $route;
    }

    public function get(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): Route
    {
        return $this->add('GET', $pattern, $handler, $middleware, $name);
    }

    public function post(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): Route
    {
        return $this->add('POST', $pattern, $handler, $middleware, $name);
    }

    public function any(array $methods, string $pattern, mixed $handler, array $middleware = [], ?string $name = null): Route
    {
        return $this->add($methods, $pattern, $handler, $middleware, $name);
    }

    /**
     * Register a named middleware.
     *
     * A middleware returns null to continue, or a Response to short-circuit.
     * That shape — rather than the onion/next() style — keeps guards trivially
     * readable: `return $user ? null : Response::error('Login required', 401);`
     *
     * @param callable(Request, array<string,string>): (Response|null) $handler
     */
    public function middleware(string $name, callable $handler): void
    {
        $this->middleware[$name] = $handler;
    }

    /** @param list<string> $names */
    public function globalMiddleware(array $names): void
    {
        $this->globalMiddleware = $names;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(Request $request): Response
    {
        $matched = null;
        $params = [];
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $candidate = $route->match($request->path);
            if ($candidate === null) {
                continue;
            }
            if ($route->acceptsMethod($request->method)) {
                $matched = $route;
                $params = $candidate;
                break;
            }
            // Path matched but method didn't — remember it so we can answer
            // 405 with a correct Allow header instead of a misleading 404.
            foreach ($route->methods as $method) {
                $allowedMethods[$method] = true;
            }
        }

        if ($matched === null) {
            if ($allowedMethods !== []) {
                return Response::methodNotAllowed(array_keys($allowedMethods));
            }
            return $this->notFound($request);
        }

        $chain = [...$this->globalMiddleware, ...$matched->middleware];

        foreach ($chain as $name) {
            if (!isset($this->middleware[$name])) {
                // A route asking for middleware that doesn't exist is a bug,
                // and the safe reading of "unknown guard" is "denied".
                error_log("Portal: route {$matched->pattern} requires unknown middleware '{$name}'");
                return $this->serverError($request, "Route is misconfigured.");
            }

            $result = ($this->middleware[$name])($request, $params);
            if ($result instanceof Response) {
                return $result;
            }
        }

        try {
            $response = $this->invoke($matched->handler, $request, $params);
        } catch (HttpException $e) {
            return $e->toResponse($request);
        }

        if ($response instanceof Response) {
            return $response;
        }
        if (is_string($response)) {
            return Response::html($response);
        }
        if (is_array($response)) {
            return Response::json($response);
        }
        if ($response === null) {
            return Response::noContent();
        }

        return $this->serverError($request, 'Handler returned something unusable.');
    }

    /** @param array<string, string> $params */
    private function invoke(mixed $handler, Request $request, array $params): mixed
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            [$class, $method] = $handler;
            $container = Container::instance();
            $instance = $container->has($class) ? $container->get($class) : new $class();
            return $instance->{$method}($request, $params);
        }

        if (is_callable($handler)) {
            return $handler($request, $params);
        }

        throw new \RuntimeException('Route handler is not callable.');
    }

    private function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::error('Not found', 404);
        }
        return Response::html(ErrorPage::render(404, 'Page not found',
            'The page you were looking for is not here.'), 404);
    }

    private function serverError(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::error($message, 500);
        }
        return Response::html(ErrorPage::render(500, 'Something went wrong', $message), 500);
    }
}
