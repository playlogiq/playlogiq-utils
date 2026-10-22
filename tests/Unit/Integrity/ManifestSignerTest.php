<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\Integrity;

use PlaylogiqUtils\Integrity\ManifestSigner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Deliberately extends PHPUnit's TestCase, not Tests\TestCase: signing touches no
 * database, cache or container, and must stay runnable in a bare build container.
 *
 * Keys are generated per-test rather than committed as fixtures — a private key in the
 * repo is exactly what this scheme is designed to prevent.
 */
class ManifestSignerTest extends TestCase
{
    /** @var string */
    private $privateKey;

    /** @var string */
    private $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->privateKey, $this->publicKey] = $this->generateKeyPair();
    }

    public function test_a_signature_verifies_against_the_matching_public_key(): void
    {
        $bytes   = "{\n  \"release_version\": \"1.0.0\"\n}\n";
        $sidecar = ManifestSigner::sign($bytes, $this->privateKey);

        $this->assertTrue(ManifestSigner::verify($bytes, $sidecar, $this->publicKey));
    }

    public function test_a_modified_manifest_fails_verification(): void
    {
        $bytes   = "{\n  \"release_version\": \"1.0.0\"\n}\n";
        $sidecar = ManifestSigner::sign($bytes, $this->privateKey);

        $tampered = str_replace('1.0.0', '1.0.1', $bytes);

        $this->assertFalse(ManifestSigner::verify($tampered, $sidecar, $this->publicKey));
    }

    public function test_a_single_flipped_byte_fails_verification(): void
    {
        $bytes   = str_repeat('a', 512);
        $sidecar = ManifestSigner::sign($bytes, $this->privateKey);

        $this->assertFalse(
            ManifestSigner::verify(substr($bytes, 0, 511) . 'b', $sidecar, $this->publicKey)
        );
    }

    public function test_a_signature_from_a_different_key_fails_verification(): void
    {
        [$otherPrivate] = $this->generateKeyPair();

        $bytes   = "manifest bytes\n";
        $sidecar = ManifestSigner::sign($bytes, $otherPrivate);

        // The point of the scheme: holding *a* key is not enough, it must be *the* key.
        $this->assertFalse(ManifestSigner::verify($bytes, $sidecar, $this->publicKey));
    }

    public function test_a_tampered_signature_fails_verification(): void
    {
        $bytes   = "manifest bytes\n";
        $sidecar = ManifestSigner::sign($bytes, $this->privateKey);

        $raw            = base64_decode($sidecar['signature'], true);
        $raw[0]         = $raw[0] === "\x00" ? "\x01" : "\x00";
        $sidecar['signature'] = base64_encode($raw);

        $this->assertFalse(ManifestSigner::verify($bytes, $sidecar, $this->publicKey));
    }

    public function test_rsa_signing_is_deterministic_so_the_tracked_sidecar_stays_quiet(): void
    {
        $bytes = "manifest bytes\n";

        $this->assertSame(
            ManifestSigner::sign($bytes, $this->privateKey),
            ManifestSigner::sign($bytes, $this->privateKey)
        );
    }

    public function test_sidecar_records_algorithm_and_key_fingerprint(): void
    {
        $sidecar = ManifestSigner::sign("bytes\n", $this->privateKey);

        $this->assertSame(1, $sidecar['signature_schema_version']);
        $this->assertSame('RSA-SHA256', $sidecar['algorithm']);
        $this->assertSame(hash('sha256', $this->publicKey), $sidecar['public_key_sha256']);
    }

    public function test_a_rotated_key_is_visible_as_a_changed_fingerprint(): void
    {
        [$otherPrivate, $otherPublic] = $this->generateKeyPair();

        $mine   = ManifestSigner::sign("bytes\n", $this->privateKey);
        $theirs = ManifestSigner::sign("bytes\n", $otherPrivate);

        $this->assertNotSame($mine['public_key_sha256'], $theirs['public_key_sha256']);
        $this->assertSame(hash('sha256', $otherPublic), $theirs['public_key_sha256']);
    }

    public function test_the_sidecar_carries_no_digest_of_the_manifest(): void
    {
        $sidecar = ManifestSigner::sign("bytes\n", $this->privateKey);

        // Nothing in the sidecar may tempt a verifier into comparing a recorded hash
        // instead of re-hashing the manifest itself.
        $this->assertSame(
            ['algorithm', 'public_key_sha256', 'signature', 'signature_schema_version'],
            $this->sortedKeys($sidecar)
        );
    }

    public function test_signature_path_is_the_manifest_path_plus_suffix(): void
    {
        $this->assertSame(
            '/app/integrity-manifest.json.sig',
            ManifestSigner::signaturePathFor('/app/integrity-manifest.json')
        );
    }

    public function test_sidecar_encodes_as_canonical_json_with_a_trailing_newline(): void
    {
        $encoded = ManifestSigner::encodeSidecar(ManifestSigner::sign("bytes\n", $this->privateKey));

        $this->assertSame("\n", substr($encoded, -1));
        $this->assertIsArray(json_decode($encoded, true));
    }

    public function test_an_unusable_private_key_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PEM private key');

        ManifestSigner::sign("bytes\n", 'not a key at all');
    }

    public function test_an_unusable_public_key_is_rejected(): void
    {
        $sidecar = ManifestSigner::sign("bytes\n", $this->privateKey);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PEM public key');

        ManifestSigner::verify("bytes\n", $sidecar, 'not a key at all');
    }

    public function test_a_sidecar_without_a_signature_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no signature');

        ManifestSigner::verify("bytes\n", ['algorithm' => 'RSA-SHA256'], $this->publicKey);
    }

    public function test_a_non_base64_signature_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid base64');

        ManifestSigner::verify("bytes\n", ['signature' => '!!!not base64!!!'], $this->publicKey);
    }

    public function test_an_ec_key_reports_ecdsa(): void
    {
        [$private] = $this->generateKeyPair(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        $this->assertSame('ECDSA-SHA256', ManifestSigner::sign("bytes\n", $private)['algorithm']);
    }

    /**
     * The KMS path: a signature produced without ever holding the private key, wrapped
     * into a sidecar from the public half alone.
     */
    public function test_a_sidecar_built_from_an_external_signature_verifies(): void
    {
        $bytes     = "{\n  \"release_version\": \"1.0.0\"\n}\n";
        $signature = $this->signExternally($bytes, $this->privateKey);

        $sidecar = ManifestSigner::sidecarFor($signature, $this->publicKey);

        $this->assertTrue(ManifestSigner::verify($bytes, $sidecar, $this->publicKey));
    }

    /**
     * The fingerprint has to come out the same whether it was derived from the private
     * half in sign() or the public half in sidecarFor(), or a verifier holding only the
     * public key could never match the recorded value.
     */
    public function test_the_key_fingerprint_matches_across_both_paths(): void
    {
        $bytes = "bytes\n";

        $signed   = ManifestSigner::sign($bytes, $this->privateKey);
        $external = ManifestSigner::sidecarFor($this->signExternally($bytes, $this->privateKey), $this->publicKey);

        $this->assertSame($signed['public_key_sha256'], $external['public_key_sha256']);
        $this->assertSame($signed['algorithm'], $external['algorithm']);
    }

    /**
     * Determinism again, from the other direction: PKCS#1 v1.5 means the external path
     * and sign() agree byte for byte, which is what lets KMS replace openssl_sign()
     * without the sidecar changing shape.
     */
    public function test_the_external_path_produces_the_same_signature_as_signing_locally(): void
    {
        $bytes = "bytes\n";

        $this->assertSame(
            ManifestSigner::sign($bytes, $this->privateKey)['signature'],
            ManifestSigner::sidecarFor($this->signExternally($bytes, $this->privateKey), $this->publicKey)['signature']
        );
    }

    /**
     * A PEM wrapped at a different width is the same key; the fingerprint must not move,
     * or a key exported by `openssl pkey` and one exported by PHP would disagree.
     */
    public function test_pem_formatting_does_not_change_the_fingerprint(): void
    {
        $rewrapped = $this->rewrapPem($this->publicKey);

        $this->assertNotSame($this->publicKey, $rewrapped, 'the fixture should differ textually');
        $this->assertSame(
            ManifestSigner::sidecarFor("signature-bytes", $this->publicKey)['public_key_sha256'],
            ManifestSigner::sidecarFor("signature-bytes", $rewrapped)['public_key_sha256']
        );
    }

    public function test_an_empty_external_signature_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        ManifestSigner::sidecarFor('', $this->publicKey);
    }

    public function test_a_bad_public_key_is_rejected_when_building_a_sidecar(): void
    {
        $this->expectException(RuntimeException::class);

        ManifestSigner::sidecarFor('signature-bytes', 'not a key at all');
    }

    /**
     * Stands in for AWS KMS: signs without the caller's sidecar code being involved.
     */
    private function signExternally(string $bytes, string $privateKeyPem): string
    {
        $signature = '';
        $key       = openssl_pkey_get_private($privateKeyPem);

        if ($key === false || !openssl_sign($bytes, $signature, $key, OPENSSL_ALGO_SHA256)) {
            $this->fail('Could not produce a stand-in external signature.');
        }

        return $signature;
    }

    /**
     * Re-emits a PEM with 48-character body lines instead of OpenSSL's 64.
     */
    private function rewrapPem(string $pem): string
    {
        $lines  = preg_split('/\R/', trim($pem));
        $header = array_shift($lines);
        $footer = array_pop($lines);
        $body   = implode('', $lines);

        return $header . "\n" . chunk_split($body, 48, "\n") . $footer . "\n";
    }

    /**
     * @return string[] [privateKeyPem, publicKeyPem]
     */
    private function generateKeyPair(array $options = []): array
    {
        $resource = openssl_pkey_new($options + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            $this->fail('Could not generate a test key pair.');
        }

        $private = '';
        openssl_pkey_export($resource, $private);

        $details = openssl_pkey_get_details($resource);

        return [$private, $details['key']];
    }

    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
