<?php

declare(strict_types=1);

namespace Lovante\Routing;

/**
 * Lovante RouteGroup
 *
 * Accumulates shared attributes (prefix, middleware, namespace)
 * that are applied to every route registered inside the group callback.
 */
class RouteGroup
{
    public function __construct(
        protected string $prefix     = '',
        protected array  $middleware = [],
        protected string $namespace  = '',
        protected ?string $name      = null
    ) {}

    /**
     * Merge a child group's attributes with this (parent) group.
     * Returns a new RouteGroup - no mutation.
     */
    public function merge(array $attributes): static
    {
        $prefix = $this->prefix . '/' . trim($attributes['prefix'] ?? '', '/');
        $prefix = '/' . trim($prefix, '/');

        $middleware = array_merge(
            $this->middleware,
            (array) ($attributes['middleware'] ?? [])
        );

        $namespace = trim(
            trim($this->namespace, '\\') . '\\' . trim($attributes['namespace'] ?? '', '\\'),
            '\\'
        );

        $name = ($this->name ?? '') . ($attributes['name'] ?? '');
        $name = $name === '' ? null : $name;

        return new static($prefix, $middleware, $namespace, $name);
    }

    public function getPrefix(): string      { return $this->prefix; }
    public function getMiddleware(): array   { return $this->middleware; }
    public function getNamespace(): string   { return $this->namespace; }
    public function getName(): ?string       { return $this->name; }
}