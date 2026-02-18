<?php

declare(strict_types=1);

namespace Lovante\Routing;

/**
 * Lovante Route
 *
 * Represents a single registered route.
 * Holds the method, URI pattern, handler, middleware, and name.
 */
class Route
{
    /**
     * Compiled regex pattern (cached after first compile)
     */
    protected ?string $compiled = null;

    /**
     * Parameter names extracted from URI pattern
     * e.g. /users/{id}/posts/{slug} → ['id', 'slug']
     */
    protected array $paramNames = [];

    /**
     * Per-parameter regex constraints
     * e.g. ['id' => '\d+', 'slug' => '[a-z0-9\-]+']
     */
    protected array $wheres = [];

    /**
     * Middleware stack assigned to this route
     */
    protected array $middleware = [];

    /**
     * Optional route name
     */
    protected ?string $name = null;

    public function __construct(
        protected array          $methods,
        protected string         $uri,
        protected mixed          $handler
    ) {
        $this->methods = array_map('strtoupper', $methods);
        $this->uri     = '/' . trim($uri, '/') ?: '/';
    }

    // -------------------------------------------------------------------------
    // Fluent configuration (chainable)
    // -------------------------------------------------------------------------

    /**
     * Assign a name to this route
     */
    public function name(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Add middleware to this route
     */
    public function middleware(string|array $middleware): static
    {
        $this->middleware = array_merge(
            $this->middleware,
            (array) $middleware
        );
        return $this;
    }

    /**
     * Add a regex constraint for a route parameter
     */
    public function where(string $param, string $regex): static
    {
        $this->wheres[$param] = $regex;
        $this->compiled = null; // invalidate cache
        return $this;
    }

    /**
     * Shortcut: constrain parameter to digits only
     */
    public function whereNumber(string $param): static
    {
        return $this->where($param, '[0-9]+');
    }

    /**
     * Shortcut: constrain parameter to alpha only
     */
    public function whereAlpha(string $param): static
    {
        return $this->where($param, '[a-zA-Z]+');
    }

    /**
     * Shortcut: constrain parameter to alphanumeric + dash
     */
    public function whereSlug(string $param): static
    {
        return $this->where($param, '[a-z0-9\-]+');
    }

    // -------------------------------------------------------------------------
    // Matching
    // -------------------------------------------------------------------------

    /**
     * Check if this route matches a given HTTP method and path.
     * Returns extracted parameters on match, or null on no match.
     */
    public function match(string $method, string $path): ?array
    {
        // Fast method check first (avoid regex if method doesn't match)
        if (!in_array($method, $this->methods, true)) {
            return null;
        }

        // Compile regex if not cached
        if ($this->compiled === null) {
            $this->compile();
        }

        // Try to match the path
        if (!preg_match($this->compiled, $path, $matches)) {
            return null;
        }

        // Extract named captures into associative params array
        $params = [];
        foreach ($this->paramNames as $name) {
            if (isset($matches[$name]) && $matches[$name] !== '') {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }

    /**
     * Compile the URI pattern into a regex string (cached).
     *
     * Handles:
     *   {param}   → required named capture
     *   {param?}  → optional named capture
     */
    protected function compile(): void
    {
        $this->paramNames = [];

        $uri = $this->uri;

        // Step 1: replace /({param?}) — optional segment absorbs the leading slash
        $pattern = preg_replace_callback(
            '/\/\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}/',
            function (array $m) {
                $name = $m[1];
                $this->paramNames[] = $name;
                $regex = $this->wheres[$name] ?? '.+';
                return '(?:/(?P<' . $name . '>' . $regex . '))?';
            },
            $uri
        );

        // Step 2: handle {param?} not preceded by slash (edge case)
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\?\}/',
            function (array $m) {
                $name = $m[1];
                if (!in_array($name, $this->paramNames, true)) {
                    $this->paramNames[] = $name;
                }
                $regex = $this->wheres[$name] ?? '.+';
                return '(?P<' . $name . '>' . $regex . ')?';
            },
            $pattern
        );

        // Step 3: replace {param} required segments
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m) {
                $name = $m[1];
                $this->paramNames[] = $name;
                $regex = $this->wheres[$name] ?? '[^/]+';
                return '(?P<' . $name . '>' . $regex . ')';
            },
            $pattern
        );

        $this->compiled = '#^' . $pattern . '$#u';
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getMethods(): array   { return $this->methods; }
    public function getUri(): string      { return $this->uri; }
    public function getHandler(): mixed   { return $this->handler; }
    public function getMiddleware(): array{ return $this->middleware; }
    public function getName(): ?string    { return $this->name; }
    public function getWheres(): array    { return $this->wheres; }
    public function getParamNames(): array{ return $this->paramNames; }

    /**
     * Check if route is static (no params) - used for fast-path optimization
     */
    public function isStatic(): bool
    {
        return !str_contains($this->uri, '{');
    }
}