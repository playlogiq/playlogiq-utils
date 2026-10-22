<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Throwable;

/**
 * Verifies the tree on THIS host against the signed manifest shipped with it.
 *
 * The counterpart to `integrity:baseline`. The baseline runs once, in the pipeline, and
 * says what the release is; this runs on every instance and says whether that instance
 * still matches it. Both expand the same inventory through the same class, which is why
 * expansion lives in CriticalComponentsInventory and nowhere else — a verifier with its
 * own idea of the covered set would either miss files or cry wolf about them.
 *
 * Order of authority matters here, and it runs the opposite way to intuition:
 *
 *   - the SIGNATURE decides whether the manifest may be believed at all;
 *   - the MANIFEST, not the local inventory, decides which files must exist and what
 *     they must hash to;
 *   - the local inventory is consulted only to notice files that have appeared inside a
 *     covered directory since the manifest was built.
 *
 * Taking the local inventory as the authority would be self-defeating: an attacker who
 * can edit a covered file can also edit config/critical_components.php to stop covering
 * it, and a verifier that trusted the local file would report a clean tree. Driving the
 * file checks from the signed manifest means narrowing the scope on an instance changes
 * nothing about what gets checked, and shows up as a mismatch on the inventory file
 * itself (which the baseline always includes) and as a scope disagreement.
 *
 * Nothing here writes, touches the database, or reads a setting. It is pure disk and
 * openssl, so it runs identically in a build container, in a test, and inside the job
 * that fires hourly on every instance in the auto-scaling group.
 *
 * Every check reports independently — see VerificationReport. A verifier that stopped at
 * the first failure would keep answering the question "is something wrong" long after
 * the useful question became "what, exactly".
 */
class ManifestVerifier
{
    /** Can the manifest be read, decoded and understood at all. */
    const CHECK_MANIFEST = 'manifest';

    /** Was it signed by the key this environment trusts. */
    const CHECK_SIGNATURE = 'signature';

    /** Does it describe the same release and scope this code belongs to. */
    const CHECK_SCOPE = 'scope';

    /** Do the covered files still hash to what it says. */
    const CHECK_FILES = 'files';

    /** Has anything appeared inside a covered directory that it does not list. */
    const CHECK_EXTRAS = 'extras';

    /** Does the critical-settings registry still declare the same keys. */
    const CHECK_SETTINGS = 'settings';

    /** @var CriticalComponentsInventory */
    private $inventory;

    /** @var string */
    private $basePath;

    public function __construct(CriticalComponentsInventory $inventory, string $basePath)
    {
        $this->inventory = $inventory;
        $this->basePath  = rtrim(str_replace('\\', '/', $basePath), '/');
    }

    /**
     * @param string      $manifestPath  absolute path to the deployed manifest
     * @param string|null $publicKeyPath absolute path to this environment's committed
     *                                   verification key. Null skips the signature
     *                                   check and is only ever legitimate outside a
     *                                   signed environment — a developer tree, or CI
     *                                   checking the manifest before it is signed.
     */
    public function verify(string $manifestPath, ?string $publicKeyPath): VerificationReport
    {
        $report = new VerificationReport();
        $report->meta('manifest_path', $manifestPath);

        $bytes = $this->read($manifestPath);

        if ($bytes === null) {
            // Absence is not innocence: deleting the manifest is the cheapest way to
            // stop a verifier that treats "nothing to compare" as "nothing wrong".
            $report->fail(
                self::CHECK_MANIFEST,
                'no readable manifest at ' . $manifestPath . ' — this instance cannot prove what it is running'
            );

            return $report;
        }

        $report->meta('manifest_sha256', hash('sha256', $bytes));

        $manifest = json_decode($bytes, true);

        if (!is_array($manifest)) {
            $report->fail(self::CHECK_MANIFEST, 'the manifest is not valid JSON: ' . json_last_error_msg());

            return $report;
        }

        $schema = isset($manifest['manifest_schema_version']) ? (int) $manifest['manifest_schema_version'] : 0;

        if ($schema !== ManifestBuilder::SCHEMA_VERSION) {
            // Code and manifest are from different eras. Continuing would compare fields
            // that may not mean what this code thinks they mean, so stop and say which
            // two versions disagree.
            $report->fail(self::CHECK_MANIFEST, sprintf(
                'manifest schema version %d, but this code reads version %d — the deployed manifest and '
                . 'the deployed code are not from the same release',
                $schema,
                ManifestBuilder::SCHEMA_VERSION
            ));

            return $report;
        }

        $algorithm = isset($manifest['hash_algorithm']) ? (string) $manifest['hash_algorithm'] : '';

        if ($algorithm !== ManifestBuilder::HASH_ALGORITHM) {
            $report->fail(self::CHECK_MANIFEST, sprintf(
                'manifest hashes are %s, but this code verifies %s',
                $algorithm === '' ? 'of an unstated algorithm' : $algorithm,
                ManifestBuilder::HASH_ALGORITHM
            ));

            return $report;
        }

        $report->meta('release_version', (string) ($manifest['release_version'] ?? ''));
        $report->meta('inventory_version', (string) ($manifest['inventory_version'] ?? ''));
        $report->pass(self::CHECK_MANIFEST, 'readable, schema ' . $schema . ', ' . $algorithm);

        $this->checkSignature($report, $manifestPath, $bytes, $publicKeyPath);
        $this->checkScope($report, $manifest);
        $this->checkFiles($report, $manifest);
        $this->checkExtras($report, $manifest);
        $this->checkSettings($report, $manifest);

        return $report;
    }

    /**
     * The manifest is only evidence if this environment's key signed it.
     *
     * The sidecar is read from disk next to the manifest, and the key from the copy
     * committed to the repository — which is itself a covered file, so swapping in an
     * attacker's key to bless an attacker's manifest turns up as a file mismatch below.
     * That pairing is the whole scheme: neither half is load-bearing alone.
     */
    private function checkSignature(
        VerificationReport $report,
        string $manifestPath,
        string $manifestBytes,
        ?string $publicKeyPath
    ): void {
        if ($publicKeyPath === null) {
            $report->skip(
                self::CHECK_SIGNATURE,
                'no verification key supplied — the file hashes below prove the tree matches this manifest, '
                . 'but nothing proves the manifest came from the pipeline'
            );

            return;
        }

        $signaturePath = ManifestSigner::signaturePathFor($manifestPath);
        $sidecarBytes  = $this->read($signaturePath);

        if ($sidecarBytes === null) {
            $report->fail(self::CHECK_SIGNATURE, 'no readable signature at ' . $signaturePath);

            return;
        }

        $sidecar = json_decode($sidecarBytes, true);

        if (!is_array($sidecar)) {
            $report->fail(self::CHECK_SIGNATURE, 'the signature sidecar is not valid JSON: ' . json_last_error_msg());

            return;
        }

        $publicKey = $this->read($publicKeyPath);

        if ($publicKey === null || trim($publicKey) === '') {
            // Not a tampering signal on its own — far more often INTEGRITY_PUBLIC_KEY
            // points at the wrong file — so name the path rather than the conclusion.
            $report->fail(self::CHECK_SIGNATURE, 'no readable verification key at ' . $publicKeyPath);

            return;
        }

        try {
            $verified = ManifestSigner::verify($manifestBytes, $sidecar, $publicKey);
        } catch (Throwable $e) {
            $report->fail(self::CHECK_SIGNATURE, 'the signature could not be checked: ' . $e->getMessage());

            return;
        }

        $signedBy = isset($sidecar['public_key_sha256']) ? (string) $sidecar['public_key_sha256'] : '';
        $report->meta('signed_by', $signedBy);
        $report->meta('signature_algorithm', isset($sidecar['algorithm']) ? (string) $sidecar['algorithm'] : '');

        if (!$verified) {
            // Distinguish "a different key signed this" from "these bytes were altered".
            // Both fail, but the first is usually a rotation or a staging build on a
            // production box, and the second is the one that means what it looks like.
            $expectedBy = $this->fingerprint($publicKey);

            $report->fail(
                self::CHECK_SIGNATURE,
                $expectedBy !== null && $signedBy !== '' && $signedBy !== $expectedBy
                    ? sprintf(
                        'signed with key %s, but this environment trusts %s.',
                        substr($signedBy, 0, 12),
                        substr($expectedBy, 0, 12)
                    )
                    : 'does not match the published manifest; it was modified after signing.'
            );

            return;
        }

        $report->pass(self::CHECK_SIGNATURE, sprintf(
            'valid, signed by %s with %s',
            substr($signedBy, 0, 12),
            isset($sidecar['algorithm']) ? (string) $sidecar['algorithm'] : 'an unstated algorithm'
        ));
    }

    /**
     * Does the manifest describe the release this code belongs to.
     *
     * Redundant with the file hashes in the sense that the inventory file is itself
     * covered, so any scope change already shows up there. It is reported separately
     * because the two failures read completely differently in a log: "config/
     * critical_components.php hash mismatch" invites a hunt for tampering, while
     * "manifest covers inventory 1.3.0, this code is 1.4.0" says plainly that the box
     * is running one release's code against another release's manifest — which is a
     * deploy problem, not an attack.
     */
    private function checkScope(VerificationReport $report, array $manifest): void
    {
        $result = $this->attempt(function () {
            return [
                'release'   => $this->inventory->version(),
                'inventory' => $this->inventory->inventoryVersion(),
            ];
        });

        if ($result['error'] !== null) {
            $report->fail(self::CHECK_SCOPE, 'the deployed inventory is unusable: ' . $result['error']);

            return;
        }

        $live     = $result['value'];
        $manifestRelease   = (string) ($manifest['release_version'] ?? '');
        $manifestInventory = (string) ($manifest['inventory_version'] ?? '');

        $mismatches = [];

        if ($manifestRelease !== $live['release']) {
            $mismatches[] = sprintf('release_version manifest=%s code=%s', $manifestRelease, $live['release']);
        }

        if ($manifestInventory !== $live['inventory']) {
            $mismatches[] = sprintf('inventory_version manifest=%s code=%s', $manifestInventory, $live['inventory']);
        }

        if ($mismatches !== []) {
            $report->fail(self::CHECK_SCOPE, implode(', ', $mismatches));

            return;
        }

        $report->pass(self::CHECK_SCOPE, sprintf(
            'release %s, inventory %s',
            $live['release'],
            $live['inventory']
        ));
    }

    /**
     * Re-hash every file the manifest names.
     *
     * Driven by the manifest rather than by the local expansion, for the reason in the
     * class docblock. Four outcomes are separated because they are four different
     * problems: a hash that moved (edited), a path that is gone (deleted), a path that
     * became a symlink (redirected — the baseline refuses symlinks, so this can only
     * have happened after it ran), and a path that cannot be read (permissions, which
     * is a real way to blind a verifier without changing a byte).
     */
    private function checkFiles(VerificationReport $report, array $manifest): void
    {
        $files = isset($manifest['files']) && is_array($manifest['files']) ? $manifest['files'] : null;

        if ($files === null || $files === []) {
            $report->fail(self::CHECK_FILES, 'the manifest carries no file hashes, so it proves nothing');

            return;
        }

        $report->meta('covered_files', count($files));

        $modified   = [];
        $missing    = [];
        $symlinked  = [];
        $unreadable = [];

        foreach ($files as $relative => $expected) {
            $absolute = $this->basePath . '/' . $relative;

            // Before is_file(), which follows the link and would happily hash the target.
            if (is_link($absolute)) {
                $symlinked[] = (string) $relative;
                continue;
            }

            if (!is_file($absolute)) {
                $missing[] = (string) $relative;
                continue;
            }

            $actual = @hash_file(ManifestBuilder::HASH_ALGORITHM, $absolute);

            if ($actual === false) {
                $unreadable[] = (string) $relative;
                continue;
            }

            if (!hash_equals((string) $expected, $actual)) {
                $modified[] = (string) $relative;
            }
        }

        $items = array_filter([
            'modified'   => $modified,
            'missing'    => $missing,
            'symlinked'  => $symlinked,
            'unreadable' => $unreadable,
        ]);

        if ($items !== []) {
            $report->fail(self::CHECK_FILES, sprintf(
                '%d of %d covered files do not match the manifest',
                count($modified) + count($missing) + count($symlinked) + count($unreadable),
                count($files)
            ), $items);

            return;
        }

        $report->pass(self::CHECK_FILES, sprintf('%d files hashed, all match', count($files)));
    }

    /**
     * Files sitting inside a covered directory that the manifest does not list.
     *
     * The one check that must consult the local inventory, because it is asking about
     * files nobody declared. Without it, coverage would be a list an attacker could add
     * to: dropping a new file into app/Services/Critical would leave every hash in the
     * manifest intact.
     *
     * A widened inventory produces the same finding, and that is correct rather than a
     * false positive — the deployed manifest genuinely does not cover those files. It
     * resolves the moment the pipeline rebuilds and re-signs.
     */
    private function checkExtras(VerificationReport $report, array $manifest): void
    {
        $files = isset($manifest['files']) && is_array($manifest['files']) ? $manifest['files'] : [];

        if ($files === []) {
            $report->skip(self::CHECK_EXTRAS, 'the manifest lists no files to compare against');

            return;
        }

        $result = $this->attempt(function () {
            return $this->inventory->expand();
        });

        if ($result['error'] !== null) {
            // expand() refuses to guess: a declared file that no longer exists, or a
            // directory rule matching nothing, aborts expansion. The file check above
            // has already named the missing paths precisely, so this is reported rather
            // than escalated.
            $report->fail(
                self::CHECK_EXTRAS,
                'the deployed inventory could not be expanded, so unexpected files cannot be ruled out: '
                . $result['error']
            );

            return;
        }

        $extra = array_values(array_diff($result['value'], array_keys($files)));

        if ($extra !== []) {
            $report->fail(
                self::CHECK_EXTRAS,
                sprintf('%d file(s) inside covered directories are not in the manifest', count($extra)),
                ['extra' => $extra]
            );

            return;
        }

        $report->pass(self::CHECK_EXTRAS, 'no unexpected files in covered directories');
    }

    /**
     * Does the critical-settings registry still declare the same keys.
     *
     * Keys only. The manifest records which settings are critical, not what they are
     * set to — values live in the database, change legitimately during operation, and
     * are audited where they are written (see the Setting model's audit trail) rather
     * than hashed into a release artifact. So this catches a key quietly dropped from
     * the registry, which would silently remove a setting from certified scope; it does
     * not catch a changed value, and is not meant to.
     *
     * Both registries, skin and global; a difference in the global one is reported under
     * `global_removed` / `global_added` so the reader can tell which registry moved.
     */
    private function checkSettings(VerificationReport $report, array $manifest): void
    {
        $expected       = $this->manifestKeys($manifest, 'settings_keys');
        $expectedGlobal = $this->manifestKeys($manifest, 'global_settings_keys');

        $result = $this->attempt(function () {
            return [$this->inventory->settingsKeys(), $this->inventory->globalSettingsKeys()];
        });

        if ($result['error'] !== null) {
            $report->fail(self::CHECK_SETTINGS, 'the deployed inventory is unusable: ' . $result['error']);

            return;
        }

        [$live, $liveGlobal] = $result['value'];

        $items = array_filter([
            'removed'        => array_values(array_diff($expected, $live)),
            'added'          => array_values(array_diff($live, $expected)),
            'global_removed' => array_values(array_diff($expectedGlobal, $liveGlobal)),
            'global_added'   => array_values(array_diff($liveGlobal, $expectedGlobal)),
        ]);

        if ($items !== []) {
            $report->fail(
                self::CHECK_SETTINGS,
                'the critical-settings registry no longer matches the manifest',
                $items
            );

            return;
        }

        $report->pass(self::CHECK_SETTINGS, sprintf(
            '%d critical settings keys and %d global critical settings keys match',
            count($expected),
            count($expectedGlobal)
        ));
    }

    /**
     * A key list from the manifest; absent reads as empty, so every live key is reported added.
     *
     * @return string[]
     */
    private function manifestKeys(array $manifest, string $field): array
    {
        return isset($manifest[$field]) && is_array($manifest[$field])
            ? array_map('strval', $manifest[$field])
            : [];
    }

    /**
     * Runs a probe against the deployed inventory, turning its refusal into a finding.
     *
     * CriticalComponentsInventory throws rather than degrading, which is right for the
     * baseline — a build must not emit a manifest covering less than it claims. On an
     * instance the same throw would abort the whole run, losing the checks that do not
     * depend on it, so it is caught per check instead.
     *
     * @return array{value: mixed, error: ?string}
     */
    private function attempt(callable $probe): array
    {
        try {
            return ['value' => $probe(), 'error' => null];
        } catch (Throwable $e) {
            return ['value' => null, 'error' => $e->getMessage()];
        }
    }

    private function read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return ($contents === false || $contents === '') ? null : $contents;
    }

    /**
     * Fingerprint of a PEM public key, computed the way ManifestSigner computes it, so
     * the two are comparable regardless of how either PEM was line-wrapped.
     */
    private function fingerprint(string $publicKeyPem): ?string
    {
        $key = @openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || !isset($details['key']) || !is_string($details['key'])) {
            return null;
        }

        return hash(ManifestSigner::DIGEST_ALGORITHM, $details['key']);
    }
}
