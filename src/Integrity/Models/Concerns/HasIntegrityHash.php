<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Models\Concerns;

/**
 * Adds an `integrity_hash` (sha256) column auto-computed at row creation.
 *
 * The hash covers all the model's attributes EXCEPT the ones listed in
 * integrityHashExcludedFields() — at minimum the hash itself and the
 * autoincrement id. Override that method on the model to exclude more.
 *
 * JSON-cast attributes are normalized (decode + recursive ksort + re-encode)
 * so the hash survives MySQL's JSON storage round-trip, which can reformat
 * the raw JSON string (added spaces, key reordering).
 *
 * Verify a previously persisted row with $model->verifyIntegrity().
 */
trait HasIntegrityHash
{
    public static function bootHasIntegrityHash(): void
    {
        static::creating(function ($model) {
            if (empty($model->integrity_hash)) {
                $model->integrity_hash = $model->generateIntegrityHash();
            }
        });
    }

    /**
     * Columns excluded from the hash calculation.
     */
    public function integrityHashExcludedFields(): array
    {
        return ['integrity_hash', 'id'];
    }

    public function generateIntegrityHash(): string
    {
        $excluded = $this->integrityHashExcludedFields();
        $normalized = [];

        foreach ($this->getAttributes() as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }
            $normalized[$key] = $this->normalizeForIntegrityHash($key, $value);
        }

        ksort($normalized);

        $data = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $data);
    }

    public function verifyIntegrity(): bool
    {
        if (empty($this->integrity_hash)) {
            return false;
        }

        return hash_equals($this->integrity_hash, $this->generateIntegrityHash());
    }

    protected function normalizeForIntegrityHash(string $key, $value)
    {
        if ($value === null) {
            return null;
        }

        $casts = $this->getCasts();
        $cast  = $casts[$key] ?? null;

        if (in_array($cast, ['array', 'json', 'object', 'collection'], true)) {
            $decoded = is_string($value) ? json_decode($value, true) : $value;
            if (is_array($decoded)) {
                $this->ksortRecursive($decoded);
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $value;
    }

    protected function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
