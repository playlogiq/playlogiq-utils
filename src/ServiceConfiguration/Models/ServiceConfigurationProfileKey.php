<?php

namespace PlaylogiqUtils\ServiceConfiguration\Models;

use Illuminate\Database\Eloquent\Model;
use PlaylogiqUtils\Crypto\CompactCrypt;
use PlaylogiqUtils\ServiceConfiguration\Casts\EncryptsSchemaSecrets;
use PlaylogiqUtils\ServiceConfiguration\SecretMask;
use RuntimeException;

/**
 * One key/value of a profile; values of keys the provider's configSchema() marks secret are
 * encrypted at rest (PQPL-7443, PQPL-7651). Setting `value` needs the profile: fill `scp_id`
 * or setRelation('scp', $profile) (an unsaved one too) first. Logging and auditing of key
 * changes belong to the application's subclass.
 */
class ServiceConfigurationProfileKey extends Model
{
    protected $table = 'service_configuration_profile_keys';

    protected $fillable = ['scp_id', 'key', 'value'];

    protected $casts = [
        'value' => EncryptsSchemaSecrets::class,
    ];

    /** @var class-string<ServiceConfigurationProfile> overridden by subclasses with their own profile model */
    protected static $profileModel = ServiceConfigurationProfile::class;

    public function scp()
    {
        return $this->belongsTo(static::$profileModel, 'scp_id');
    }

    /** @throws RuntimeException when there is no key name, no profile, or its implementation can't be resolved */
    public function isSecret(): bool
    {
        $scp = $this->scp;

        if ($this->key === null || $scp === null) {
            throw new RuntimeException('Profile key ' . ($this->key ?? '(unnamed)') . ' needs its key and profile to tell whether it is secret.');
        }

        return isset($scp->secretKeys()[(string) $this->key]);
    }

    /** Masks a value of this key when it is secret or stored encrypted, e.g. for logs and audit rows (PQPL-6313). */
    public function maskIfSecret($value)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        // Reads decrypt every stored payload, so one the schema no longer marks secret is masked too.
        foreach ([$this->getRawOriginal('value'), $this->getAttributes()['value'] ?? null] as $raw) {
            if (is_string($raw) && (CompactCrypt::isCompactPayload($raw) || CompactCrypt::isLaravelCryptPayload($raw))) {
                return SecretMask::mask((string) $value);
            }
        }
        try {
            $secret = $this->isSecret();
        } catch (RuntimeException $e) {
            $secret = true; // unresolvable: mask rather than risk logging a secret
        }

        return $secret ? SecretMask::mask((string) $value) : $value;
    }
}
