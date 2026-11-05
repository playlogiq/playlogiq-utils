<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Models;

use PlaylogiqUtils\Integrity\CriticalSettingsGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GLI-33 §2.3: one Settings Integrity Check — a skin's live certified settings, or the
 * global ones (skin_id null), compared against the newest snapshot, by somebody, at a moment.
 *
 * Written through SettingsIntegrityCheckLog and by nothing else — by the button
 * (the application's own check service), by the daily check of every regulated skin
 * (ScheduledSettingsIntegrityChecks::run()) and by the guard in front of every save of a
 * skin's critical settings (CriticalSettingsGuard::check()). A row is never changed afterwards.
 * The status and trigger values live here rather than in the schema, and a number once
 * assigned is never reused, because rows already written carry its old meaning.
 */
class SettingsIntegrityCheck extends Model
{
    /** A save of the skin's critical settings, compared before it was written. */
    public const TRIGGER_CONFIGURATION_CHANGE = 1;

    /** The backoffice check: the button on the report page or on the skin's GLI settings tab. */
    public const TRIGGER_MANUAL = 2;

    /** The daily check of every GLI-regulated skin (04:00 UTC), RunScheduledSettingsIntegrityChecks. */
    public const TRIGGER_SCHEDULED = 3;

    /**
     * For rendering and for validating an incoming value. Plain English, like
     * AppIntegrityCheck::TRIGGERS: operator- and auditor-facing.
     *
     * @var array<int, string>
     */
    public const TRIGGERS = [
        self::TRIGGER_CONFIGURATION_CHANGE => 'configuration change',
        self::TRIGGER_MANUAL               => 'manual',
        self::TRIGGER_SCHEDULED            => 'scheduled',
    ];

    public const STATUS_MATCHES       = 1;
    public const STATUS_DRIFT         = 2;
    public const STATUS_NO_BASELINE   = 3;
    public const STATUS_NOT_REGULATED = 4;
    public const STATUS_DISABLED      = 5;
    public const STATUS_FAILED        = 6;

    /**
     * Stored value => the guard's outcome. The labels are the guard's own words, so the
     * listing, the modal and the significant event log say the same thing.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_MATCHES       => CriticalSettingsGuard::CHECK_MATCHES,
        self::STATUS_DRIFT         => CriticalSettingsGuard::CHECK_DRIFT,
        self::STATUS_NO_BASELINE   => CriticalSettingsGuard::CHECK_NO_BASELINE,
        self::STATUS_NOT_REGULATED => CriticalSettingsGuard::CHECK_NOT_REGULATED,
        self::STATUS_DISABLED      => CriticalSettingsGuard::CHECK_DISABLED,
        self::STATUS_FAILED        => CriticalSettingsGuard::CHECK_FAILED,
    ];

    protected $table = 'settings_integrity_checks';

    public $timestamps = false;

    protected $fillable = [
        'skin_id',
        'trigger',
        'status',
        'compared_to',
        'drift',
        'error',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'skin_id' => 'integer',
        'trigger' => 'integer',
        'status' => 'integer',
        'drift' => 'array',
        'created_by' => 'integer',
        'created_at' => 'datetime',
    ];

    /** The application's skin model, integrity.models.skin. */
    public function skin(): BelongsTo
    {
        return $this->belongsTo((string) config('integrity.models.skin'), 'skin_id');
    }

    /** The backoffice user model: integrity.models.user, else the default auth provider's. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            (string) config('integrity.models.user', config('auth.providers.users.model')),
            'created_by'
        );
    }

    /** The stored value for one of the guard's CHECK_* outcomes; unknown ones are failures. */
    public static function statusFor(string $outcome): int
    {
        $status = array_search($outcome, self::STATUSES, true);

        return $status === false ? self::STATUS_FAILED : (int) $status;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'unknown';
    }

    public function triggerLabel(): string
    {
        return self::TRIGGERS[$this->trigger] ?? 'unknown';
    }
}
