<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Contracts;

/**
 * Where the integrity checks report their findings (GLI-33 significant events).
 *
 * The checks decide what is a finding; the application decides where a finding goes. An
 * application with a significant-event store binds its own implementation; one without
 * gets LogSignificantEvents, which writes each finding to the log at critical.
 *
 * Implementations must never throw. Every caller is reporting a finding it has already
 * made, and a store that cannot be reached must not change the outcome of a settings save
 * or a verification.
 */
interface SignificantEvents
{
    /** A skin's certified settings moved outside a save. */
    const DRIFT = 'critical_settings_mismatch';

    /** A regulated skin has no snapshot to compare against. */
    const MISSING_BASELINE = 'critical_settings_missing_baseline';

    /**
     * A critical-settings finding, from CriticalSettingsGuard: DRIFT or MISSING_BASELINE.
     * $skinId is null for the global settings.
     *
     * $context is the guard's: `drift` (setting => snapshot/database/incoming), `compared_to`
     * (the snapshot key), `saving` (the keys the save was writing) and `admin_id`.
     */
    public function write(string $event, ?int $skinId, array $context = []): void;

    /**
     * An instance that does not match its signed manifest, or could not be verified.
     *
     * @param bool     $unverifiable    true when the check could not run, false on a mismatch
     * @param int|null $instanceRowId   its app_integrity_check_instances row, when one was written
     * @param string   $host            which instance, as HostIdentity::describe() puts it
     * @param string[] $failedChecks    the verifier checks that failed; empty when unverifiable
     * @param string   $reason          the verifier's summary, or why it could not run
     * @param int|null $adminId         who asked, for a check started from the backoffice
     */
    public function controlProgramFailed(
        bool $unverifiable,
        ?int $instanceRowId,
        string $host,
        array $failedChecks,
        string $reason,
        ?int $adminId = null
    ): void;

    /** A fleet check whose command never reached the instances, so none of them verified. */
    public function fleetCheckNotSent(int $checkId, string $reason, ?int $adminId = null): void;
}
