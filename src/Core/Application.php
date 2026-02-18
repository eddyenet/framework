<?php

declare(strict_types=1);

namespace Lovante\Core;

use Closure;
use Exception;

class Application extends Container
{
    const VERSION = '1.0.0';

    protected string $basePath;
    protected bool $hasBeenBootstrapped = false;
    protected array $serviceProviders = [];
    protected array $loadedProviders = [];
    protected array $bootedProviders = [];

    public function __construct(?string $basePath = null)
    {
        if ($basePath) {
            $this->setBasePath($basePath);
        }

        $this->registerBaseBindings();
        $this->registerBaseServiceProviders();
    }

    protected function registerBaseBindings(): void
    {
        static::setInstance($this);

        $this->instance('app', $this);
        $this->instance(Container::class, $this);
        $this->instance(Application::class, $this);
    }

    protected function registerBaseServiceProviders(): void
    {
        // Base service providers will be registered here
        // For now, we keep it minimal for performance
    }

    public function setBasePath(string $basePath): static
    {
        $this->basePath = rtrim($basePath, '\/');
        $this->bindPathsInContainer();
        return $this;
    }

    protected function bindPathsInContainer(): void
    {
        $this->bind('path', fn() => $this->path());
        $this->bind('path.base', fn() => $this->basePath());
        $this->bind('path.config', fn() => $this->configPath());
        $this->bind('path.public', fn() => $this->publicPath());
        $this->bind('path.storage', fn() => $this->storagePath());
        $this->bind('path.resources', fn() => $this->resourcePath());
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }

    public function path(string $path = ''): string
    {
        return $this->basePath('app') . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }

    public function configPath(string $path = ''): string
    {
        return $this->basePath('config') . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }

    public function publicPath(string $path = ''): string
    {
        return $this->basePath('public') . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }

    public function storagePath(string $path = ''): string
    {
        return $this->basePath('storage') . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }

    public function resourcePath(string $path = ''): string
    {
        return $this->basePath('resources') . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }

    public function register(object|string $provider, bool $force = false): object
    {
        if (is_string($provider)) {
            $provider = $this->resolveProvider($provider);
        }

        $providerClass = get_class($provider);

        if (!$force && isset($this->loadedProviders[$providerClass])) {
            return $this->loadedProviders[$providerClass];
        }

        if (method_exists($provider, 'register')) {
            $provider->register();
        }

        $this->loadedProviders[$providerClass] = $provider;
        $this->serviceProviders[] = $provider;

        if ($this->hasBeenBootstrapped) {
            $this->bootProvider($provider);
        }

        return $provider;
    }

    protected function resolveProvider(string $provider): object
    {
        return new $provider($this);
    }

    public function boot(): void
    {
        if ($this->hasBeenBootstrapped) {
            return;
        }

        foreach ($this->serviceProviders as $provider) {
            $this->bootProvider($provider);
        }

        $this->hasBeenBootstrapped = true;
    }

    protected function bootProvider(object $provider): void
    {
        $providerClass = get_class($provider);

        if (isset($this->bootedProviders[$providerClass])) {
            return;
        }

        if (method_exists($provider, 'boot')) {
            $provider->boot();
        }

        $this->bootedProviders[$providerClass] = true;
    }

    public function handle(mixed $request): mixed
    {
        // This will be implemented when we create Router and Request/Response
        return null;
    }

    public function version(): string
    {
        return static::VERSION;
    }

    public function isDebug(): bool
    {
        return (bool) ($_ENV['APP_DEBUG'] ?? false);
    }

    public function environment(): string
    {
        return $_ENV['APP_ENV'] ?? 'production';
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function isLocal(): bool
    {
        return $this->environment() === 'local';
    }
}