<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use PlaylogiqUtils\Integrity\Models\SettingsIntegrityCheck;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GLI-33 §2.3 — writes one settings_integrity_checks row per comparison, whoever ran it.
 *
 * Two doors run the comparison: the backoffice button (the application's own check service,
 * TRIGGER_MANUAL) and the guard in front of every save of a skin's critical settings
 * (CriticalSettingsGuard::check(), TRIGGER_CONFIGURATION_CHANGE). One writer, so the
 * history reads the same whichever door a row came through.
 *
 * Never throws. The comparison has already happened when this runs: for the button its
 * answer is still worth showing, and for a save, a history that could not be written must
 * not decide whether the settings are. A failed write is logged at critical instead.
 */
class SettingsIntegrityCheckLog
{
    /**
     * Returns the row's id, or null when it could not be written.
     *
     * @param array{skin_id: int|null, status: string, compared_to: string|null, drift: array, error: string|null} $report
     *        the guard's report shape: `status` is one of its CHECK_* outcomes
     */
    public function record(int $trigger, array $report, ?int $userId): ?int
    {
        try {
            return (int) SettingsIntegrityCheck::create([
                // Null is the global settings scope.
                'skin_id'     => $report['skin_id'] === null ? null : (int) $report['skin_id'],
                'trigger'     => $trigger,
                'status'      => SettingsIntegrityCheck::statusFor((string) $report['status']),
                'compared_to' => $report['compared_to'],
                'drift'       => $report['drift'] === [] ? null : $report['drift'],
                'error'       => $report['error'],
                'created_by'  => $userId,
                'created_at'  => now('UTC'),
            ])->id;
        } catch (Throwable $e) {
            Log::critical(sprintf(
                'SettingsIntegrityCheckLog: could not record the %s check of %s (%s): %s',
                SettingsIntegrityCheck::TRIGGERS[$trigger] ?? (string) $trigger,
                SettingsSnapshotStore::scopeName($report['skin_id'] === null ? null : (int) $report['skin_id']),
                (string) $report['status'],
                $e->getMessage()
            ));

            return null;
        }
    }
}
