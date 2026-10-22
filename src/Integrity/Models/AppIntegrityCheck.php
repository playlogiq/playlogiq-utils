<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * PQPL-5432 GLI-33 §2.3: one control-program check, and the instances it verified.
 *
 * The header: what asked for the check, who, and when — what every instance in it has in
 * common. Each instance's own verdict is an AppIntegrityCheckInstance.
 *
 * A check is started once for the whole fleet — by the scheduler or by the backoffice
 * button — through DispatchFleetIntegrityCheck, which claims the header by its group key
 * and sends it to every instance over SSM; each instance then records its row under it.
 * A bare `integrity:verify` run by hand verifies the one instance it runs on, and gets a
 * header of its own — see openFor().
 *
 * The check's result is not stored. Instances of a scheduled check keep arriving for as
 * long as the fleet is reporting, so any stored aggregate would be stale the moment the
 * next one landed; it is derived from the instances instead — see result().
 *
 * The values of `trigger` live here rather than in the schema, for the same reason the
 * results live on the instance: the set is open, and a number once assigned is never
 * reused, because rows already written carry its old meaning.
 */
class AppIntegrityCheck extends Model
{
    /** The daily fleet check (04:00 UTC), started by the scheduler through DispatchFleetIntegrityCheck. */
    public const TRIGGER_SCHEDULED = 1;

    /** `integrity:verify` run by hand, or the backoffice check. */
    public const TRIGGER_MANUAL = 2;

    /** The deployment gate, before the release is allowed onto the host. */
    public const TRIGGER_DEPLOY = 3;

    /**
     * For rendering and for validating an incoming value. Keys are the stored smallint;
     * the labels are plain English because this is operator- and auditor-facing, not a
     * player-facing string.
     *
     * @var array<int, string>
     */
    public const TRIGGERS = [
        self::TRIGGER_SCHEDULED => 'scheduled',
        self::TRIGGER_MANUAL => 'manual',
        self::TRIGGER_DEPLOY => 'deploy',
    ];

    protected $table = 'app_integrity_checks';

    /**
     * created_at is when the check was opened — for a scheduled one, by the first instance
     * to report in that hour. Nothing on the header changes after that.
     */
    public $timestamps = false;

    protected $fillable = [
        'trigger',
        'created_by',
        'group_key',
        'ssm_command_id',
        'details',
        'created_at',
    ];

    protected $casts = [
        'trigger' => 'integer',
        'created_by' => 'integer',
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function instances(): HasMany
    {
        return $this->hasMany(AppIntegrityCheckInstance::class, 'app_integrity_check_id');
    }

    /** The backoffice user model: integrity.models.user, else the default auth provider's. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            (string) config('integrity.models.user', config('auth.providers.users.model')),
            'created_by'
        );
    }

    /**
     * A header of its own, for a run that verifies only the instance it runs on.
     */
    public static function openFor(int $trigger, ?int $createdBy = null, ?Carbon $at = null): self
    {
        return self::create([
            'trigger' => $trigger,
            'created_by' => $createdBy,
            'group_key' => null,
            'created_at' => $at ?? now(),
        ]);
    }

    /**
     * Open the header for $key, or null when it is already taken.
     *
     * The unique index on group_key decides, not a read-then-write: of two callers racing
     * for the same key exactly one insert lands, and only that caller gets a header back.
     * That is what keeps the scheduled check to one per hour even if two schedulers fire,
     * and what lets a caller that generated the key find the header the job opened.
     */
    public static function claim(string $key, int $trigger, ?int $createdBy = null): ?self
    {
        $inserted = self::query()->insertOrIgnore([
            'trigger' => $trigger,
            'created_by' => $createdBy,
            'group_key' => $key,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return null;
        }

        return self::query()->where('group_key', $key)->firstOrFail();
    }

    /**
     * The hour slot a scheduled check belongs to. The migration that introduced headers
     * keys historical rows the same way.
     */
    public static function scheduledGroupKey(Carbon $at): string
    {
        return 'scheduled:' . $at->format('Y-m-d H');
    }

    /**
     * Why the check could not reach the fleet, or null when it did — or when nothing went
     * wrong that the check itself could see. Same shape as an instance's unverifiable().
     */
    public function reason(): ?string
    {
        $reason = ((array) $this->details)['reason'] ?? null;

        return $reason === null ? null : (string) $reason;
    }

    public function triggerLabel(): string
    {
        return self::TRIGGERS[$this->trigger] ?? 'unknown';
    }

    /**
     * The check's verdict, from its instances: failed if any instance failed, otherwise
     * unverifiable if any could not verify — or if none has reported at all, because a
     * check with no instances has shown nothing — and passed only when every one did.
     *
     * Instances report in over the minute or so after the command is sent, so a check
     * that has only just started reads as unverifiable until they arrive. That is the
     * honest reading of a check that has not yet shown anything.
     *
     * Reads the loaded relation; eager-load `instances` when listing.
     */
    public function result(): int
    {
        $results = $this->instances->pluck('result')->map(function ($result) {
            return (int) $result;
        });

        if ($results->contains(AppIntegrityCheckInstance::RESULT_FAILED)) {
            return AppIntegrityCheckInstance::RESULT_FAILED;
        }

        if ($results->isEmpty() || $results->contains(function ($result) {
            return $result !== AppIntegrityCheckInstance::RESULT_PASSED;
        })) {
            return AppIntegrityCheckInstance::RESULT_UNVERIFIABLE;
        }

        return AppIntegrityCheckInstance::RESULT_PASSED;
    }

    public function resultLabel(): string
    {
        return AppIntegrityCheckInstance::RESULTS[$this->result()] ?? 'unknown';
    }

    /**
     * The same rule as result(), as a query, so the listing can filter on the verdict
     * and still paginate and count in SQL.
     */
    public function scopeWhereResult(Builder $query, int $result): Builder
    {
        $failed = function ($q) {
            $q->where('result', AppIntegrityCheckInstance::RESULT_FAILED);
        };
        $notPassed = function ($q) {
            $q->where('result', '<>', AppIntegrityCheckInstance::RESULT_PASSED);
        };

        switch ($result) {
            case AppIntegrityCheckInstance::RESULT_FAILED:
                return $query->whereHas('instances', $failed);

            case AppIntegrityCheckInstance::RESULT_PASSED:
                return $query->has('instances')->whereDoesntHave('instances', $notPassed);

            case AppIntegrityCheckInstance::RESULT_UNVERIFIABLE:
                return $query->whereDoesntHave('instances', $failed)
                    ->where(function ($q) use ($notPassed) {
                        $q->doesntHave('instances')->orWhereHas('instances', $notPassed);
                    });

            default:
                return $query->whereRaw('1 = 0');
        }
    }
}
