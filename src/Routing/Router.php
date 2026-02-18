<?php

declare(strict_types=1);

namespace Lovante\Routing;

use Lovante\Http\Request;
use Lovante\Http\Response;
use Exception;

/**
 * Lovante Router
 *
 * Ultra-fast HTTP router with:
 *  - Static route fast-path (O(1) hash lookup, no regex)
 *  - Dynamic route regex matching (compiled & cached per Route)
 *  - Route groups with prefix / middleware / namespace stacking
 *  - Named routes + URL generation
 *  - Method-not-allowed detection (405)
 *  - Custom 404 / 405 handlers
 */
class Router
{
    /**
     * Static routes: ['METHOD:path' => Route]
     * Hash lookup — no regex needed at all for static paths.
     */
    protected array $staticRoutes = [];

    /**
     * Dynamic routes (contain {params}): ['METHOD' => [Route, ...]]
     */
    protected array $dynamicRoutes = [];

    /**
     * Named routes map: ['name' => Route]
     */
    protected array $namedRoutes = [];

    /**
     * Group stack (pushed/popped during group() calls)
     *
     * @var RouteGroup[]
     */
    protected array $groupStack = [];

    /**
     * Global middleware applied to every route
     */
    protected array $globalMiddleware = [];

    /**
     * Custom handler for 404 Not Found
     */
    protected mixed $notFoundHandler = null;

    /**
     * Custom handler for 405 Method Not Allowed
     */
    protected mixed $methodNotAllowedHandler = null;

    // =========================================================================
    // Route Registration
    // =========================================================================

    public function get(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $handler);
    }

    public function post(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['POST'], $uri, $handler);
    }

    public function put(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['PUT'], $uri, $handler);
    }

    public function patch(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['PATCH'], $uri, $handler);
    }

    public function delete(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['DELETE'], $uri, $handler);
    }

    public function options(string $uri, mixed $handler): Route
    {
        return $this->addRoute(['OPTIONS'], $uri, $handler);
    }

    /**
     * Register a route that responds to any HTTP method
     */
    public function any(string $uri, mixed $handler): Route
    {
        return $this->addRoute(
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            $uri,
            $handler
        );
    }

    /**
     * Register a route for multiple specific methods
     */
    public function match(array $methods, string $uri, mixed $handler): Route
    {
        return $this->addRoute($methods, $uri, $handler);
    }

    // =========================================================================
    // Route Groups
    // =========================================================================

    /**
     * Create a route group with shared attributes.
     *
     * Supported attributes:
     *   'prefix'     => '/admin'
     *   'middleware' => ['auth', 'throttle']
     *   'namespace'  => 'App\\Controllers\\Admin'
     *   'name'       => 'admin.'
     */
    public function group(array $attributes, callable $callback): void
    {
        $parent = $this->currentGroup();
        $group  = $parent ? $parent->merge($attributes) : new RouteGroup(
            prefix:     '/' . trim($attributes['prefix'] ?? '', '/'),
            middleware: (array) ($attributes['middleware'] ?? []),
            namespace:  $attributes['namespace'] ?? '',
            name:       $attributes['name'] ?? null,
        );

        $this->groupStack[] = $group;
        $callback($this);
        array_pop($this->groupStack);
    }

    // =========================================================================
    // Global Middleware
    // =========================================================================

    public function addMiddleware(string $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    // =========================================================================
    // Custom Error Handlers
    // =========================================================================

    public function notFound(callable $handler): void
    {
        $this->notFoundHandler = $handler;
    }

    public function methodNotAllowed(callable $handler): void
    {
        $this->methodNotAllowedHandler = $handler;
    }

    // =========================================================================
    // Dispatching
    // =========================================================================

    /**
     * Dispatch a Request and return a Response.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path   = $request->path();

        // ------------------------------------------------------------------
        // 1. Static fast-path: O(1) hash lookup
        // ------------------------------------------------------------------
        $staticKey = $method . ':' . $path;

        if (isset($this->staticRoutes[$staticKey])) {
            $route = $this->staticRoutes[$staticKey];
            $request->setRouteParams([]);
            return $this->runRoute($route, $request);
        }

        // HEAD → fall back to GET static
        if ($method === 'HEAD') {
            $getKey = 'GET:' . $path;
            if (isset($this->staticRoutes[$getKey])) {
                $route = $this->staticRoutes[$getKey];
                $request->setRouteParams([]);
                return $this->runRoute($route, $request);
            }
        }

        // ------------------------------------------------------------------
        // 2. Dynamic routes: regex matching
        // ------------------------------------------------------------------
        $allowedMethods = [];

        // Try matching with current method
        foreach ($this->dynamicRoutes[$method] ?? [] as $route) {
            $params = $route->match($method, $path);
            if ($params !== null) {
                $request->setRouteParams($params);
                return $this->runRoute($route, $request);
            }
        }

        // Check if other methods match (for 405 detection)
        foreach ($this->dynamicRoutes as $routeMethod => $routes) {
            if ($routeMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if ($route->match($routeMethod, $path) !== null) {
                    $allowedMethods[] = $routeMethod;
                }
            }
        }

        // Also check static routes for other methods (405 detection)
        foreach ($this->staticRoutes as $key => $route) {
            [$routeMethod, $routePath] = explode(':', $key, 2);
            if ($routePath === $path && $routeMethod !== $method) {
                $allowedMethods[] = $routeMethod;
            }
        }

        // ------------------------------------------------------------------
        // 3. Not Found or Method Not Allowed
        // ------------------------------------------------------------------
        if (!empty($allowedMethods)) {
            return $this->handleMethodNotAllowed(
                array_unique($allowedMethods),
                $request
            );
        }

        return $this->handleNotFound($request);
    }

    // =========================================================================
    // URL Generation
    // =========================================================================

    /**
     * Generate a URL for a named route.
     *
     * Example: $router->route('users.show', ['id' => 42]) → '/users/42'
     */
    public function route(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new Exception("Route [{$name}] not defined.");
        }

        $uri = $this->namedRoutes[$name]->getUri();

        // Replace {param} and {param?}
        foreach ($params as $key => $value) {
            $uri = str_replace(
                ['{' . $key . '}', '{' . $key . '?}'],
                (string) $value,
                $uri
            );
        }

        // Remove any remaining optional params
        $uri = preg_replace('#/\{[^}]+\?\}#', '', $uri);

        // Remaining required params not supplied
        if (preg_match('/\{[^}]+\}/', $uri)) {
            throw new Exception("Missing required parameters for route [{$name}].");
        }

        return $uri;
    }

    // =========================================================================
    // Internal Helpers
    // =========================================================================

    /**
     * Core method: create a Route and register it.
     */
    protected function addRoute(array $methods, string $uri, mixed $handler): Route
    {
        // Apply group prefix and namespace
        $group = $this->currentGroup();

        if ($group) {
            $uri     = $this->mergeGroupPrefix($group->getPrefix(), $uri);
            $handler = $this->mergeGroupNamespace($group->getNamespace(), $handler);
        }

        $uri   = '/' . trim($uri, '/') ?: '/';
        $route = new Route($methods, $uri, $handler);

        // Apply group middleware
        if ($group && !empty($group->getMiddleware())) {
            $route->middleware($group->getMiddleware());
        }

        // Apply group name prefix
        if ($group && $group->getName() !== null) {
            // Routes inside a group can then call ->name('suffix')
            // and get 'groupprefix.suffix' automatically.
            // We store the prefix on the route for later use via ->name()
        }

        // Store the route
        $this->registerRoute($route);

        return $route;
    }

    /**
     * Store a Route in the correct bucket (static or dynamic).
     */
    protected function registerRoute(Route $route): void
    {
        if ($route->isStatic()) {
            foreach ($route->getMethods() as $method) {
                $this->staticRoutes[$method . ':' . $route->getUri()] = $route;
            }
        } else {
            foreach ($route->getMethods() as $method) {
                $this->dynamicRoutes[$method][] = $route;
            }
        }

        // Register named route
        if ($route->getName() !== null) {
            $this->namedRoutes[$route->getName()] = $route;
        }
    }

    /**
     * After a route is named (via Route::name()), sync it into namedRoutes.
     * This is called lazily — not every route needs a name.
     */
    public function syncNamedRoute(Route $route): void
    {
        if ($route->getName() !== null) {
            $this->namedRoutes[$route->getName()] = $route;
        }
    }

    /**
     * Run a matched route: execute its handler and return a Response.
     */
    protected function runRoute(Route $route, Request $request): Response
    {
        $handler = $route->getHandler();

        $response = $this->callHandler($handler, $request);

        // Normalize return value into a Response
        return $this->toResponse($response);
    }

    /**
     * Execute the route handler.
     * Supports:
     *  - Closure / callable
     *  - 'ControllerClass@method'
     *  - [ControllerClass::class, 'method']
     */
    protected function callHandler(mixed $handler, Request $request): mixed
    {
        // Closure or any callable
        if ($handler instanceof \Closure || (is_callable($handler) && !is_string($handler) && !is_array($handler))) {
            return $handler($request);
        }

        // 'Controller@method' string
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $controller = new $class();
            return $controller->$method($request);
        }

        // [ControllerClass::class, 'method'] array
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = is_string($class) ? new $class() : $class;
            return $controller->$method($request);
        }

        // Invokable class string
        if (is_string($handler) && class_exists($handler)) {
            $controller = new $handler();
            return $controller($request);
        }

        throw new Exception("Invalid route handler.");
    }

    /**
     * Normalize any value returned from a handler into a Response.
     */
    protected function toResponse(mixed $value): Response
    {
        if ($value instanceof Response) {
            return $value;
        }

        if (is_array($value) || is_object($value)) {
            return Response::json($value);
        }

        if (is_string($value) || is_numeric($value)) {
            return Response::make((string) $value);
        }

        if ($value === null) {
            return Response::noContent();
        }

        return Response::make((string) $value);
    }

    /**
     * Handle a 404 Not Found response.
     */
    protected function handleNotFound(Request $request): Response
    {
        if ($this->notFoundHandler !== null) {
            return $this->toResponse(($this->notFoundHandler)($request));
        }

        return Response::make('404 Not Found', 404);
    }

    /**
     * Handle a 405 Method Not Allowed response.
     */
    protected function handleMethodNotAllowed(array $allowedMethods, Request $request): Response
    {
        if ($this->methodNotAllowedHandler !== null) {
            return $this->toResponse(
                ($this->methodNotAllowedHandler)($request, $allowedMethods)
            );
        }

        return Response::make('405 Method Not Allowed', 405)
            ->header('Allow', implode(', ', $allowedMethods));
    }

    /**
     * Merge group prefix with route URI.
     */
    protected function mergeGroupPrefix(string $prefix, string $uri): string
    {
        $uri = trim($uri, '/');
        return $prefix . ($uri ? '/' . $uri : '');
    }

    /**
     * Prepend namespace to a string handler.
     */
    protected function mergeGroupNamespace(string $namespace, mixed $handler): mixed
    {
        if (!$namespace || !is_string($handler)) {
            return $handler;
        }

        // Only prepend if handler looks like a class reference
        if (str_contains($handler, '@') || class_exists($handler)) {
            return trim($namespace, '\\') . '\\' . ltrim($handler, '\\');
        }

        return $handler;
    }

    /**
     * Return the currently active group, or null.
     */
    protected function currentGroup(): ?RouteGroup
    {
        return !empty($this->groupStack)
            ? end($this->groupStack)
            : null;
    }

    // =========================================================================
    // Debug helpers
    // =========================================================================

    /**
     * Get all registered routes (useful for debugging / artisan-style listing)
     */
    public function getRoutes(): array
    {
        $all = [];

        foreach ($this->staticRoutes as $route) {
            $all[] = $route;
        }

        foreach ($this->dynamicRoutes as $routes) {
            foreach ($routes as $route) {
                $all[] = $route;
            }
        }

        return $all;
    }

    /**
     * Dump a route table to stdout (for debugging)
     */
    public function dumpRoutes(): void
    {
        echo "\n";
        printf("%-8s %-40s %-10s %s\n", 'METHOD', 'URI', 'TYPE', 'HANDLER');
        echo str_repeat('-', 90) . "\n";

        foreach ($this->getRoutes() as $route) {
            $handler = $route->getHandler();

            if ($handler instanceof \Closure) {
                $label = '{Closure}';
            } elseif (is_array($handler)) {
                $label = implode('@', $handler);
            } else {
                $label = (string) $handler;
            }

            printf(
                "%-8s %-40s %-10s %s\n",
                implode('|', $route->getMethods()),
                $route->getUri(),
                $route->isStatic() ? 'static' : 'dynamic',
                $label
            );
        }

        echo "\n";
    }
}