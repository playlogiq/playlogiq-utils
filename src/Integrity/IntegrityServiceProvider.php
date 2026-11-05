<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Illuminate\Support\ServiceProvider;
use PlaylogiqUtils\Integrity\Console\IntegrityBaseline;
use PlaylogiqUtils\Integrity\Console\IntegritySettingsSnapshot;
use PlaylogiqUtils\Integrity\Console\IntegritySign;
use PlaylogiqUtils\Integrity\Console\IntegrityVerify;
use PlaylogiqUtils\Integrity\Contracts\SignificantEvents;
use PlaylogiqUtils\Integrity\Tinker\AuditedTinkerCommand;

/**
 * GLI-33 integrity checks: the integrity:* commands, and a log-only SignificantEvents for
 * an application that has not bound its own.
 *
 * The tinker command audit (PQPL-7456): tinker stamps each command it runs, and
 * Jobs\ImportTinkerSessionCommands reads them back into operations_audit_log.
 *
 * The application still provides config/critical_components.php, config/integrity.php
 * (with session_audit.app), the migrations, and a Contracts\CriticalSettingsReader binding
 * if it uses the settings check.
 */
class IntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(SignificantEvents::class, LogSignificantEvents::class);

        // Bound, not singletons, so a config change (for example, in tests) builds a fresh instance.
        $this->app->bind(ExecStamp::class, static function (): ExecStamp {
            return ExecStamp::fromConfig();
        });

        $this->app->bind(SessionManagerLogs::class, static function (): SessionManagerLogs {
            return SessionManagerLogs::fromConfig();
        });

        // PQPL-7324: tinker stamps every command it runs for the Session Manager audit. Only
        // runs when command.tinker is resolved, so an application without laravel/tinker never loads it.
        $this->app->extend('command.tinker', static function (): AuditedTinkerCommand {
            return new AuditedTinkerCommand();
        });
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
