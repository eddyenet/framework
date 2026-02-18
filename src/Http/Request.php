<?php

declare(strict_types=1);

namespace Lovante\Http;

/**
 * Lovante Request
 *
 * Represents an incoming HTTP request.
 * Wraps PHP superglobals into a clean, immutable interface.
 * Designed for speed - no heavy parsing unless needed.
 */
class Request
{
    /**
     * HTTP method (GET, POST, PUT, PATCH, DELETE, etc.)
     */
    protected string $method;

    /**
     * Request URI path (without query string)
     */
    protected string $uri;

    /**
     * Query string parameters ($_GET)
     */
    protected array $query;

    /**
     * POST body parameters ($_POST)
     */
    protected array $body;

    /**
     * Uploaded files ($_FILES)
     */
    protected array $files;

    /**
     * Request headers (normalized)
     */
    protected array $headers;

    /**
     * Server variables ($_SERVER)
     */
    protected array $server;

    /**
     * Cookies ($_COOKIE)
     */
    protected array $cookies;

    /**
     * Parsed JSON body (lazy-loaded)
     */
    protected ?array $json = null;

    /**
     * Route parameters set by the Router
     */
    protected array $routeParams = [];

    /**
     * Create a new Request instance
     */
    public function __construct(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $files = [],
        array $headers = [],
        array $server = [],
        array $cookies = []
    ) {
        $this->method  = strtoupper($method);
        $this->uri     = $this->parseUri($uri);
        $this->query   = $query;
        $this->body    = $body;
        $this->files   = $files;
        $this->headers = $headers;
        $this->server  = $server;
        $this->cookies = $cookies;
    }

    /**
     * Create a Request from PHP superglobals (the typical use case)
     */
    public static function capture(): static
    {
        return new static(
            method:  static::detectMethod(),
            uri:     $_SERVER['REQUEST_URI'] ?? '/',
            query:   $_GET,
            body:    $_POST,
            files:   $_FILES,
            headers: static::parseHeaders(),
            server:  $_SERVER,
            cookies: $_COOKIE
        );
    }

    /**
     * Create a Request manually (useful for testing)
     */
    public static function create(
        string $uri,
        string $method = 'GET',
        array $params = [],
        array $headers = []
    ): static {
        $method = strtoupper($method);
        $query  = [];
        $body   = [];

        if ($method === 'GET') {
            $query = $params;
        } else {
            $body = $params;
        }

        return new static(
            method:  $method,
            uri:     $uri,
            query:   $query,
            body:    $body,
            headers: $headers,
            server:  [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI'    => $uri,
                'SERVER_NAME'    => 'localhost',
                'SERVER_PORT'    => '80',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Method
    // -------------------------------------------------------------------------

    /**
     * Get the HTTP method
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Check if the request uses a given HTTP method
     */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isGet(): bool    { return $this->method === 'GET'; }
    public function isPost(): bool   { return $this->method === 'POST'; }
    public function isPut(): bool    { return $this->method === 'PUT'; }
    public function isPatch(): bool  { return $this->method === 'PATCH'; }
    public function isDelete(): bool { return $this->method === 'DELETE'; }

    // -------------------------------------------------------------------------
    // URI / Path
    // -------------------------------------------------------------------------

    /**
     * Get the full URI (path + query string)
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Get path only (no query string)
     */
    public function path(): string
    {
        return parse_url($this->uri, PHP_URL_PATH) ?? '/';
    }

    /**
     * Check if path matches a given pattern (simple wildcard: *)
     */
    public function is(string $pattern): bool
    {
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\*', '.*', $pattern);
        return (bool) preg_match('#^' . $pattern . '$#', $this->path());
    }

    /**
     * Get the full URL including scheme and host
     */
    public function url(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->path();
    }

    /**
     * Get the full URL including query string
     */
    public function fullUrl(): string
    {
        $query = $this->queryString();
        return $this->url() . ($query ? '?' . $query : '');
    }

    /**
     * Get the URL scheme (http or https)
     */
    public function scheme(): string
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return 'https';
        }
        return 'http';
    }

    /**
     * Get the host name
     */
    public function host(): string
    {
        return $this->server['HTTP_HOST']
            ?? $this->server['SERVER_NAME']
            ?? 'localhost';
    }

    /**
     * Get the raw query string
     */
    public function queryString(): string
    {
        return $this->server['QUERY_STRING'] ?? '';
    }

    // -------------------------------------------------------------------------
    // Input (query + body merged, like Laravel's $request->input())
    // -------------------------------------------------------------------------

    /**
     * Get all input (query + body merged)
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * Get a single input value (query or body), with optional default
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Get only specific keys from input
     */
    public function only(string ...$keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * Get all input except specific keys
     */
    public function except(string ...$keys): array
    {
        $all = $this->all();
        return array_diff_key($all, array_flip($keys));
    }

    /**
     * Check if an input key exists
     */
    public function has(string $key): bool
    {
        return isset($this->body[$key]) || isset($this->query[$key]);
    }

    /**
     * Check if input key exists and is not empty
     */
    public function filled(string $key): bool
    {
        $value = $this->input($key);
        return $value !== null && $value !== '';
    }

    // -------------------------------------------------------------------------
    // Query String
    // -------------------------------------------------------------------------

    /**
     * Get a query parameter
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get all query parameters
     */
    public function queryAll(): array
    {
        return $this->query;
    }

    // -------------------------------------------------------------------------
    // Body (POST)
    // -------------------------------------------------------------------------

    /**
     * Get a POST body parameter
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Get all POST parameters
     */
    public function postAll(): array
    {
        return $this->body;
    }

    // -------------------------------------------------------------------------
    // JSON Body
    // -------------------------------------------------------------------------

    /**
     * Get a value from the JSON body (lazy parsed)
     */
    public function json(string $key, mixed $default = null): mixed
    {
        if ($this->json === null) {
            $this->json = $this->parseJsonBody();
        }

        return $this->json[$key] ?? $default;
    }

    /**
     * Get the full parsed JSON body
     */
    public function jsonAll(): array
    {
        if ($this->json === null) {
            $this->json = $this->parseJsonBody();
        }

        return $this->json;
    }

    /**
     * Check if request body is JSON
     */
    public function isJson(): bool
    {
        $contentType = $this->header('Content-Type', '');
        return str_contains($contentType, 'application/json');
    }

    /**
     * Check if request expects a JSON response
     */
    public function wantsJson(): bool
    {
        $accept = $this->header('Accept', '');
        return str_contains($accept, 'application/json')
            || str_contains($accept, 'application/x-json');
    }

    // -------------------------------------------------------------------------
    // Headers
    // -------------------------------------------------------------------------

    /**
     * Get a header value
     */
    public function header(string $key, mixed $default = null): mixed
    {
        $normalized = strtolower(str_replace('_', '-', $key));
        return $this->headers[$normalized] ?? $default;
    }

    /**
     * Check if a header exists
     */
    public function hasHeader(string $key): bool
    {
        $normalized = strtolower(str_replace('_', '-', $key));
        return isset($this->headers[$normalized]);
    }

    /**
     * Get all headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get the Bearer token from Authorization header
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Cookies
    // -------------------------------------------------------------------------

    /**
     * Get a cookie value
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Check if a cookie exists
     */
    public function hasCookie(string $key): bool
    {
        return isset($this->cookies[$key]);
    }

    // -------------------------------------------------------------------------
    // Files
    // -------------------------------------------------------------------------

    /**
     * Get an uploaded file
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Check if a file was uploaded
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key])
            && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    // -------------------------------------------------------------------------
    // Route Parameters (set by Router)
    // -------------------------------------------------------------------------

    /**
     * Get a route parameter
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Get all route parameters
     */
    public function params(): array
    {
        return $this->routeParams;
    }

    /**
     * Set route parameters (called by Router)
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if the request is an AJAX / XHR request
     */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Check if request is over HTTPS
     */
    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    /**
     * Get the client IP address
     */
    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['HTTP_CLIENT_IP']
            ?? $this->server['REMOTE_ADDR']
            ?? '127.0.0.1';
    }

    /**
     * Get the User-Agent string
     */
    public function userAgent(): string
    {
        return $this->header('user-agent', '');
    }

    /**
     * Get a server variable
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    // -------------------------------------------------------------------------
    // Internal Helpers
    // -------------------------------------------------------------------------

    /**
     * Detect the real HTTP method (supports _method override for HTML forms)
     */
    protected static function detectMethod(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Support HTML form method override via hidden _method field
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        // Support X-HTTP-Method-Override header
        if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            return strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }

        return strtoupper($method);
    }

    /**
     * Parse all headers from $_SERVER
     */
    protected static function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$header] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $header = strtolower(str_replace('_', '-', $key));
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    /**
     * Strip query string from URI and normalize
     */
    protected function parseUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        return '/' . trim($path, '/') ?: '/';
    }

    /**
     * Parse JSON from raw request body
     */
    protected function parseJsonBody(): array
    {
        if (!$this->isJson()) {
            return [];
        }

        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}