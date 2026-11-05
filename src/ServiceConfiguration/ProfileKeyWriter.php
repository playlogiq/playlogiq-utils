<?php

namespace PlaylogiqUtils\ServiceConfiguration;

use PlaylogiqUtils\ServiceConfiguration\Models\ServiceConfigurationProfile;

/**
 * Saves the submitted keys of a profile, moved from betmaker's controller (PQPL-7443).
 * plan() decides, persist() applies, so the decision is testable without a database.
 */
class ProfileKeyWriter
{
    /** @var SchemaResolver */
    protected $resolver;

    public function __construct(SchemaResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param array<string, mixed> $submitted key => value as posted, e.g. (array) $request->keys
     */
    public function persist(ServiceConfigurationProfile $scp, array $submitted): void
    {
        $plan = static::plan(
            $this->resolver->resolve($scp),
            $submitted,
            $scp->keys->pluck('value', 'key')->all()
        );

        $keyModel = $scp::keyModel();

        foreach ($plan['upsert'] as $key => $value) {
            $keyModel::updateOrCreate(
                ['scp_id' => $scp->id, 'key' => $key],
                ['value' => $value]
            );
        }

        $deleteQuery = $keyModel::where('scp_id', $scp->id);

        if (!empty($plan['keep'])) {
            $deleteQuery->whereNotIn('key', $plan['keep']);
        }

        // One by one, so each key's deleted event fires (betmaker audits them).
        foreach ($deleteQuery->get() as $row) {
            $row->delete();
        }
    }

    /**
     * Which keys to write and which stored keys survive; every other stored key is deleted.
     * Only schema keys are accepted. A secret that is empty, shaped like any mask, or equal
     * to the current value's mask is never a real edit, so its stored value is kept
     * (PQPL-7002). Untouched secrets are kept, never treated as removed keys.
     *
     * @param array<string, mixed> $current the stored values (decrypted), key => value
     * @return array{upsert: array<string, mixed>, keep: array<int, string>}
     */
    public static function plan(array $schema, array $submitted, array $current): array
    {
        $allowedKeys = array_flip(array_map(function ($f) {
            return $f['key'];
        }, $schema));
        $secretKeys = SecretMask::secretKeysOf($schema);

        $upsert = [];
        $keep = [];

        foreach ($submitted as $key => $value) {
            if (!isset($allowedKeys[$key])) {
                continue;
            }

            $keep[] = $key;

            if (isset($secretKeys[$key])) {
                $currentValue = $current[$key] ?? null;

                if ($value === ''
                    || SecretMask::looksMasked((string) $value)
                    || ($currentValue !== null && $currentValue !== '' && $value === SecretMask::mask((string) $currentValue))
                ) {
                    continue;
                }
            }

            $upsert[$key] = $value;
        }

        foreach (array_keys($secretKeys) as $key) {
            if (!in_array($key, $keep, true)) {
                $keep[] = $key;
            }
        }

        return ['upsert' => $upsert, 'keep' => $keep];
    }
}
