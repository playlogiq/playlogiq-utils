<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Crypto;

/**
 * HMAC integrity signatures over permanent records.
 *
 * Signing always uses the current key; verification tries every key on the
 * ring. Historical signatures are never re-signed under a new key: doing so
 * would demonstrate that the application can rewrite them at will, which is
 * exactly what the signature exists to rule out. The practical consequence is
 * that a retired key stays in APP_KEYS_OLD as verification-only material for
 * as long as the records signed under it must remain verifiable.
 */
class KeyRingSignature
{
    private AppKeyRing $ring;

    public function __construct(AppKeyRing $ring)
    {
        $this->ring = $ring;
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->ring->current()->literal());
    }

    public function verify(string $payload, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }

        foreach ($this->ring->all() as $entry) {
            if (hash_equals(hash_hmac('sha256', $payload, $entry->literal()), $expected)) {
                return true;
            }
        }

        return false;
    }
}
