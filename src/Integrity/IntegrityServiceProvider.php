<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Illuminate\Support\ServiceProvider;
use PlaylogiqUtils\Integrity\Console\IntegrityBaseline;
use PlaylogiqUtils\Integrity\Console\IntegritySettingsSnapshot;
use PlaylogiqUtils\Integrity\Console\IntegritySign;
use PlaylogiqUtils\Integrity\Console\IntegrityVerify;
use PlaylogiqUtils\Integrity\Contracts\SignificantEvents;

/**
 * GLI-33 integrity checks: the integrity:* commands, and a log-only SignificantEvents for
 * an application that has not bound its own.
 *
 * The application still provides config/critical_components.php, config/integrity.php, the
 * migrations, and a Contracts\CriticalSettingsReader binding if it uses the settings check.
 */
class IntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(SignificantEvents::class, LogSignificantEvents::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            IntegrityBaseline::class,
            IntegritySign::class,
            IntegrityVerify::class,
            IntegritySettingsSnapshot::class,
        ]);
    }
}
