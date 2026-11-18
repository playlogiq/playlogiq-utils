<?php

namespace PlaylogiqUtils\ServiceConfiguration\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use PlaylogiqUtils\Crypto\StoredSecret;

/**
 * The value cast of a profile key (PQPL-7651): a value is encrypted when the provider's
 * configSchema() marks its key secret, never by key name.
 */
class EncryptsSchemaSecrets implements CastsAttributes
{
    /**
     * Any stored payload is decrypted: keys once secret, or of providers no longer in code,
     * still hold encrypted rows, and reading needs no profile.
     */
    public function get($model, string $key, $value, array $attributes)
    {
        return StoredSecret::decrypt($value, ['model' => get_class($model), 'attribute' => $key, 'setting_key' => $attributes['key'] ?? null]);
    }

    /** Throws when the key's profile can't be resolved, so a secret is never stored in clear. */
    public function set($model, string $key, $value, array $attributes)
    {
        if (empty($value)) {
            return $value; // StoredSecret::encrypt() keeps empty values as they are, so clearing needs no profile
        }

        return $model->isSecret() ? StoredSecret::encrypt($value) : $value;
    }
}
