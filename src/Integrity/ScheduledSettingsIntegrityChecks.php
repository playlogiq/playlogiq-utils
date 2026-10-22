<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use PlaylogiqUtils\Integrity\Contracts\CriticalSettingsReader;
use PlaylogiqUtils\Integrity\Models\SettingsIntegrityCheck;

/**
 * GLI-33 §2.3 — the daily Settings Integrity Check: every GLI-regulated skin and the global
 * settings, compared against their newest snapshot and recorded with TRIGGER_SCHEDULED and
 * no user.
 *
 * No authorisation, deliberately: nobody asks for this run, the scheduler does, and it
 * reaches every regulated skin because certifying all of them is the point. It is reachable
 * from RunScheduledSettingsIntegrityChecks and nothing a browser can call.
 */
class ScheduledSettingsIntegrityChecks
{
    /** The outcome key for the global settings, beside the skin ids. */
    public const GLOBAL_SCOPE = SettingsSnapshotStore::GLOBAL_SCOPE;

    /** @var CriticalSettingsGuard|null */
    private $guard;

    /** @var SettingsIntegrityCheckLog */
    private $log;

    public function __construct(?CriticalSettingsGuard $guard = null, ?SettingsIntegrityCheckLog $log = null)
    {
        $this->guard = $guard;
        $this->log   = $log ?? new SettingsIntegrityCheckLog();
    }

    /**
     * Built on first use, not in the constructor: fromConfig() reads configuration, so
     * building it here means the guard sees the configuration in force when the check
     * actually runs.
     */
    private function guard(): CriticalSettingsGuard
    {
        if ($this->guard === null) {
            $this->guard = CriticalSettingsGuard::fromConfig();
        }

        return $this->guard;
    }

    /**
     * Each skin is its own comparison and its own row, and none of them can stop the rest:
     * inspect() and the log both report failures instead of throwing. A drift or a missing
     * baseline writes its significant event through the guard, exactly as the button does.
     *
     * @return array<int|string, string> skin id, or GLOBAL_SCOPE, => the guard's CHECK_* outcome
     */
    public function run(): array
    {
        $reader = app(CriticalSettingsReader::class, [
            'inventory' => new CriticalComponentsInventory((array) config('critical_components'), base_path()),
        ]);

        $outcomes = [];

        foreach ($reader->regulatedSkinIds() as $skinId) {
            $report = $this->guard()->inspect($skinId);

            // Unregulated between the listing and the check: nothing certified to compare.
            if ($report['status'] === CriticalSettingsGuard::CHECK_NOT_REGULATED) {
                continue;
            }

            $this->log->record(SettingsIntegrityCheck::TRIGGER_SCHEDULED, $report, null);

            $outcomes[$skinId] = (string) $report['status'];
        }

        $global = $this->guard()->inspect(null);

        // Switched off, there is nothing to compare: the skins above still say so on their rows.
        if ($global['status'] !== CriticalSettingsGuard::CHECK_DISABLED) {
            $this->log->record(SettingsIntegrityCheck::TRIGGER_SCHEDULED, $global, null);

            $outcomes[self::GLOBAL_SCOPE] = (string) $global['status'];
        }

        return $outcomes;
    }
}
