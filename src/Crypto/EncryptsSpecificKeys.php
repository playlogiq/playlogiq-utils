<?php
namespace PlaylogiqUtils\Crypto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eloquent cast for key/value tables: encrypts the value only when the row's key names a
 * secret (the defaults below plus the model's own SECRET_KEYS constant).
 *
 * Moved from betmaker's App\Casts\EncryptsSpecificKeys (PQPL-7443); betmaker now uses it directly.
 * Everything goes through static:: so a subclass can extend $defaultSecretKeys.
 */
class EncryptsSpecificKeys implements CastsAttributes {

    // Defaults that apply to ANY model using this cast.
    // Each model can extend this list via its own `SECRET_KEYS` constant.
    protected static array $defaultSecretKeys = [
        'private_key',
        'app_key',
        'app_secret',
        'api_token',
        'api_signature',
        'webhook_secret_bets',
        'webhook_secret_payments',
        'secret',
        'secret_key',
        'sendinblue_api_key',
        'kra_api_key',
        'poker_provider_secret_key',
        'poker_betmaker_secret_key',
    ];

    /**
     * Resolve the effective secret-key list — defaults merged with the model's
     * own `SECRET_KEYS` constant when defined.
     *
     * @param object|class-string|null $model
     */
    public static function secretKeysFor($model = null): array
    {
        if ($model === null) {
            return static::$defaultSecretKeys;
        }
        $class = is_object($model) ? get_class($model) : $model;
        if (!defined($class . '::SECRET_KEYS')) {
            return static::$defaultSecretKeys;
        }
        return array_values(array_unique(array_merge(
            static::$defaultSecretKeys,
            (array) constant($class . '::SECRET_KEYS')
        )));
    }

    /**
     * @param object|class-string|null $model
     */
    public static function isSecretKey(?string $key, $model = null): bool
    {
        return $key !== null && in_array($key, static::secretKeysFor($model), true);
    }

    /**
     * Runs automatically when reading data: $model->value
     */
    public function get($model, string $key, $value, array $attributes) {
        $targetKey = $attributes['key'] ?? $attributes['setting'] ?? $attributes['s_key'] ?? $model->key ?? $model->setting ?? $model->s_key ?? null;
        if (static::isSecretKey($targetKey, $model)) {
            if (empty($value)) return $value;

            // Compact format ("c1:" / "c2:"). Single decrypt — fits in VARCHAR(255).
            if (CompactCrypt::isCompactPayload((string) $value)) {
                try {
                    return CompactCrypt::decrypt((string) $value);
                } catch (Throwable $e) {
                    // Returning the raw payload is long-standing behaviour that
                    // callers rely on, but it must not be silent: during a key
                    // rotation this is how a provider ends up being handed a
                    // base64 blob as its API key. Never log the value itself.
                    Log::warning('EncryptsSpecificKeys: could not decrypt stored secret', [
                        'model'       => get_class($model),
                        'attribute'   => $key,
                        'setting_key' => $targetKey,
                        'kid'         => CompactCrypt::kidOf((string) $value),
                        'reason'      => $e->getMessage(),
                    ]);

                    return $value;
                }
            }

            // Legacy Laravel Crypt rows. Peel up to 5 layers to tolerate
            // legacy double-encrypted rows from the historical migration bug.
            $current = $value;
            $layers = 0;
            while ($layers < 5) {
                try {
                    $current = Crypt::decryptString($current);
                    $layers++;
                } catch (DecryptException $e) {
                    break;
                }
            }

            if ($layers === 0) {
                return $value;
            }

            return $current;
        }

        return $value;
    }

    /**
     * Runs automatically when writing data: $model->value = 'secret';
     */
    public function set($model, string $key, $value, array $attributes) {
        $targetKey = $attributes['key'] ?? $attributes['setting'] ?? $attributes['s_key'] ?? $model->key ?? $model->setting ?? $model->s_key ?? null;
        if (static::isSecretKey($targetKey, $model)) {
            if (empty($value)) return $value;

            $stringValue = (string) $value;

            // If the caller resubmitted an already-encrypted blob (e.g. UI
            // round-trip of a masked field), keep it as-is to avoid wrapping.
            if (CompactCrypt::isCompactPayload($stringValue)) {
                return $stringValue;
            }
            try {
                Crypt::decryptString($stringValue);
                return $stringValue;
            } catch (DecryptException $e) {
                // not encrypted — fall through and encrypt
            }

            return CompactCrypt::encrypt($stringValue);
        }

        return $value;
    }
}
