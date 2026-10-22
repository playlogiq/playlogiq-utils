<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Console;

use PlaylogiqUtils\Integrity\ManifestSigner;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Writes integrity-manifest.json.sig from a signature produced by AWS KMS.
 *
 * The signing key is an asymmetric KMS CMK whose private half cannot be exported, so this
 * command never holds a key and never calls AWS. The pipeline does that part:
 *
 *   openssl dgst -binary -sha256 integrity-manifest.json > digest.bin
 *   aws kms sign --key-id "$KEY" --message fileb://digest.bin --message-type DIGEST \
 *       --signing-algorithm RSASSA_PKCS1_V1_5_SHA_256 --query Signature --output text > sig.b64
 *   aws kms get-public-key --key-id "$KEY" --query PublicKey --output text \
 *       | base64 -d | openssl pkey -pubin -inform DER -outform PEM > pub.pem
 *   php artisan integrity:sign --signature-file=sig.b64 --public-key=pub.pem
 *
 * Two details in that sequence are not interchangeable. DIGEST rather than RAW, because
 * RAW caps the message at 4096 bytes and the manifest is already 3.2 KB and growing.
 * PKCS#1 v1.5 rather than PSS, because PSS is randomised and would rewrite the signature
 * on every run, churning the diff of a tracked file for no gain.
 *
 * The command verifies the signature against the manifest before writing anything. That
 * makes it self-checking rather than merely obedient: a signature over the wrong bytes,
 * or made with a key other than the one supplied, fails here instead of being committed
 * as something that looks like proof. It also means the command cannot be abused to
 * bless a tampered manifest — doing that would need a valid signature over the tampered
 * bytes, which needs the private key, which is exactly what nobody has.
 */
class IntegritySign extends Command
{
    protected $signature = 'integrity:sign
        {--manifest= : Manifest to sign (default: integrity-manifest.json in the app root).}
        {--signature= : Base64 signature, as returned by `aws kms sign --query Signature --output text`.}
        {--signature-file= : File holding that base64. Preferred in CI — keeps it out of the process list.}
        {--public-key= : Required. PEM public key to verify against, e.g. integrity-signing-public-staging.pem.}';

    protected $description = 'Attach a KMS-produced signature to the integrity manifest, verifying it first.';

    public function handle(): int
    {
        try {
            return $this->attachSignature();
        } catch (Throwable $e) {
            // An unsigned manifest is a visible failure; a wrongly signed one is not.
            // Fail the build rather than write a sidecar we could not confirm.
            $this->error('Signing failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function attachSignature(): int
    {
        $manifestPath = $this->resolveManifestPath();

        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            $this->error('No readable manifest at ' . $manifestPath . '. Run integrity:baseline first.');

            return self::FAILURE;
        }

        $manifestBytes = file_get_contents($manifestPath);

        if ($manifestBytes === false || $manifestBytes === '') {
            $this->error('The manifest at ' . $manifestPath . ' is empty.');

            return self::FAILURE;
        }

        $signature  = $this->resolveSignature();
        $publicKey  = $this->resolvePublicKey();
        $sidecar    = ManifestSigner::sidecarFor($signature, $publicKey);

        // The whole point of this step. Verify against the manifest as it sits on disk,
        // using the same key the sidecar will name, before committing to anything.
        if (!ManifestSigner::verify($manifestBytes, $sidecar, $publicKey)) {
            $this->error(
                'The signature does not verify against ' . basename($manifestPath) . ' with the supplied '
                . 'public key. Either it covers different bytes — regenerate the manifest and re-sign in '
                . 'the same run — or it was made with a different key than the one exported here.'
            );

            return self::FAILURE;
        }

        $signaturePath = ManifestSigner::signaturePathFor($manifestPath);

        if (@file_put_contents($signaturePath, ManifestSigner::encodeSidecar($sidecar)) === false) {
            $this->error('Could not write the signature to ' . $signaturePath);

            return self::FAILURE;
        }

        $this->info('Wrote ' . $signaturePath);
        $this->table(['field', 'value'], [
            ['manifest', $manifestPath],
            ['manifest sha256', hash('sha256', $manifestBytes)],
            ['algorithm', $sidecar['algorithm']],
            ['signing key sha256', $sidecar['public_key_sha256']],
            ['verified', 'yes, before writing'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Raw signature bytes from exactly one of the two options.
     *
     * Requiring exactly one is deliberate: accepting both and silently preferring one
     * would let a stale --signature win over the file CI just wrote.
     */
    private function resolveSignature(): string
    {
        $inline = trim((string) $this->option('signature'));
        $path   = trim((string) $this->option('signature-file'));

        if ($inline !== '' && $path !== '') {
            throw new RuntimeException('Pass either --signature or --signature-file, not both.');
        }

        if ($inline === '' && $path === '') {
            throw new RuntimeException('No signature given. Pass --signature-file (preferred) or --signature.');
        }

        if ($path !== '') {
            $absolute = $this->absolutePath($path);

            if (!is_file($absolute)) {
                // Report the resolved path, not what was typed. "sig.b64 is not readable"
                // is unhelpful when you are standing in the directory containing it.
                throw new RuntimeException('--signature-file not found at: ' . $absolute);
            }

            if (!is_readable($absolute)) {
                // Report the resolved path, not what was typed. "sig.b64 is not readable"
                // is unhelpful when you are standing in the directory containing it.
                throw new RuntimeException('--signature-file found but not a readable file: ' . $absolute);
            }

            $inline = (string) file_get_contents($absolute);
        }

        // The AWS CLI adds a trailing newline; base64_decode in strict mode rejects it.
        $encoded = preg_replace('/\s+/', '', $inline);

        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('The signature is empty.');
        }

        $encoded = $this->unwrapJsonSignature($encoded, $inline);
        $signature = base64_decode($encoded, true);

        if ($signature === false || $signature === '') {
            throw new RuntimeException(
                'The signature is not base64. Expected the bare base64 that '
                . '`aws kms sign --query Signature --output text` prints. Got: '
                . $this->preview($inline)
            );
        }

        return $signature;
    }

    /**
     * Unwraps the two JSON shapes that keep turning up in this argument.
     *
     * `aws kms sign` without `--query Signature --output text` prints its whole
     * response, and our own sidecar is JSON too — so a pipeline that hands over
     * either is making an understandable mistake rather than a nonsensical one.
     * Accepting them costs nothing: the signature is still verified against the
     * manifest before anything is written, so a wrong one cannot slip through here.
     *
     * Base64 never starts with '{', so the discrimination is unambiguous.
     */
    private function unwrapJsonSignature(string $encoded, string $raw): string
    {
        if (substr($encoded, 0, 1) !== '{') {
            return $encoded;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'The signature file looks like JSON but does not parse: ' . $this->preview($raw)
            );
        }

        // 'Signature' is the AWS CLI's field; 'signature' is our sidecar's.
        foreach (['Signature', 'signature'] as $field) {
            if (isset($decoded[$field]) && is_string($decoded[$field]) && trim($decoded[$field]) !== '') {
                $this->warn('Read the signature from the "' . $field . '" field of a JSON document.');

                return (string) preg_replace('/\s+/', '', $decoded[$field]);
            }
        }

        throw new RuntimeException(
            'The signature file is JSON but carries no "Signature" or "signature" field: '
            . $this->preview($raw)
        );
    }

    /**
     * A short, single-line excerpt for error messages. Signatures are not secret, but
     * an unbounded dump into a build log is still noise.
     */
    private function preview(string $raw): string
    {
        $flat = (string) preg_replace('/\s+/', ' ', trim($raw));

        return strlen($flat) > 80 ? substr($flat, 0, 80) . '...' : $flat;
    }

    private function resolvePublicKey(): string
    {
        $path = trim((string) $this->option('public-key'));

        // No default on purpose. There is one verification key per environment, so
        // defaulting to any single one would let a run verify against the wrong
        // environment's key. That fails safely — the signature simply would not
        // verify — but the error would point at the signature rather than at the
        // misconfiguration that caused it. Make the caller say which key it means.
        if ($path === '') {
            throw new RuntimeException(
                '--public-key is required. Pass the key for the environment being signed, '
                . 'e.g. --public-key=integrity-signing-public-staging.pem.'
            );
        }

        $absolute = $this->absolutePath($path);

        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new RuntimeException(
                'No readable public key at ' . $absolute . '. Export it with '
                . '`aws kms get-public-key` and commit it, or point --public-key elsewhere.'
            );
        }

        $contents = file_get_contents($absolute);

        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException('The public key at ' . $absolute . ' is empty.');
        }

        return $contents;
    }

    private function resolveManifestPath(): string
    {
        $manifest = trim((string) $this->option('manifest'));

        if ($manifest === '') {
            return base_path('integrity-manifest.json');
        }

        return $this->absolutePath($manifest);
    }

    /**
     * Relative paths resolve against the app root, never the shell's working directory,
     * so a CI step does not have to care which directory it happens to run from.
     *
     * All three path options share this on purpose. Mixing conventions is how you end up
     * being told a file is unreadable while looking straight at it.
     */
    private function absolutePath(string $path): string
    {
        return substr($path, 0, 1) === '/' ? $path : base_path($path);
    }
}
