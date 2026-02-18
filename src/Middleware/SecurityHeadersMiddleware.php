<?php

declare(strict_types=1);

namespace Lovante\Middleware;

use Lovante\Http\Request;
use Lovante\Http\Response;

/**
 * Security Headers Middleware
 *
 * Automatically adds modern browser security headers to every response.
 * Protects against clickjacking, XSS, MIME sniffing, and more.
 *
 * Usage:
 *   $pipeline->pipe(new SecurityHeadersMiddleware());
 *
 *   // Or with custom config:
 *   $pipeline->pipe(new SecurityHeadersMiddleware([
 *       'csp' => "default-src 'self'; script-src 'self' cdn.example.com",
 *   ]));
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            // Prevent clickjacking
            'x_frame_options'        => 'SAMEORIGIN',

            // Prevent MIME type sniffing
            'x_content_type_options' => 'nosniff',

            // Legacy XSS protection for older browsers
            'x_xss_protection'       => '1; mode=block',

            // Referrer policy
            'referrer_policy'        => 'strict-origin-when-cross-origin',

            // Permissions / Feature Policy
            'permissions_policy'     => 'camera=(), microphone=(), geolocation=()',

            // HSTS (only enable if you are 100% on HTTPS in production)
            'hsts'                   => '',

            // Content Security Policy (empty = not set)
            'csp'                    => '',
        ], $config);
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        return $this->applyHeaders($response, $request);
    }

    protected function applyHeaders(Response $response, Request $request): Response
    {
        $h = $this->config;

        if ($h['x_frame_options']) {
            $response->header('X-Frame-Options', $h['x_frame_options']);
        }

        if ($h['x_content_type_options']) {
            $response->header('X-Content-Type-Options', $h['x_content_type_options']);
        }

        if ($h['x_xss_protection']) {
            $response->header('X-XSS-Protection', $h['x_xss_protection']);
        }

        if ($h['referrer_policy']) {
            $response->header('Referrer-Policy', $h['referrer_policy']);
        }

        if ($h['permissions_policy']) {
            $response->header('Permissions-Policy', $h['permissions_policy']);
        }

        // HSTS only over HTTPS
        if ($h['hsts'] && $request->isSecure()) {
            $response->header('Strict-Transport-Security', $h['hsts']);
        }

        if ($h['csp']) {
            $response->header('Content-Security-Policy', $h['csp']);
        }

        return $response;
    }
}