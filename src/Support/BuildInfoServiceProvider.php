<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Support;

use Illuminate\Support\ServiceProvider;

/**
 * Merges `app.build` and `app.version` into the application's config.
 *
 * Auto-discovered via composer.json's extra.laravel.providers, so an installed
 * application gets config('app.build.version') without editing config/app.php —
 * which is the point: a package cannot publish into a file the framework owns.
 *
 * mergeConfigFrom merges only the top level and lets the application's own
 * value win, so an app that already defines `app.build` or `app.version` keeps
 * it. It is also skipped entirely once the config is cached — by design: the
 * values were already resolved and baked into the cached file when
 * `config:cache` ran, which for a built artifact is exactly the identity of the
 * commit it was built from.
 *
 * Nothing is bound or booted; BuildInfo is static and touches no container.
 */
class BuildInfoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'app');
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../config/app-build.php';
    }
}
