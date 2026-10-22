<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * PQPL-5432 GLI-33 §2.3: one instance's verification, within an AppIntegrityCheck.
 *
 * The three results are deliberately the three VerificationReport can reach, and
 * UNVERIFIABLE is one of them. "I could not run the check" is not a pass and not a gap in
 * the record; it is a finding, and VerifyControlProgram already logs it at the same level
 * as a mismatch. A table that only stored passes and failures would show the checks that
 * ran and say nothing about a host that has been unable to check itself for a week.
 */
class AppIntegrityCheckInstance extends Model
{
    /** The tree matches the signed manifest. */
    public const RESULT_PASSED = 1;

    /** The check ran and the tree does not match. */
    public const RESULT_FAILED = 2;

    /**
     * The check could not run at all: no manifest, no signature, no configured key.
     *
     * Also the value a row is opened with, before the run has a verdict. A run that is
     * killed, or whose host disappears, therefore leaves behind a row that is already
     * telling the truth, and nothing has to sweep up after it.
     */
    public const RESULT_UNVERIFIABLE = 3;

    /**
     * The reason a row is opened with: what an UNVERIFIABLE row says when nothing ever
     * closed it — still running, or killed before it could report.
     */
    public const REASON_NOT_REPORTED = 'The run started and has not reported a result: it is still running, or it was stopped before it could finish.';

    /** @var array<int, string> */
    public const RESULTS = [
        self::RESULT_PASSED => 'passed',
        self::RESULT_FAILED => 'failed',
        self::RESULT_UNVERIFIABLE => 'unverifiable',
    ];

    protected $table = 'app_integrity_check_instances';

    /**
     * created_at is set explicitly when the row is opened, and it is the moment the run
     * STARTED — the one write that follows only settles `result`. There is no updated_at
     * because that second write is the same run finishing, not a later edit: the pair is
     * one event, and a column recording when it landed would invite reading the row as
     * something that can legitimately change afterwards. It cannot.
     */
    public $timestamps = false;

    protected $fillable = [
        'app_integrity_check_id',
        'instance_id',
        'result',
        'details',
        'created_at',
    ];

    protected $casts = [
        'app_integrity_check_id' => 'integer',
        'result' => 'integer',
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(AppIntegrityCheck::class, 'app_integrity_check_id');
    }

    /**
     * Open this instance's row in $check, before anything is verified — as UNVERIFIABLE,
     * see the constant. The one path every run records through, so they cannot drift apart.
     */
    public static function openIn(AppIntegrityCheck $check, string $instanceId): self
    {
        return self::create([
            'app_integrity_check_id' => $check->id,
            'instance_id' => $instanceId,
            'result' => self::RESULT_UNVERIFIABLE,
            'details' => self::unverifiable(self::REASON_NOT_REPORTED),
            'created_at' => now(),
        ]);
    }

    /**
     * Open this instance's row in a check of its own, for a run that verifies only the
     * instance it runs on.
     */
    public static function openFor(int $trigger, string $instanceId, ?int $createdBy = null): self
    {
        return self::openIn(AppIntegrityCheck::openFor($trigger, $createdBy), $instanceId);
    }

    /**
     * Close the row with its verdict and, for anything but a pass, what explains it:
     * VerificationReport::failureRecord() for a mismatch, unverifiable() for a run that
     * could not reach one.
     *
     * @param array<string, mixed>|null $details
     */
    public function close(int $result, ?array $details = null): void
    {
        try {
            $this->update(['result' => $result, 'details' => $details]);
        } catch (Throwable $e) {
            $this->explainUnrecorded($result, $e);

            throw $e;
        }
    }

    /**
     * Best effort, after close() could not write its verdict: leave the row UNVERIFIABLE
     * but saying which verdict was lost and why, instead of the opening reason. Whatever
     * broke the first write may break this one too — then the row keeps what it had, and
     * the caller still logs the original error.
     */
    private function explainUnrecorded(int $result, Throwable $e): void
    {
        $reason = sprintf(
            'The run reached a verdict (%s) but it could not be recorded: %s',
            self::RESULTS[$result] ?? (string) $result,
            $e->getMessage()
        );

        try {
            $this->update(['result' => self::RESULT_UNVERIFIABLE, 'details' => self::unverifiable($reason)]);
        } catch (Throwable $ignored) {
            // Keep the model saying what the database still says.
            $this->setRawAttributes($this->getRawOriginal(), true);
        }
    }

    /**
     * The details of a run that could not verify at all.
     *
     * @return array{reason: string}
     */
    public static function unverifiable(string $reason): array
    {
        return ['reason' => $reason];
    }

    public function resultLabel(): string
    {
        return self::RESULTS[$this->result] ?? 'unknown';
    }

    public function passed(): bool
    {
        return $this->result === self::RESULT_PASSED;
    }
}
