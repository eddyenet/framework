<?php

declare(strict_types=1);

namespace Lovante\Middleware;

use Lovante\Http\Request;
use Lovante\Http\Response;

/**
 * CORS Middleware
 *
 * Handles Cross-Origin Resource Sharing headers.
 * Supports preflight OPTIONS requests and configurable origins/methods/headers.
 *
 * Usage in your app bootstrap:
 *   $pipeline->pipe(new CorsMiddleware([
 *       'origins'  => ['https://yourfrontend.com'],
 *       'methods'  => ['GET', 'POST', 'PUT', 'DELETE'],
 *       'headers'  => ['Content-Type', 'Authorization'],
 *       'max_age'  => 86400,
 *   ]));
 */
class CorsMiddleware implements MiddlewareInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'origins'           => ['*'],
            'methods'           => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'headers'           => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'expose_headers'    => [],
            'max_age'           => 0,
            'allow_credentials' => false,
        ], $config);
    }

    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->header('origin', '');

        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            return $this->preflight($origin);
        }

        // Pass through to next middleware/handler
        $response = $next($request);

        // Add CORS headers to actual response
        return $this->addCorsHeaders($response, $origin);
    }

    /**
     * Build a preflight response (200 with CORS headers, no body)
     */
    protected function preflight(string $origin): Response
    {
        $response = Response::make('', 204);
        return $this->addCorsHeaders($response, $origin);
    }

    /**
     * Attach CORS headers to a Response
     */
    protected function addCorsHeaders(Response $response, string $origin): Response
    {
        $allowedOrigin = $this->resolveOrigin($origin);

        $response->header('Access-Control-Allow-Origin', $allowedOrigin);

        if ($this->config['allow_credentials']) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        $response->header(
            'Access-Control-Allow-Methods',
            implode(', ', $this->config['methods'])
        );

        $response->header(
            'Access-Control-Allow-Headers',
            implode(', ', $this->config['headers'])
        );

        if (!empty($this->config['expose_headers'])) {
            $response->header(
                'Access-Control-Expose-Headers',
                implode(', ', $this->config['expose_headers'])
            );
        }

        if ($this->config['max_age'] > 0) {
            $response->header(
                'Access-Control-Max-Age',
                (string) $this->config['max_age']
            );
        }

        return $response;
    }

    /**
     * Resolve which origin to allow.
     * If config origins is ['*'], return '*'.
     * Otherwise only reflect the origin if it's in the allowed list.
     */
    protected function resolveOrigin(string $origin): string
    {
        $allowed = $this->config['origins'];

        if ($allowed === ['*'] || in_array('*', $allowed, true)) {
            return '*';
        }

        if (in_array($origin, $allowed, true)) {
            return $origin;
        }

        // Origin not allowed — return first allowed origin as fallback
        return $allowed[0] ?? '*';
    }
}