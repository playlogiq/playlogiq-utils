<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Status;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the status-check defaults.
 *
 * Auto-discovered via composer.json's extra.laravel.providers, so a consuming
 * application gets working defaults without touching config/app.php. Nothing is
 * bound into the container: StatusCheckService has a zero-argument constructor
 * and the container autowires it.
 */
class StatusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'status');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => $this->app->configPath('status.php'),
        ], 'playlogiq-status-config');
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../config/status.php';
    }
}
