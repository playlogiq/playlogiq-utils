<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use RuntimeException;

/**
 * Builds the integrity manifest from an expanded inventory.
 *
 * The manifest is deliberately a pure function of the repository: no timestamps, no
 * host names, no build ids, and nothing that depends on an install step. Re-running
 * the baseline on the same tree must produce byte-identical output, so a manifest can
 * be independently reproduced and its signature re-checked rather than merely trusted
 * — and so the signed pair can be committed and reviewed like any other source.
 *
 * The signature is a sidecar (integrity-manifest.json.sig) rather than a field
 * inside the document, so the signed bytes are exactly the file's bytes and no
 * extract-then-re-encode step can change them.
 */
class ManifestBuilder
{
    /**
     * 2 — the dependency digest is gone. composer.json and composer.lock are no longer
     *     covered, so the key they lived under left the document rather than staying as
     *     an empty map in something that gets signed. A host running this code against a
     *     schema 1 manifest fails the manifest check by design: the two are not from the
     *     same release, and continuing would compare fields that may not mean what this
     *     code thinks they mean.
     */
    const SCHEMA_VERSION = 2;
    const HASH_ALGORITHM = 'sha256';

    /** @var CriticalComponentsInventory */
    private $inventory;

    public function __construct(CriticalComponentsInventory $inventory)
    {
        $this->inventory = $inventory;
    }

    /**
     * @return array{
     *     manifest_schema_version: int,
     *     release_version: string,
     *     inventory_version: string,
     *     hash_algorithm: string,
     *     files: array<string, string>,
     *     settings_keys: string[],
     *     global_settings_keys: string[]
     * }
     */
    public function build(): array
    {
        $files = [];

        foreach ($this->inventory->expand() as $relative) {
            $files[$relative] = $this->hashFile($this->inventory->absolute($relative));
        }

        // expand() already sorts, but the map is what gets signed — be explicit.
        ksort($files, SORT_STRING);

        return [
            'manifest_schema_version' => self::SCHEMA_VERSION,
            'release_version'         => $this->inventory->version(),
            'inventory_version'       => $this->inventory->inventoryVersion(),
            'hash_algorithm'          => self::HASH_ALGORITHM,
            'files'                   => $files,
            'settings_keys'           => $this->inventory->settingsKeys(),
            'global_settings_keys'    => $this->inventory->globalSettingsKeys(),
        ];
    }

    private function hashFile(string $absolute): string
    {
        if (!is_file($absolute) || !is_readable($absolute)) {
            throw new RuntimeException(sprintf('Cannot hash unreadable file: %s', $absolute));
        }

        $hash = hash_file(self::HASH_ALGORITHM, $absolute);

        if ($hash === false) {
            throw new RuntimeException(sprintf('Hashing failed: %s', $absolute));
        }

        return $hash;
    }

    /**
     * Canonical serialisation: recursively key-sorted, pretty-printed for
     * reviewable diffs, trailing newline. These bytes are what gets signed and
     * what verification must re-read unchanged.
     */
    public static function canonicalJson(array $manifest): string
    {
        $encoded = json_encode(
            self::sortRecursive($manifest),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($encoded === false) {
            throw new RuntimeException('Failed to encode manifest: ' . json_last_error_msg());
        }

        return $encoded . "\n";
    }

    /**
     * @param  mixed $value
     * @return mixed
     */
    private static function sortRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);

        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }

        if (!$isList) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
