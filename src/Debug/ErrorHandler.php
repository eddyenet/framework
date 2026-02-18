<?php

declare(strict_types=1);

namespace Lovante\Debug;

use Throwable;

/**
 * Lovante ErrorHandler
 *
 * Registers PHP error/exception/shutdown handlers.
 * In debug mode  → renders the beautiful debug page.
 * In production  → renders a clean generic error page.
 */
class ErrorHandler
{
    protected static bool $registered = false;

    /**
     * Register as the global PHP error/exception handler.
     */
    public static function register(bool $debug = true): void
    {
        if (static::$registered) {
            return;
        }

        static::$registered = true;

        // Convert PHP errors into ErrorException
        set_error_handler(static function (
            int    $severity,
            string $message,
            string $file,
            int    $line
        ): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        // Handle uncaught exceptions
        set_exception_handler(static function (Throwable $e) use ($debug): void {
            static::handleException($e, $debug);
        });

        // Handle fatal errors (E_ERROR, E_PARSE, etc.)
        register_shutdown_function(static function () use ($debug): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $e = new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );
                static::handleException($e, $debug);
            }
        });
    }

    /**
     * Handle an exception — render and send the appropriate response.
     */
    public static function handleException(Throwable $e, bool $debug = true): void
    {
        // Clear any partial output
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        $statusCode = static::resolveStatusCode($e);

        http_response_code($statusCode);
        header('Content-Type: text/html; charset=UTF-8');

        if ($debug) {
            echo ExceptionHandler::renderDebugPage($e);
        } else {
            echo ExceptionHandler::renderProductionPage($statusCode);
        }
    }

    /**
     * Guess HTTP status code from exception type/code.
     */
    protected static function resolveStatusCode(Throwable $e): int
    {
        $code = $e->getCode();

        if ($code >= 400 && $code < 600) {
            return $code;
        }

        return 500;
    }

    /**
     * Unregister handlers (useful for testing).
     */
    public static function unregister(): void
    {
        restore_error_handler();
        restore_exception_handler();
        static::$registered = false;
    }
}