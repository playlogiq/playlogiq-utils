<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Jobs;

use PlaylogiqUtils\Jobs\BaseJob;
use PlaylogiqUtils\Integrity\Models\AppIntegrityCheck;
use PlaylogiqUtils\Integrity\Models\AppIntegrityCheckInstance;
use PlaylogiqUtils\Integrity\CriticalComponentsInventory;
use PlaylogiqUtils\Integrity\HostIdentity;
use PlaylogiqUtils\Integrity\ManifestVerifier;
use PlaylogiqUtils\Integrity\Contracts\SignificantEvents;
use PlaylogiqUtils\Integrity\VerificationReport;
use PlaylogiqUtils\Integrity\VerificationTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GLI-33 §2.3 — verifies THIS host's control program against the signed manifest.
 *
 * Scheduled hourly on every instance. The point of the check is per-host: an instance
 * whose files have been altered is not made safe by a sibling that verified cleanly, so
 * a result is only worth having if every instance in the auto-scaling group produces its
 * own.
 *
 * Two deliberate choices make that true, and both look like mistakes until you consider
 * what the alternative does:
 *
 * 1. THE SYNC CONNECTION. The job runs in the process that dispatches it — the
 *    scheduler's, on the instance the scheduler is running on. Dispatching to Redis or
 *    the database instead would put one job on a queue shared by the whole fleet, and
 *    exactly one instance — whichever worker got there first — would run it. The
 *    remaining instances would be reported on by a box that is not them. Sync is not a
 *    shortcut here; it is the only connection that makes the result mean anything, and
 *    there is nothing to gain from a broker: the work is a few hundred file hashes
 *    against local disk, with no external call to wait on.
 *
 *    This is also why no supervisor worker is needed. Adding one that listened on a
 *    shared 'integrity' queue would reintroduce the very problem sync avoids.
 *
 * 2. THE HOST-SCOPED UNIQUE ID. BaseJob implements ShouldBeUnique, and Laravel takes
 *    that lock in PendingDispatch *before* the connection is consulted — through the
 *    default cache store, which here is the shared ElastiCache cluster. With a constant
 *    uniqueId, the first instance to reach the top of the hour would take a fleet-wide
 *    lock and every other instance would silently decline to dispatch, leaving one
 *    instance verified per hour and the rest unchecked while the logs looked healthy.
 *    Keying the lock on the host restores what uniqueness is actually wanted for:
 *    stopping two overlapping runs on the SAME box.
 *
 * The job never throws. There is nothing here to retry — the manifest, the signature and
 * the files are all local disk, so a second attempt reads the same bytes and reaches the
 * same verdict — and every way this can fail is a finding rather than a transport
 * problem. Being unable to verify (no manifest, no signature, no configured key) is
 * treated as failing, not as skipping: that state is exactly what tampering would try to
 * produce, so it is logged at the same level as a mismatch.
 *
 * Every failure is logged critically, which is what alerting reads, and recorded as an
 * APP_INTEGRITY_CHECK_FAILED significant event (GLI-33 2.8.8) through the application's
 * PlaylogiqUtils\Integrity\Contracts\SignificantEvents binding.
 *
 * WHAT IT RECORDS. Every run writes one row to app_integrity_check_instances, under the
 * app_integrity_checks header it belongs to — shared by every instance for a scheduled
 * run, its own for a manual one. The row is opened before the work starts and closed with
 * the verdict after. The log line answers "is something wrong
 * right now", which is a question with a short shelf life; the row answers "did this
 * instance check itself, and what did it say", months later, to somebody holding a
 * certificate. Recording is wrapped so that it can never fail the check — see record().
 *
 * WHO ASKED. $trigger names that, and it defaults to TRIGGER_SCHEDULED because the
 * scheduler is the only caller that does not pass one. The backoffice button runs this
 * same job with TRIGGER_MANUAL, through Bus::dispatchNow() rather than dispatch(): the
 * unique lock below is acquired in PendingDispatch, so a manual run arriving while the
 * hourly one holds the lock would be dropped on the floor and the operator would watch a
 * button do nothing. dispatchNow() bypasses PendingDispatch entirely, and runs the handler
 * on the caller's own object so it can read recordedInstance() afterwards — which
 * dispatch_sync() does NOT do, because a ShouldQueue job goes through SyncQueue and is
 * reconstructed from a serialized payload. Two overlapping runs are harmless here — the
 * job reads files and hashes them — and two rows are the truth.
 */
class VerifyControlProgram extends BaseJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt. A retry would re-read the same disk and reach the same conclusion,
     * and a check that reports twice makes an alert look like two incidents.
     */
    public $tries = 1;

    /**
     * Shorter than the hourly schedule, so a lock orphaned by a killed process clears
     * itself before the next run rather than skipping it.
     */
    public $uniqueFor = 1800;

    /**
     * What asked for this run, as an AppIntegrityCheck::TRIGGER_* value.
     *
     * @var int
     */
    protected $trigger;

    /**
     * The backoffice user who asked for this run, recorded on the check's header. Null
     * for the scheduler, which has no user.
     *
     * @var int|null
     */
    protected $createdBy;

    /**
     * The row this run opened, or null when it could not be recorded. Readable after a
     * dispatchNow(), which runs the handler on the very object the caller holds — that is
     * how the backoffice endpoint reports on the run it just triggered without having to
     * guess which row is its own.
     *
     * @var AppIntegrityCheckInstance|null
     */
    protected $instance;

    public function __construct(int $trigger = AppIntegrityCheck::TRIGGER_SCHEDULED, ?int $createdBy = null)
    {
        $this->trigger = $trigger;
        $this->createdBy = $createdBy;

        // Set here rather than left to configuration: QUEUE_CONNECTION is redis in
        // production, and inheriting it would hand this job to the shared fleet queue.
        // See the class docblock — the connection is part of what the check means.
        $this->connection = 'sync';

        // Nominal under sync, which executes immediately regardless of queue. Named
        // anyway so the job is identifiable in a failed_jobs payload, and so it is
        // obvious which queue would have to exist if this ever moved off sync.
        $this->queue = 'integrity';
    }

    public function handle(): void
    {
        $host = new HostIdentity();

        // Opened as UNVERIFIABLE. Every early return below is a run that could not reach a
        // verdict, and the row is already saying so — nothing has to be closed on those
        // paths, including the ones this process never returns from.
        $this->openCheck($host);

        try {
            $target = VerificationTarget::fromConfig(
                (array) config('critical_components.verification'),
                base_path()
                // No override, and allowUnsigned left false: the fleet check always
                // requires a signature. An unsigned pass would say the tree matches a
                // manifest without saying the pipeline produced that manifest, which on
                // a live instance is not a weaker result — it is not a result.
            );
        } catch (Throwable $e) {
            $this->reportUnverifiable($host, $e->getMessage());

            return;
        }

        try {
            $verifier = new ManifestVerifier(
                new CriticalComponentsInventory((array) config('critical_components'), base_path()),
                base_path()
            );

            $report = $verifier->verify($target->manifestPath(), $target->publicKeyPath());
        } catch (Throwable $e) {
            // The verifier turns the inventory's own refusals into findings, so reaching
            // here means something more basic broke. Still a failure to verify, not a
            // pass, and still not worth a retry.
            $this->reportUnverifiable($host, $e->getMessage());

            return;
        }

        $report->meta('host', $host->describe());

        if ($report->passed()) {
            $this->closeCheck(AppIntegrityCheckInstance::RESULT_PASSED);

            Log::info(sprintf(
                'VerifyControlProgram [GLI-33 §2.3]: verified — host=%s release=%s inventory=%s files=%s manifest=%s',
                $host->describe(),
                $this->meta($report, 'release_version'),
                $this->meta($report, 'inventory_version'),
                $this->meta($report, 'covered_files'),
                substr($this->meta($report, 'manifest_sha256'), 0, 12)
            ));

            return;
        }

        $this->closeCheck(AppIntegrityCheckInstance::RESULT_FAILED, $report->failureRecord());

        app(SignificantEvents::class)->controlProgramFailed(
            false,
            $this->instance !== null ? (int) $this->instance->id : null,
            $host->describe(),
            array_keys($report->failures()),
            $report->summary(),
            $this->createdBy
        );

        Log::critical(sprintf(
            'VerifyControlProgram [GLI-33 §2.3]: CONTROL PROGRAM MISMATCH — host=%s release=%s inventory=%s '
            . 'manifest=%s checks[%s] %s',
            $host->describe(),
            $this->meta($report, 'release_version'),
            $this->meta($report, 'inventory_version'),
            substr($this->meta($report, 'manifest_sha256'), 0, 12),
            $report->summary(),
            $report->failureDetail()
        ));
    }

    /**
     * The check could not be performed at all.
     *
     * Logged at the same level as a mismatch on purpose. "I could not tell" and "it does
     * not match" are different diagnoses but the same operational state: this instance
     * cannot show that it is running the certified control program.
     */
    private function reportUnverifiable(HostIdentity $host, string $reason): void
    {
        // The row already says UNVERIFIABLE; this only adds why.
        $this->closeCheck(AppIntegrityCheckInstance::RESULT_UNVERIFIABLE, AppIntegrityCheckInstance::unverifiable($reason));

        app(SignificantEvents::class)->controlProgramFailed(
            true,
            $this->instance !== null ? (int) $this->instance->id : null,
            $host->describe(),
            [],
            $reason,
            $this->createdBy
        );

        Log::critical(sprintf(
            'VerifyControlProgram [GLI-33 §2.3]: CANNOT VERIFY — host=%s reason=%s',
            $host->describe(),
            $reason
        ));
    }

    /**
     * The row this run produced, for a caller that ran the job inline and wants to report
     * on it. Null when the run could not be recorded at all.
     */
    public function recordedInstance(): ?AppIntegrityCheckInstance
    {
        return $this->instance;
    }

    /**
     * Open this run's row, before anything is verified.
     *
     * Recording is evidence OF the check, never part of it. A database this instance
     * cannot reach must not turn a host that matches its manifest into a failed
     * verification, nor a mismatched one into a silent success — so a failure here is
     * logged and the job carries on to its real verdict exactly as it would have.
     */
    private function openCheck(HostIdentity $host): void
    {
        try {
            $this->instance = AppIntegrityCheckInstance::openFor(
                $this->trigger,
                $host->instanceId() ?? $host->hostname(),
                $this->createdBy
            );
        } catch (Throwable $e) {
            $this->instance = null;

            Log::error(
                'VerifyControlProgram [GLI-33 §2.3]: could not record this run — host='
                . $host->describe() . ' error=' . $e->getMessage()
            );
        }
    }

    /**
     * Close the row with the verdict and what explains it. No-op when openCheck() could
     * not write one.
     *
     * @param array<string, mixed>|null $details
     */
    private function closeCheck(int $result, ?array $details = null): void
    {
        if ($this->instance === null) {
            return;
        }

        try {
            $this->instance->close($result, $details);
        } catch (Throwable $e) {
            // Same reasoning as openCheck(). The row is left saying UNVERIFIABLE, which
            // understates a pass and never overstates one — the safe direction to be wrong in.
            // close() has tried to put the lost verdict and this error in its details.
            Log::error(
                'VerifyControlProgram [GLI-33 §2.3]: could not record the result of this run — error='
                . $e->getMessage()
            );
        }
    }

    private function meta(VerificationReport $report, string $key): string
    {
        $metadata = $report->metadata();

        return isset($metadata[$key]) ? (string) $metadata[$key] : 'unknown';
    }

    /**
     * Scoped to this host — see the class docblock. Getting this wrong does not break
     * the job, it silently reduces a fleet-wide control to a single-instance one.
     *
     * The hostname, not describe(): this runs at dispatch time and again when the lock
     * is released, and neither moment should depend on the instance metadata service
     * being reachable. Every instance has a distinct hostname, which is all a lock key
     * needs — the instance id is for the log line, where it is worth a round trip.
     */
    public function uniqueId(): string
    {
        return 'vcp:' . (new HostIdentity())->hostname();
    }
}
