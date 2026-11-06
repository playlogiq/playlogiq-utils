<?php

namespace PlaylogiqUtils\ServiceConfiguration\Models;

use Illuminate\Database\Eloquent\Model;
use PlaylogiqUtils\Crypto\EncryptsSpecificKeys;
use PlaylogiqUtils\ServiceConfiguration\ConfigKeys;
use PlaylogiqUtils\ServiceConfiguration\SecretMask;
use PlaylogiqUtils\ServiceConfiguration\TypeRegistry;

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

    /**
     * scp_id => declaredSecretKeys(), per process (PQPL-7470); cleared when the profile is saved.
     * shortcut: stale in long-lived workers and for changes made by another process; upgrade
     * (TTL, or no cache in console) if profile type/code start changing after creation.
     *
     * @var array<int|string, string[]|null>
     */
    protected static $declaredSecretKeysByScp = [];

    protected static function booted()
    {
        // value may have been assigned before scp_id was (keys()->create(), fill order): re-run
        // the cast now, reading through get() so an already-encrypted value is not re-wrapped.
        static::saving(function (ServiceConfigurationProfileKey $row) {
            if ($row->isDirty('value')) {
                $row->value = $row->value;
            }
        });
    }

    public function profile()
    {
        return $this->belongsTo(ServiceConfigurationProfile::class, 'scp_id');
    }

    /**
     * PQPL-7470: the `secrets` of the profile implementation's $config_keys when it declares
     * both `secrets` and `non_secrets`; null (no profile, no class, other shape) keeps the
     * cast's unified list. Without the loaded relation, one query per scp_id per process.
     */
    public function declaredSecretKeys(): ?array
    {
        $scpId = $this->attributes['scp_id'] ?? null;
        if ($scpId === null) {
            return null;
        }
        $profile = $this->relationLoaded('profile') ? $this->getRelation('profile') : null;
        if ($profile !== null && (string) $profile->getKey() === (string) $scpId) {
            return static::declaredSecretKeysOf($profile);
        }

        return static::declaredSecretKeysForScp($scpId);
    }

    /** declaredSecretKeys() by profile id, through the per-process cache. */
    public static function declaredSecretKeysForScp($scpId): ?array
    {
        if ($scpId === null) {
            return null;
        }
        if (!array_key_exists($scpId, static::$declaredSecretKeysByScp)) {
            // Write PDO: betmaker's read/write split has no `sticky`, a new profile may not be on the replica yet.
            $profile = ServiceConfigurationProfile::on((new static())->getConnectionName())
                ->useWritePdo()->find($scpId, ['type', 'code']);
            if ($profile === null) {
                return null; // not cached: the profile may exist on the next call
            }
            static::$declaredSecretKeysByScp[$scpId] = static::declaredSecretKeysOf($profile);
        }

        return static::$declaredSecretKeysByScp[$scpId];
    }

    /** Called when a profile is saved, as its type/code may have changed. */
    public static function forgetDeclaredSecretKeys($scpId): void
    {
        unset(static::$declaredSecretKeysByScp[$scpId]);
    }

    protected static function declaredSecretKeysOf(?ServiceConfigurationProfile $profile): ?array
    {
        if ($profile === null || $profile->type === null) {
            return null;
        }
        $class = TypeRegistry::resolveImplementationClass((int) $profile->type, $profile->code);

        return $class === null ? null : (ConfigKeys::of($class)['secrets'] ?? null);
    }

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

    /**
     * maskIfSecret() under the row's profile rule (PQPL-7470): with structured $config_keys only
     * its declared secrets are masked, otherwise (or with no $scpId) maskIfSecret() decides.
     * A separate method: a third parameter on maskIfSecret() would break subclasses overriding it.
     */
    public static function maskIfSecretForScp($scpId, $key, $value)
    {
        $declared = static::declaredSecretKeysForScp($scpId);
        if ($declared === null) {
            return static::maskIfSecret($key, $value);
        }
        if ($value === null || $value === '' || !in_array((string) $key, $declared, true)) {
            return $value;
        }

        return SecretMask::mask((string) $value);
    }
}
