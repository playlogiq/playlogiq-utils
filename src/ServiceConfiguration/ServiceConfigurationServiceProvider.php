<?php

declare(strict_types=1);

namespace PlaylogiqUtils\ServiceConfiguration;

use Illuminate\Support\ServiceProvider;

/**
 * Service Configuration Profiles (PQPL-7443): the default config (no types) and the
 * migrations of the two SCP tables. Auto-discovered via composer.json's extra.laravel.providers.
 *
 * The migrations keep betmaker's filenames and guard every step, so a database that already
 * has the tables skips them.
 */
class ServiceConfigurationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'service_configuration');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->publishes([
            $this->configPath() => $this->app->configPath('service_configuration.php'),
        ], 'playlogiq-service-configuration-config');
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../config/service_configuration.php';
    }
}
