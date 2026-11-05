<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Crypto;

/**
 * One key on the ring, in both representations a consumer may need.
 *
 * raw()     — the key bytes: base64-decoded when the ring was built with
 *             decodeBase64 = true, otherwise exactly as configured. Used by
 *             RingEncrypter and CompactCrypt.
 * literal() — the configured string, including any "base64:" prefix. Used by
 *             helpers that hash the configured APP_KEY string as a password
 *             (Betmaker's custom_encrypt()) and by KeyRingSignature. Using
 *             raw() there would silently change every signature.
 */
final class KeyRingEntry
{
    private string $kid;

    private string $raw;

    private string $literal;

    public function __construct(string $kid, string $raw, string $literal)
    {
        $this->kid = $kid;
        $this->raw = $raw;
        $this->literal = $literal;
    }

    public function kid(): string
    {
        return $this->kid;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function literal(): string
    {
        return $this->literal;
    }
}
