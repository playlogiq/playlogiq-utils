<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use RuntimeException;

/**
 * Detached signing and verification of the integrity manifest.
 *
 * The signed bytes are exactly the bytes written to integrity-manifest.json — no
 * extract-then-re-encode step sits between signing and verification, so there is no
 * way for a canonicalisation difference to invalidate a genuine signature or, worse,
 * validate a forged one.
 *
 * The signature is a sidecar (integrity-manifest.json.sig) holding base64 rather than
 * raw bytes, because the file is tracked in git and must stay text-diffable.
 *
 * Key custody is the real control here, not this class: the private key exists only in
 * the pipeline (injected from Secrets Manager), and instances carry only the public key.
 * Nothing an instance holds lets it produce a signature, which is what makes
 * "no instance can re-baseline itself" true.
 *
 * Note on determinism: RSA (PKCS#1 v1.5) signatures are deterministic, so re-signing an
 * unchanged manifest reproduces identical sidecar bytes and a no-op diff. ECDSA is not —
 * it draws a random nonce per signature, so an EC key produces a fresh signature on every
 * run even when nothing changed. Both verify correctly; RSA simply keeps the tracked
 * sidecar quiet.
 */
class ManifestSigner
{
    /** Sidecar suffix, appended to the manifest path. */
    const SIGNATURE_SUFFIX = '.sig';

    const SIGNATURE_SCHEMA_VERSION = 1;

    /** Digest used under the signature. */
    const DIGEST_ALGORITHM = 'sha256';

    /**
     * Signs the manifest bytes and returns the sidecar document.
     *
     * @return array{signature_schema_version: int, algorithm: string, public_key_sha256: string, signature: string}
     */
    public static function sign(string $manifestBytes, string $privateKeyPem): array
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new RuntimeException(
                'Could not read the signing key as a PEM private key: ' . self::opensslErrors()
            );
        }

        $signature = '';

        if (!openssl_sign($manifestBytes, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing the manifest failed: ' . self::opensslErrors());
        }

        return [
            'signature_schema_version' => self::SIGNATURE_SCHEMA_VERSION,
            'algorithm'                => self::algorithmName($key),
            'public_key_sha256'        => self::publicKeyFingerprint($key),
            'signature'                => base64_encode($signature),
        ];
    }

    /**
     * Builds a sidecar around a signature produced somewhere else.
     *
     * In practice that somewhere else is AWS KMS, whose private key cannot be exported
     * and therefore can never be handed to sign() above. KMS asked for
     * RSASSA_PKCS1_V1_5_SHA_256 emits exactly the bytes openssl_sign() emits for the same
     * key and message, so the two paths are interchangeable: verify() cannot tell, and
     * does not need to, which one produced a given signature.
     *
     * The fingerprint is derived the same way as in sign() — a hash of OpenSSL's own
     * re-export of the public key — so it comes out identical whether it was computed
     * from the private half or the public half, and regardless of how the caller's PEM
     * happened to be line-wrapped.
     *
     * @param  string $signature raw signature bytes, already base64-decoded
     * @return array{signature_schema_version: int, algorithm: string, public_key_sha256: string, signature: string}
     */
    public static function sidecarFor(string $signature, string $publicKeyPem): array
    {
        if ($signature === '') {
            throw new RuntimeException('Refusing to build a sidecar around an empty signature.');
        }

        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            throw new RuntimeException(
                'Could not read the verification key as a PEM public key: ' . self::opensslErrors()
            );
        }

        return [
            'signature_schema_version' => self::SIGNATURE_SCHEMA_VERSION,
            'algorithm'                => self::algorithmName($key),
            'public_key_sha256'        => self::publicKeyFingerprint($key),
            'signature'                => base64_encode($signature),
        ];
    }

    /**
     * Verifies manifest bytes against a sidecar document.
     *
     * Deliberately re-derives everything from $manifestBytes: the sidecar carries no
     * digest of the manifest, so there is nothing an attacker could put in it that a
     * verifier might compare against instead of the real content.
     */
    public static function verify(string $manifestBytes, array $sidecar, string $publicKeyPem): bool
    {
        $encoded = $sidecar['signature'] ?? null;

        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Signature sidecar has no signature.');
        }

        $signature = base64_decode($encoded, true);

        if ($signature === false) {
            throw new RuntimeException('Signature sidecar is not valid base64.');
        }

        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            throw new RuntimeException(
                'Could not read the verification key as a PEM public key: ' . self::opensslErrors()
            );
        }

        $result = openssl_verify($manifestBytes, $signature, $key, OPENSSL_ALGO_SHA256);

        if ($result === -1) {
            throw new RuntimeException('Verifying the manifest signature failed: ' . self::opensslErrors());
        }

        return $result === 1;
    }

    /**
     * The sidecar path for a given manifest path.
     */
    public static function signaturePathFor(string $manifestPath): string
    {
        return $manifestPath . self::SIGNATURE_SUFFIX;
    }

    /**
     * Canonical bytes for the sidecar file, matching the manifest's encoding so both
     * artifacts diff the same way.
     */
    public static function encodeSidecar(array $sidecar): string
    {
        return ManifestBuilder::canonicalJson($sidecar);
    }

    /**
     * @param resource|object $key
     */
    private static function algorithmName($key): string
    {
        $details = openssl_pkey_get_details($key);
        $type    = is_array($details) ? ($details['type'] ?? null) : null;

        if ($type === OPENSSL_KEYTYPE_RSA) {
            return 'RSA-SHA256';
        }

        if ($type === OPENSSL_KEYTYPE_EC) {
            return 'ECDSA-SHA256';
        }

        if ($type === OPENSSL_KEYTYPE_DSA) {
            return 'DSA-SHA256';
        }

        throw new RuntimeException('Unsupported signing key type; use an RSA or EC key.');
    }

    /**
     * Fingerprint of the public half, so a verifier can tell which key signed and a
     * rotation shows up as a changed fingerprint rather than a bare failure.
     *
     * @param resource|object $key
     */
    private static function publicKeyFingerprint($key): string
    {
        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            throw new RuntimeException('Could not extract the public key from the signing key.');
        }

        return hash(self::DIGEST_ALGORITHM, $details['key']);
    }

    private static function opensslErrors(): string
    {
        $messages = [];

        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return $messages === [] ? 'no OpenSSL error detail available' : implode('; ', $messages);
    }
}
