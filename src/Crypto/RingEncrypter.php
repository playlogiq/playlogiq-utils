<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Crypto;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * The framework encrypter, widened to decrypt under every key on the ring.
 *
 * Covers encrypted cookies, the session payload, the X-XSRF-TOKEN header and
 * the Crypt facade. Encryption is untouched and always uses the current key,
 * so nothing is re-issued at rotation time — sessions and cookies simply age
 * off the old key as they expire.
 *
 * Laravel payloads carry no key id, but they are MAC'd: a wrong key fails the
 * MAC check rather than returning garbage, so trying each key is safe.
 *
 * The parent class is not final and $key is protected, so this needs no
 * framework patching; the application swaps the "encrypter" binding.
 */
class RingEncrypter extends Encrypter
{
    private AppKeyRing $ring;

    public function __construct(AppKeyRing $ring, string $cipher)
    {
        foreach ($ring->all() as $entry) {
            if (!static::supported($entry->raw(), $cipher)) {
                throw new RuntimeException(
                    'Key "' . $entry->kid() . '" has the wrong length for cipher ' . $cipher . '.'
                );
            }
        }

        parent::__construct($ring->current()->raw(), $cipher);

        $this->ring = $ring;
    }

    public function decrypt($payload, $unserialize = true)
    {
        $failure = null;

        foreach ($this->ring->all() as $entry) {
            try {
                return $this->withKey($entry->raw(), function () use ($payload, $unserialize) {
                    return parent::decrypt($payload, $unserialize);
                });
            } catch (DecryptException $e) {
                $failure = $e;
            }
        }

        throw $failure ?: new DecryptException('The payload could not be decrypted with any configured key.');
    }

    public function decryptString($payload)
    {
        return $this->decrypt($payload, false);
    }

    /**
     * Id of the ring key that can decrypt the payload, or null if none can.
     */
    public function kidFor(string $payload): ?string
    {
        foreach ($this->ring->all() as $entry) {
            try {
                $this->withKey($entry->raw(), function () use ($payload) {
                    return parent::decrypt($payload, false);
                });

                return $entry->kid();
            } catch (DecryptException $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return string[] raw bytes of every key on the ring, current first
     */
    public function allKeys(): array
    {
        return array_map(function (KeyRingEntry $entry) {
            return $entry->raw();
        }, $this->ring->all());
    }

    /**
     * Run $attempt with $this->key temporarily set to $key.
     *
     * The key is restored in a finally block: this object is a singleton, so
     * leaving it pointed at an old key would make every later encrypt() in
     * the process use it.
     *
     * @return mixed
     */
    private function withKey(string $key, callable $attempt)
    {
        $currentKey = $this->key;
        $this->key = $key;

        try {
            return $attempt();
        } finally {
            $this->key = $currentKey;
        }
    }
}
