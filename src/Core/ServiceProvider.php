<?php

declare(strict_types=1);

namespace Lovante\Core;

abstract class ServiceProvider
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    abstract public function register(): void;

    public function boot(): void
    {
        // Optional boot method
    }

    public function provides(): array
    {
        return [];
    }
}