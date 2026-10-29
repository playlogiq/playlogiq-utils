<?php

namespace PlaylogiqUtils\ServiceConfiguration\Models;

use Illuminate\Database\Eloquent\Model;
use PlaylogiqUtils\Crypto\EncryptsSpecificKeys;
use PlaylogiqUtils\ServiceConfiguration\SecretMask;

/**
 * One key/value of a profile; secret values are encrypted at rest by EncryptsSpecificKeys
 * (PQPL-7443). Logging and auditing of key changes belong to the application's subclass.
 */
class ServiceConfigurationProfileKey extends Model
{
    /**
     * Extra secret SCP key names. Merged with EncryptsSpecificKeys::$defaultSecretKeys
     * at runtime. Covers SCP integration schemas declared as `x-secret` / `password`.
     */
    const SECRET_KEYS = [
        'api_key',
        'api_secret',
        'api_token',
        'secret_key',
        'clientSecret',
        'password',
        'token',
    ];

    protected $table = 'service_configuration_profile_keys';

    protected $fillable = ['scp_id', 'key', 'value'];

    protected $casts = [
        'value' => EncryptsSpecificKeys::class,
    ];

    /** Masks a secret key's value, e.g. for logs and audit rows (PQPL-6313). */
    public static function maskIfSecret($key, $value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (EncryptsSpecificKeys::isSecretKey((string) $key, static::class)) {
            return SecretMask::mask((string) $value);
        }

        return $value;
    }
}
