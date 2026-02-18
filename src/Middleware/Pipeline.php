<?php

declare(strict_types=1);

namespace Lovante\Middleware;

use Lovante\Http\Request;
use Lovante\Http\Response;
use Exception;

/**
 * Lovante Middleware Pipeline
 *
 * Builds a nested callable chain from a list of middleware classes,
 * then runs it with a Request to produce a Response.
 *
 * Performance notes:
 * - Stack is built once, then reused
 * - Each layer is a single closure wrapping the next
 * - No reflection, no container lookups during dispatch
 *
 * Usage:
 *   $response = (new Pipeline())
 *       ->pipe(CorsMiddleware::class)
 *       ->pipe(SecurityHeadersMiddleware::class)
 *       ->run($request, fn($req) => $router->dispatch($req));
 */
class Pipeline
{
    /**
     * Ordered list of middleware (class names or instances)
     *
     * @var array<MiddlewareInterface|string>
     */
    protected array $middleware = [];

    /**
     * Add a middleware to the end of the pipeline
     *
     * @param MiddlewareInterface|string $middleware
     */
    public function pipe(MiddlewareInterface|string $middleware): static
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Prepend a middleware to the beginning of the pipeline
     *
     * @param MiddlewareInterface|string $middleware
     */
    public function prepend(MiddlewareInterface|string $middleware): static
    {
        array_unshift($this->middleware, $middleware);
        return $this;
    }

    /**
     * Run the pipeline with the given Request and core handler.
     *
     * @param Request  $request
     * @param callable $destination  fn(Request): Response  — the final handler (router)
     * @return Response
     */
    public function run(Request $request, callable $destination): Response
    {
        $pipeline = $this->buildPipeline($destination);
        return $pipeline($request);
    }

    /**
     * Build the nested callable chain (inside-out).
     *
     * Given middleware [A, B, C] and destination D, builds:
     *   A( B( C( D($request) ) ) )
     *
     * We fold from right to left so the first middleware in the list
     * is the outermost (first to execute on the way in).
     */
    protected function buildPipeline(callable $destination): callable
    {
        // Start from the destination (innermost callable)
        $next = $this->wrapDestination($destination);

        // Wrap each middleware around it, from last to first
        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $this->wrapMiddleware(
                $this->resolveMiddleware($middleware),
                $next
            );
        }

        return $next;
    }

    /**
     * Wrap a single middleware around the $next callable.
     */
    protected function wrapMiddleware(MiddlewareInterface $middleware, callable $next): callable
    {
        return function (Request $request) use ($middleware, $next): Response {
            return $middleware->handle($request, $next);
        };
    }

    /**
     * Wrap the destination (final handler) to ensure it returns a Response.
     */
    protected function wrapDestination(callable $destination): callable
    {
        return function (Request $request) use ($destination): Response {
            $result = $destination($request);

            if ($result instanceof Response) {
                return $result;
            }

            if (is_array($result) || is_object($result)) {
                return Response::json($result);
            }

            if (is_string($result) || is_numeric($result)) {
                return Response::make((string) $result);
            }

            return Response::noContent();
        };
    }

    /**
     * Resolve a middleware — accepts class name string or instance.
     *
     * @throws Exception
     */
    protected function resolveMiddleware(MiddlewareInterface|string $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (!class_exists($middleware)) {
            throw new Exception("Middleware class [{$middleware}] not found.");
        }

        $instance = new $middleware();

        if (!$instance instanceof MiddlewareInterface) {
            throw new Exception(
                "Class [{$middleware}] must implement MiddlewareInterface."
            );
        }

        return $instance;
    }

    /**
     * Get the current middleware stack
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Clear all middleware
     */
    public function flush(): void
    {
        $this->middleware = [];
    }
}