<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Jobs;

use PlaylogiqUtils\Jobs\BaseJob;
use PlaylogiqUtils\Integrity\CriticalSettingsGuard;
use PlaylogiqUtils\Integrity\ScheduledSettingsIntegrityChecks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GLI-33 §2.3 — the daily Settings Integrity Check of every GLI-regulated skin and of the
 * global settings.
 *
 * Started by the scheduler at 04:00 UTC. Each regulated skin's live certified settings are
 * compared against its newest snapshot, by the same comparison the backoffice button runs,
 * and each comparison leaves a settings_integrity_checks row with trigger "scheduled". The work
 * and its rules are ScheduledSettingsIntegrityChecks::run(); this job only starts it and
 * logs the summary.
 *
 * It writes no snapshot: a drift is reported and recorded, never certified. Only a
 * successful save of the skin's settings records a new baseline.
 *
 * One attempt. A retry would be a second check, not a retry of the first, and every way a
 * comparison can fail is already a recorded outcome rather than an exception.
 */
class RunScheduledSettingsIntegrityChecks extends BaseJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 1;

    /** One run at a time; released on completion, so this only covers a process that died. */
    public $uniqueFor = 60 * 60;

    public function handle(ScheduledSettingsIntegrityChecks $checks): void
    {
        $outcomes = $checks->run();

        $counts = array_count_values($outcomes);
        ksort($counts);

        $summary = [];
        foreach ($counts as $status => $count) {
            $summary[] = $status . '=' . $count;
        }

        $message = sprintf(
            'RunScheduledSettingsIntegrityChecks [GLI-33 §2.3]: %d scope(s) checked (regulated skins, and the global settings when certified) — %s',
            count($outcomes),
            $summary === [] ? 'none' : implode(' ', $summary)
        );

        // Anything but a match is a finding, already recorded on its row and, for a drift or
        // a missing baseline, as a significant event; the log line is what alerting reads.
        $findings = array_diff($outcomes, [CriticalSettingsGuard::CHECK_MATCHES]);

        if ($findings === []) {
            Log::info($message);

            return;
        }

        Log::critical($message . ' — scopes with findings: ' . implode(', ', array_keys($findings)));
    }
}
