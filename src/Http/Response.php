<?php

declare(strict_types=1);

namespace Lovante\Http;

/**
 * Lovante Response
 *
 * Fluent HTTP response builder.
 * Supports HTML, JSON, redirects, file downloads, and custom responses.
 * All setters return $this for chaining.
 */
class Response
{
    /**
     * HTTP status codes
     */
    const HTTP_OK                    = 200;
    const HTTP_CREATED               = 201;
    const HTTP_ACCEPTED              = 202;
    const HTTP_NO_CONTENT            = 204;
    const HTTP_MOVED_PERMANENTLY     = 301;
    const HTTP_FOUND                 = 302;
    const HTTP_NOT_MODIFIED          = 304;
    const HTTP_BAD_REQUEST           = 400;
    const HTTP_UNAUTHORIZED          = 401;
    const HTTP_FORBIDDEN             = 403;
    const HTTP_NOT_FOUND             = 404;
    const HTTP_METHOD_NOT_ALLOWED    = 405;
    const HTTP_UNPROCESSABLE_ENTITY  = 422;
    const HTTP_TOO_MANY_REQUESTS     = 429;
    const HTTP_INTERNAL_SERVER_ERROR = 500;

    /**
     * HTTP status texts
     */
    protected static array $statusTexts = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    /**
     * Response body content
     */
    protected string $content = '';

    /**
     * HTTP status code
     */
    protected int $status = 200;

    /**
     * Response headers
     */
    protected array $headers = [];

    /**
     * Create a new Response instance
     */
    public function __construct(
        string $content = '',
        int    $status  = 200,
        array  $headers = []
    ) {
        $this->content = $content;
        $this->status  = $status;
        $this->headers = $headers;
    }

    // -------------------------------------------------------------------------
    // Static Factories
    // -------------------------------------------------------------------------

    /**
     * Create a plain text / HTML response
     */
    public static function make(string $content = '', int $status = 200, array $headers = []): static
    {
        return new static($content, $status, $headers);
    }

    /**
     * Create a JSON response
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): static
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $json = json_encode(['error' => 'JSON encoding failed']);
        }

        return new static(
            content: $json,
            status:  $status,
            headers: array_merge(['Content-Type' => 'application/json; charset=UTF-8'], $headers)
        );
    }

    /**
     * Create a redirect response
     */
    public static function redirect(string $url, int $status = 302): static
    {
        return new static(
            content: '',
            status:  $status,
            headers: ['Location' => $url]
        );
    }

    /**
     * Create a "no content" response (204)
     */
    public static function noContent(): static
    {
        return new static('', 204);
    }

    /**
     * Create a file download response
     */
    public static function download(
        string $filePath,
        string $fileName = '',
        array  $headers  = []
    ): static {
        if (!file_exists($filePath)) {
            return new static('File not found', 404);
        }

        $fileName = $fileName ?: basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        return new static(
            content: (string) file_get_contents($filePath),
            status:  200,
            headers: array_merge([
                'Content-Type'        => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'Content-Length'      => (string) filesize($filePath),
            ], $headers)
        );
    }

    /**
     * Create a view response (plain PHP template rendering)
     * Full template engine comes later - this is the raw version.
     */
    public static function view(string $path, array $data = [], int $status = 200): static
    {
        if (!file_exists($path)) {
            return new static("View [{$path}] not found.", 500);
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $path;
        $content = (string) ob_get_clean();

        return new static($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // -------------------------------------------------------------------------
    // Fluent Setters (all return $this for chaining)
    // -------------------------------------------------------------------------

    /**
     * Set the response content
     */
    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Set the HTTP status code
     */
    public function status(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Set a response header
     */
    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set multiple headers at once
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /**
     * Set the Content-Type header
     */
    public function contentType(string $type): static
    {
        return $this->header('Content-Type', $type);
    }

    /**
     * Set a cookie on the response
     */
    public function cookie(
        string $name,
        string $value,
        int    $minutes  = 0,
        string $path     = '/',
        string $domain   = '',
        bool   $secure   = false,
        bool   $httpOnly = true
    ): static {
        $this->headers['Set-Cookie'] = sprintf(
            '%s=%s; Path=%s%s%s%s%s',
            urlencode($name),
            urlencode($value),
            $path,
            $minutes ? '; Max-Age=' . ($minutes * 60) : '',
            $domain ? '; Domain=' . $domain : '',
            $secure ? '; Secure' : '',
            $httpOnly ? '; HttpOnly' : ''
        );
        return $this;
    }

    // -------------------------------------------------------------------------
    // Status Code Shortcuts
    // -------------------------------------------------------------------------

    public function ok(): static        { return $this->status(200); }
    public function created(): static   { return $this->status(201); }
    public function accepted(): static  { return $this->status(202); }
    public function notFound(): static  { return $this->status(404); }
    public function forbidden(): static { return $this->status(403); }
    public function unauthorized(): static { return $this->status(401); }
    public function serverError(): static  { return $this->status(500); }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    /**
     * Get the response body
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get the HTTP status code
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Get all headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header value
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Get the status text for the current status code
     */
    public function getStatusText(): string
    {
        return static::$statusTexts[$this->status] ?? 'Unknown';
    }

    /**
     * Check if the response is successful (2xx)
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Check if the response is a redirect (3xx)
     */
    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /**
     * Check if response is a client error (4xx)
     */
    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Check if response is a server error (5xx)
     */
    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    // -------------------------------------------------------------------------
    // Send
    // -------------------------------------------------------------------------

    /**
     * Send the HTTP status line and all headers
     */
    protected function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Status line
        $statusText = $this->getStatusText();
        header("HTTP/1.1 {$this->status} {$statusText}", true, $this->status);

        // Set default Content-Type if not already set
        if (!isset($this->headers['Content-Type'])) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true);
        }
    }

    /**
     * Send the response body
     */
    protected function sendContent(): void
    {
        echo $this->content;
    }

    /**
     * Send the full response (headers + body)
     */
    public function send(): void
    {
        $this->sendHeaders();
        $this->sendContent();
    }
}