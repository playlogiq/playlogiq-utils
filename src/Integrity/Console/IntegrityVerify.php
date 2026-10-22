<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Console;

use PlaylogiqUtils\Integrity\Models\AppIntegrityCheck;
use PlaylogiqUtils\Integrity\Models\AppIntegrityCheckInstance;
use PlaylogiqUtils\Integrity\CriticalComponentsInventory;
use PlaylogiqUtils\Integrity\HostIdentity;
use PlaylogiqUtils\Integrity\ManifestVerifier;
use PlaylogiqUtils\Integrity\Contracts\SignificantEvents;
use PlaylogiqUtils\Integrity\VerificationReport;
use PlaylogiqUtils\Integrity\VerificationTarget;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks this host against the signed manifest it was deployed with.
 *
 * The by-hand counterpart to the scheduled job: same verifier, same configuration, same
 * verdict, with the reasoning printed instead of logged. Reach for it when the fleet job
 * has reported a mismatch and the question is which files — or before a release, to
 * confirm a freshly built instance matches what the pipeline signed.
 *
 * Exit code is the answer: 0 verified, 1 not. That makes it usable as a health check or
 * a deployment gate without parsing the output, and `--json` is there for when something
 * does want to parse it.
 *
 * This is also what every instance runs when a fleet check reaches it over SSM — see
 * DispatchFleetIntegrityCheck — with --check naming the check to report into.
 *
 * Reads only, with one exception: every run writes this instance's row to
 * app_integrity_check_instances, under the check named by --check or a check of its own,
 * so the evidence that the check happened does not depend on anyone having kept the
 * console output. That row is opened before the verification starts and closed with the
 * verdict after — see openCheck(). It cannot repair a mismatch, and deliberately offers
 * no option to — the fix for a modified file is a redeploy of signed artifacts, not a
 * command that quietly makes the difference go away.
 */
class IntegrityVerify extends Command
{
    protected $signature = 'integrity:verify
        {--manifest= : Manifest to verify against (default: integrity-manifest.json in the app root).}
        {--public-key= : Verification key, overriding INTEGRITY_PUBLIC_KEY for this run.}
        {--allow-unsigned : Check the file hashes without checking the signature. Development and pre-signing CI only.}
        {--json : Emit the report as JSON instead of a table.}
        {--trigger=manual : What asked for this run (manual, deploy), recorded on the app_integrity_checks header.}
        {--check= : The app_integrity_checks id to report into. Set by the fleet dispatch over SSM; omit to record a check of its own.}';

    protected $description = 'Verify this host against the signed integrity manifest.';

    /** @var HostIdentity|null */
    private $host;

    public function handle(): int
    {
        $trigger = $this->resolveTrigger();

        if ($trigger === null) {
            $this->error(sprintf(
                'Unknown --trigger "%s". Expected one of: %s.',
                (string) $this->option('trigger'),
                implode(', ', AppIntegrityCheck::TRIGGERS)
            ));

            return self::FAILURE;
        }

        $check = $this->openCheck($trigger);

        try {
            $report = $this->runVerification();
        } catch (Throwable $e) {
            // Being unable to run the check is a failure of the check, never a pass.
            // The row already says UNVERIFIABLE; this only adds why.
            $this->error('Verification could not run: ' . $e->getMessage());

            $this->closeCheck(
                $check,
                AppIntegrityCheckInstance::RESULT_UNVERIFIABLE,
                AppIntegrityCheckInstance::unverifiable($e->getMessage())
            );

            app(SignificantEvents::class)->controlProgramFailed(
                true,
                $check !== null ? (int) $check->id : null,
                $this->host()->describe(),
                [],
                $e->getMessage(),
                $this->requester($check)
            );

            return self::FAILURE;
        }

        if ($report->passed()) {
            $this->closeCheck($check, AppIntegrityCheckInstance::RESULT_PASSED);

            return self::SUCCESS;
        }

        $this->closeCheck($check, AppIntegrityCheckInstance::RESULT_FAILED, $report->failureRecord());

        app(SignificantEvents::class)->controlProgramFailed(
            false,
            $check !== null ? (int) $check->id : null,
            $this->host()->describe(),
            array_keys($report->failures()),
            $report->summary(),
            $this->requester($check)
        );

        return self::FAILURE;
    }

    /**
     * The admin who started the check this run reports into, for a check started from the
     * backoffice; null for the scheduler, the deploy gate and a run by hand. Best effort:
     * a database that cannot be read here leaves the event attributed to the system.
     */
    private function requester(?AppIntegrityCheckInstance $check): ?int
    {
        try {
            $createdBy = $check !== null && $check->check !== null ? $check->check->created_by : null;

            return $createdBy !== null ? (int) $createdBy : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function runVerification(): VerificationReport
    {
        $target = VerificationTarget::fromConfig(
            (array) config('critical_components.verification'),
            base_path(),
            $this->stringOption('manifest'),
            $this->stringOption('public-key'),
            (bool) $this->option('allow-unsigned')
        );

        $verifier = new ManifestVerifier(
            new CriticalComponentsInventory((array) config('critical_components'), base_path()),
            base_path()
        );

        $report = $verifier->verify($target->manifestPath(), $target->publicKeyPath());
        $report->meta('host', $this->host()->describe());

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report;
        }

        $this->render($report);

        return $report;
    }

    private function render(VerificationReport $report): void
    {
        $metadata = [];

        foreach ($report->metadata() as $key => $value) {
            $metadata[] = [$key, is_scalar($value) ? (string) $value : json_encode($value)];
        }

        $this->table(['field', 'value'], $metadata);

        $rows = [];

        foreach ($report->checks() as $check => $state) {
            $rows[] = [$check, strtoupper($state['result']), $state['detail']];
        }

        $this->table(['check', 'result', 'detail'], $rows);

        // The paths themselves, in full and unabridged — the log line truncates them,
        // and needing more than the log line is the reason to run this by hand.
        foreach ($report->failures() as $check => $state) {
            foreach ($state['items'] as $kind => $paths) {
                if ($paths === []) {
                    continue;
                }

                $this->line('');
                $this->line(sprintf('<comment>%s / %s (%d):</comment>', $check, $kind, count($paths)));

                foreach ($paths as $path) {
                    $this->line('  ' . $path);
                }
            }
        }

        $this->line('');

        foreach ($report->skipped() as $check) {
            $this->warn(sprintf('%s check skipped: %s', $check, $report->detail($check)));
        }

        if ($report->passed()) {
            $this->info('This host matches the manifest.');

            return;
        }

        $this->error('This host does NOT match the manifest.');
    }

    /**
     * Open the row for this run, before anything is verified.
     *
     * It is written as UNVERIFIABLE on purpose rather than left null or pending. That is
     * the honest state of a check that has started and not reported, and it is also what
     * the row should still say if this process is killed, the host is terminated, or the
     * verifier throws — nothing has to clean up after a run that never finished, because
     * the row was already correct. Only a completed run overwrites it.
     *
     * Returns null when the row could not be written. Recording is evidence of the check,
     * not part of it: a database this command cannot reach must not turn a host that
     * matches its manifest into a failed verification, so the failure is a warning on
     * stderr and the verdict below is reached exactly as it would have been.
     */
    private function openCheck(int $trigger): ?AppIntegrityCheckInstance
    {
        // The instance id where there is one, the hostname everywhere else.
        $instanceId = $this->host()->instanceId() ?? $this->host()->hostname();

        try {
            $check = $this->requestedCheck();

            if ($check !== null) {
                return AppIntegrityCheckInstance::openIn($check, $instanceId);
            }

            return AppIntegrityCheckInstance::openFor($trigger, $instanceId);
        } catch (Throwable $e) {
            $this->warn('Could not record this verification run: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Close the row with the verdict and what explains it. No-op when openCheck() could
     * not write one.
     *
     * @param array<string, mixed>|null $details
     */
    private function closeCheck(?AppIntegrityCheckInstance $check, int $result, ?array $details = null): void
    {
        if ($check === null) {
            return;
        }

        try {
            $check->close($result, $details);
        } catch (Throwable $e) {
            // Same reasoning as openCheck(): the run happened and its verdict stands.
            // The row is left saying UNVERIFIABLE, which is at least not a false pass, and
            // close() has tried to put the lost verdict and this error in its details.
            $this->warn('Could not record the result of this verification run: ' . $e->getMessage());
        }
    }

    /**
     * The check named by --check, or null to record one of its own.
     *
     * A --check that names no check still verifies and still records — under a check of
     * its own, with a warning — rather than leave no evidence that this instance ran.
     */
    private function requestedCheck(): ?AppIntegrityCheck
    {
        $id = $this->stringOption('check');

        if ($id === null) {
            return null;
        }

        $check = ctype_digit($id) ? AppIntegrityCheck::find((int) $id) : null;

        if ($check === null) {
            $this->warn(sprintf('No app integrity check #%s; recording this run as a check of its own.', $id));
        }

        return $check;
    }

    /**
     * The --trigger option as its stored smallint, or null when it names nothing.
     *
     * Matched against the labels on the model so the two cannot drift: adding a trigger
     * there is all it takes for the command to accept it. TRIGGER_SCHEDULED is in that
     * map and therefore accepted, but nothing is expected to pass it — the daily job
     * does not go through this command and records its own row.
     */
    private function resolveTrigger(): ?int
    {
        $trigger = $this->option('trigger');

        if (!is_string($trigger)) {
            return null;
        }

        $value = array_search(strtolower(trim($trigger)), AppIntegrityCheck::TRIGGERS, true);

        return $value === false ? null : (int) $value;
    }

    /**
     * Memoised: HostIdentity caches its own metadata lookup per instance, so one object
     * shared by the row and the report costs at most one round trip to IMDS.
     */
    private function host(): HostIdentity
    {
        if ($this->host === null) {
            $this->host = new HostIdentity();
        }

        return $this->host;
    }

    /**
     * Console options arrive as '' when passed with no value; treat that as absent so
     * `--manifest=` cannot resolve to the application root.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
