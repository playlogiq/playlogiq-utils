<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Jobs;

use PlaylogiqUtils\Jobs\BaseJob;
use PlaylogiqUtils\Integrity\Models\AppIntegrityCheck;
use PlaylogiqUtils\Integrity\Models\AppIntegrityCheckInstance;
use Aws\Exception\AwsException;
use PlaylogiqUtils\Integrity\FleetCommand;
use PlaylogiqUtils\Integrity\Contracts\SignificantEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GLI-33 §2.3 — starts one control-program check across the whole fleet.
 *
 * Opens the check's header and sends it to every instance over SSM (FleetCommand); each
 * instance verifies itself with `integrity:verify --check=<id>` and records its row under
 * that header. This job verifies nothing itself, and does not wait for the instances.
 *
 * Started from two places: the scheduler, daily at 04:00 UTC, and the backoffice button. Both go
 * through dispatch(), never dispatchNow(), because the unique lock is the point:
 *
 * THE FLEET-WIDE UNIQUE ID. The opposite of VerifyControlProgram's host-scoped one. The
 * lock is taken in PendingDispatch through the shared cache, so while one dispatch is in
 * flight anywhere in the fleet a second one is discarded — two operators pressing the
 * button at once start one check, not two. Under sync the lock is released as soon as
 * handle() returns.
 *
 * THE GROUP KEY. The lock covers the moment of sending; the key covers the rest. The
 * header is claimed by a unique key before anything is sent — `scheduled:<hour>` for the
 * scheduler, so the daily check is one per slot even if two schedulers fire; a key the
 * caller generates for a manual one, which is also how the caller finds the header this
 * job opened after the dispatch returns. A key that is already taken sends nothing.
 *
 * THE SYNC CONNECTION, BY DEFAULT. The work is one API call, and running in the caller's
 * process is what lets the button report the check it started in the same response, so
 * sync is what the constructor sets. The scheduler overrides it with the default queue
 * connection (see Kernel): nothing waits for that run, and through the queue it is
 * processed and logged by a worker like every other job.
 *
 * Never throws: a check that could not be sent is a finding, not a retry. It is logged
 * critically, and its header is left with no command id and no instances, which the
 * listing shows as unverifiable — with the reason in the header's details.
 */
class DispatchFleetIntegrityCheck extends BaseJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One attempt: a resend would be a second check, not a retry of the first. */
    public $tries = 1;

    /** Only reached if a process dies holding the lock; it is released on completion. */
    public $uniqueFor = 300;

    /** @var int */
    protected $trigger;

    /** @var int|null */
    protected $createdBy;

    /** @var string|null */
    protected $groupKey;

    /**
     * @param string|null $groupKey null for the scheduled check, which is keyed by its hour
     */
    public function __construct(
        int $trigger = AppIntegrityCheck::TRIGGER_SCHEDULED,
        ?int $createdBy = null,
        ?string $groupKey = null
    ) {
        $this->trigger = $trigger;
        $this->createdBy = $createdBy;
        $this->groupKey = $groupKey;

        // No queue of its own: under sync it does not matter, and on the scheduler's queued
        // run the connection's default queue is the one the workers listen on.
        $this->connection = 'sync';
    }

    public function handle(FleetCommand $fleet): void
    {
        $key = $this->groupKey ?? AppIntegrityCheck::scheduledGroupKey(now());

        try {
            $check = AppIntegrityCheck::claim($key, $this->trigger, $this->createdBy);
        } catch (Throwable $e) {
            Log::critical('DispatchFleetIntegrityCheck [GLI-33 §2.3]: could not open the check — key='
                . $key . ' error=' . $e->getMessage());

            return;
        }

        if ($check === null) {
            Log::warning('DispatchFleetIntegrityCheck [GLI-33 §2.3]: check already started, nothing sent — key=' . $key);

            return;
        }

        try {
            $commandId = $fleet->send($check->triggerLabel(), (int) $check->id);
        } catch (Throwable $e) {
            app(SignificantEvents::class)->fleetCheckNotSent((int) $check->id, self::describeError($e), $this->createdBy);

            Log::critical(sprintf(
                'DispatchFleetIntegrityCheck [GLI-33 §2.3]: CANNOT SEND CHECK TO THE FLEET — check=%d key=%s error=%s',
                $check->id,
                $key,
                $e->getMessage()
            ));

            $this->recordNotSent($check, $e);

            return;
        }

        try {
            $check->update(['ssm_command_id' => $commandId]);
        } catch (Throwable $e) {
            // The command is out and the instances will report under the check regardless;
            // only the pointer to the SSM side of it is missing, and the log keeps it.
            Log::error('DispatchFleetIntegrityCheck [GLI-33 §2.3]: could not record the command id — check='
                . $check->id . ' command=' . $commandId . ' error=' . $e->getMessage());
        }

        Log::info(sprintf(
            'DispatchFleetIntegrityCheck [GLI-33 §2.3]: check sent to the fleet — check=%d trigger=%s command=%s',
            $check->id,
            $check->triggerLabel(),
            $commandId
        ));
    }

    /**
     * Put on the header why it has no instances: with nothing sent, there is no instance
     * row to say it. Best effort — the log above already has it.
     */
    private function recordNotSent(AppIntegrityCheck $check, Throwable $e): void
    {
        $error = self::describeError($e);

        try {
            $check->update(['details' => AppIntegrityCheckInstance::unverifiable(
                'The check could not be sent to the fleet, so no instance verified itself: ' . $error
            )]);
        } catch (Throwable $ignored) {
            Log::error('DispatchFleetIntegrityCheck [GLI-33 §2.3]: could not record why the check was not sent — check='
                . $check->id . ' error=' . $ignored->getMessage());
        }
    }

    /**
     * An AwsException's message is the whole HTTP exchange; its code and message are what
     * an operator needs, e.g. "InvalidParameters: ... check_id ...".
     */
    private static function describeError(Throwable $e): string
    {
        return $e instanceof AwsException && $e->getAwsErrorCode()
            ? $e->getAwsErrorCode() . ': ' . $e->getAwsErrorMessage()
            : $e->getMessage();
    }

    /** One for the whole fleet — see the class docblock. */
    public function uniqueId(): string
    {
        return 'fleet';
    }
}
