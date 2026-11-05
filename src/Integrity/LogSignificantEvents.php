<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Illuminate\Support\Facades\Log;
use PlaylogiqUtils\Integrity\Contracts\SignificantEvents;

/**
 * The default SignificantEvents: every finding goes to the log at critical, which is what
 * alerting reads. For an application without a significant-event store of its own; one
 * that has one binds its own implementation instead.
 */
class LogSignificantEvents implements SignificantEvents
{
    public function write(string $event, ?int $skinId, array $context = []): void
    {
        Log::critical(
            '[integrity][significant_event] ' . $event,
            array_merge(['skin_id' => $skinId], $context)
        );
    }

    public function controlProgramFailed(
        bool $unverifiable,
        ?int $instanceRowId,
        string $host,
        array $failedChecks,
        string $reason,
        ?int $adminId = null
    ): void {
        Log::critical(
            '[integrity][significant_event] ' . ($unverifiable ? 'control_program_unverifiable' : 'control_program_mismatch'),
            [
                'instance_row_id' => $instanceRowId,
                'host'            => $host,
                'failed_checks'   => array_values($failedChecks),
                'reason'          => $reason,
                'admin_id'        => $adminId,
            ]
        );
    }

    public function fleetCheckNotSent(int $checkId, string $reason, ?int $adminId = null): void
    {
        Log::critical('[integrity][significant_event] fleet_check_not_sent', [
            'check_id' => $checkId,
            'reason'   => $reason,
            'admin_id' => $adminId,
        ]);
    }
}
