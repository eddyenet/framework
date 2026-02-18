<?php

declare(strict_types=1);

namespace Lovante\Middleware;

use Lovante\Http\Request;
use Lovante\Http\Response;

/**
 * CSRF Middleware
 *
 * Validates CSRF tokens on state-changing requests (POST, PUT, PATCH, DELETE).
 * Token is stored in $_SESSION and must be sent via:
 *   - POST body field:  _token
 *   - Header:          X-CSRF-Token  (for AJAX/SPA)
 *   - Header:          X-XSRF-Token  (for Axios/Angular compatibility)
 *
 * Usage in forms:
 *   <input type="hidden" name="_token" value="{{ csrf_token() }}">
 *
 * Usage in AJAX:
 *   headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content }
 *
 * Excluded paths:
 *   $pipeline->pipe(new CsrfMiddleware(except: ['/api/*', '/webhook/*']));
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * Methods that must be verified
     */
    protected const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Session key for the CSRF token
     */
    protected const SESSION_KEY = '_zephyr_csrf_token';

    /**
     * URI patterns to skip CSRF check (e.g. API routes, webhooks)
     */
    protected array $except;

    public function __construct(array $except = [])
    {
        $this->except = $except;
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->shouldVerify($request)) {
            if (!$this->validateToken($request)) {
                return Response::make('CSRF token mismatch.', 419)
                    ->header('Content-Type', 'text/plain');
            }
        }

        $response = $next($request);

        // Rotate token after each state-changing request for extra security
        if (in_array($request->method(), self::PROTECTED_METHODS, true)) {
            $this->rotateToken();
        }

        return $response;
    }

    /**
     * Determine if this request needs CSRF validation
     */
    protected function shouldVerify(Request $request): bool
    {
        // Only verify state-changing methods
        if (!in_array($request->method(), self::PROTECTED_METHODS, true)) {
            return false;
        }

        // Skip if path matches any except pattern
        foreach ($this->except as $pattern) {
            if ($request->is($pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the CSRF token from the request
     */
    protected function validateToken(Request $request): bool
    {
        $sessionToken = $this->getSessionToken();

        if (empty($sessionToken)) {
            return false;
        }

        $requestToken = $this->getTokenFromRequest($request);

        if (empty($requestToken)) {
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        return hash_equals($sessionToken, $requestToken);
    }

    /**
     * Extract token from request (body or headers)
     */
    protected function getTokenFromRequest(Request $request): string
    {
        // 1. Form field _token
        $token = $request->input('_token', '');
        if ($token) return (string) $token;

        // 2. X-CSRF-Token header (custom AJAX header)
        $token = $request->header('X-CSRF-Token', '');
        if ($token) return (string) $token;

        // 3. X-XSRF-Token header (Axios / Angular compatibility)
        $token = $request->header('X-XSRF-Token', '');
        return (string) $token;
    }

    /**
     * Get the CSRF token from session, generating one if needed
     */
    protected function getSessionToken(): string
    {
        $this->ensureSessionStarted();

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = $this->generateToken();
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Generate and return the current CSRF token (for use in views)
     */
    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Rotate the token (regenerate for next request)
     */
    protected function rotateToken(): void
    {
        $this->ensureSessionStarted();
        $_SESSION[self::SESSION_KEY] = $this->generateToken();
    }

    protected function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    protected function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
}