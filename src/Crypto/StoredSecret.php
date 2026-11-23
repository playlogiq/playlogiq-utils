<?php

namespace PlaylogiqUtils\Crypto;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How an encrypted column value is written and read, shared by every encrypting cast
 * (the SCP key cast here, betmaker's settings casts). Taken out of the former
 * EncryptsSpecificKeys cast (PQPL-7651), behaviour unchanged.
 */
class StoredSecret
{
    /**
     * Encrypts a value to store, leaving empty values and already-encrypted blobs as they are.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function encrypt($value)
    {
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

    /**
     * Decrypts a stored value; one that is not an encrypted payload comes back unchanged.
     *
     * @param mixed $value
     * @param array<string, mixed> $context where the value comes from, for the warning log,
     *                                      e.g. ['model' => ..., 'attribute' => ..., 'setting_key' => ...]
     * @return mixed
     */
    public static function decrypt($value, array $context = [])
    {
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
                Log::warning('EncryptsSpecificKeys: could not decrypt stored secret', $context + [
                    'kid' => CompactCrypt::kidOf((string) $value),
                    'reason' => $e->getMessage(),
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
}
