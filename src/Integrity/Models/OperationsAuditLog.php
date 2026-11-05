<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PlaylogiqUtils\Integrity\Models\Concerns\HasIntegrityHash;

/**
 * PQPL-7324 GLI-33: operational actions on production, one row each. Append-only.
 *
 * The application provides the operations_audit_log migration, and extends this model for
 * anything of its own (betmaker's export).
 */
class OperationsAuditLog extends Model
{
    use HasIntegrityHash;

    public const EVENT_TINKER_COMMAND = 'tinker_command';

    /** Every event type written so far, in the order the report's filter offers them. */
    public const EVENT_TYPES = [self::EVENT_TINKER_COMMAND];

    protected $table = 'operations_audit_log';

    // Append-only: only created_at, no updated_at
    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'actor',
        'reference',
        'occurred_at',
        'details',
        'created_at',
        'integrity_hash',
    ];

    protected $casts = [
        'details'     => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    /**
     * The report's filters, shared by the grid and the export so both always select the same
     * rows. Keys: events (string[]), actor, command, session, date_from, date_to (Y-m-d H:i:s).
     */
    public static function applyFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['events'])) {
            $query->whereIn('event_type', $filters['events']);
        }
        if (!empty($filters['actor'])) {
            $query->where('actor', 'like', '%' . addcslashes($filters['actor'], '%_\\') . '%');
        }
        if (!empty($filters['command'])) {
            $query->where('details->command', 'like', '%' . addcslashes($filters['command'], '%_\\') . '%');
        }
        if (!empty($filters['session'])) {
            $query->where('details->ssm_session_id', $filters['session']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('occurred_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('occurred_at', '<=', $filters['date_to']);
        }

        return $query;
    }
}
