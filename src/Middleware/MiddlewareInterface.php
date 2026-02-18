<?php

declare(strict_types=1);

namespace Lovante\Middleware;

use Lovante\Http\Request;
use Lovante\Http\Response;

/**
 * Lovante Middleware Interface
 *
 * Simple, fast contract — no PSR-15 overhead.
 * Every middleware receives the Request and a $next callable
 * that invokes the rest of the pipeline.
 *
 * Usage:
 *   public function handle(Request $request, callable $next): Response
 *   {
 *       // Before handler
 *       $response = $next($request);
 *       // After handler
 *       return $response;
 *   }
 */
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}