<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

/**
 * The snapshot document: one skin's certified configuration at one instant, or the global
 * settings' (skin_id null).
 *
 * Written on every authorised change to a critical setting, in the same canonical form
 * as the manifest — recursively key-sorted, pretty-printed, trailing newline — so two
 * snapshots of the same configuration are byte-identical and a diff between two of them
 * reads as a list of what an operator changed.
 *
 * Every snapshot carries ALL registry keys, not the one that changed. A record that only
 * listed the delta would force whoever answers "what was this skin's tax configuration
 * on 14 March" to replay the whole sequence from the beginning and trust that no event
 * was ever lost. A complete statement answers it by itself.
 *
 * The fields that look decorative and are not:
 *
 *   settings_sha256           lets the writer decide whether anything actually changed
 *                             without diffing documents, and lets the verifier compare a
 *                             live skin against a snapshot in one comparison.
 *   settings_registry_sha256  says which version of the registry this covers. When a key
 *                             is added to the registry, older snapshots legitimately
 *                             cover less, and a verifier has to be able to tell that
 *                             apart from a key that went missing.
 *   previous_snapshot         chains the documents. Deleting one is impossible in the
 *                             store, so the chain is not there to detect deletion: it
 *                             detects a document written outside the normal path, and
 *                             proves the one you are reading is really the newest.
 *   trigger                   provenance — which setting, which event, which operator,
 *                             and the same UTC instant recorded on the matching
 *                             ConfigurationAuditLog row, which is how the two are joined.
 *   written_by                the host that wrote it. A snapshot from a host that should
 *                             not be writing snapshots is itself a finding.
 *
 * This class is pure: it takes values and returns a document. Reading the values is
 * CriticalSettingsReader's job and storing the document is SettingsSnapshotStore's, so
 * the shape of the record can be tested without a database or a bucket.
 */
class SettingsSnapshot
{
    /**
     * Bumped only for a change that a reader could not otherwise survive — a field
     * removed, or one whose meaning changes. Adding a field does not need it.
     */
    /**
     * 2 — trigger carries `settings`, a list, where it used to carry `setting`, one name.
     *     A snapshot is now taken once per save rather than once per changed row, so a
     *     single name could no longer say what caused it.
     */
    const SCHEMA_VERSION = 2;

    const HASH_ALGORITHM = 'sha256';

    /** @var CriticalComponentsInventory */
    private $inventory;

    /** @var HostIdentity */
    private $host;

    public function __construct(CriticalComponentsInventory $inventory, HostIdentity $host)
    {
        $this->inventory = $inventory;
        $this->host      = $host;
    }

    /**
     * @param int|null                              $skinId   null for the global scope
     * @param array<string, string|null>            $values   registry key => live value
     * @param array<string, mixed>                  $trigger  what caused this snapshot
     * @param array{key: string, sha256: string}|null $previous the snapshot this follows
     *
     * @return array<string, mixed>
     */
    public function build(?int $skinId, array $values, array $trigger, ?array $previous): array
    {
        return [
            'schema_version'           => self::SCHEMA_VERSION,
            'skin_id'                  => $skinId,
            'taken_at'                 => gmdate('Y-m-d\TH:i:s\Z'),
            'release_version'          => $this->inventory->version(),
            'inventory_version'        => $this->inventory->inventoryVersion(),
            'settings_registry_sha256' => self::registryDigest($this->inventory->settingsKeysFor($skinId)),
            'settings_sha256'          => self::settingsDigest($values),
            'settings'                 => $values,
            'trigger'                  => $trigger,
            'previous_snapshot'        => $previous,

            // hostname(), not describe(): describe() reaches for the instance metadata
            // service, and a snapshot must not fail to be written because that endpoint
            // is briefly unreachable.
            'written_by'               => $this->host->hostname(),
        ];
    }

    /**
     * The bytes that get stored. Same canonicalisation as the manifest, for the same
     * reason: a record whose serialisation can vary cannot be compared by hash.
     *
     * @param array<string, mixed> $payload
     */
    public static function canonicalJson(array $payload): string
    {
        return ManifestBuilder::canonicalJson($payload);
    }

    /**
     * Digest of the settings map alone.
     *
     * Deliberately not the digest of the whole document: taken_at and trigger differ on
     * every save, so a document digest would report "changed" every time an operator
     * pressed save on an unmodified form, and the store would fill with identical
     * configurations that cannot be deleted.
     *
     * @param array<string, string|null> $values
     */
    public static function settingsDigest(array $values): string
    {
        return hash(self::HASH_ALGORITHM, ManifestBuilder::canonicalJson($values));
    }

    /**
     * Digest of the covered key list — what was certified, independent of its values.
     *
     * @param string[] $keys
     */
    public static function registryDigest(array $keys): string
    {
        sort($keys, SORT_STRING);

        return hash(self::HASH_ALGORITHM, implode("\n", $keys));
    }

    public static function digest(string $bytes): string
    {
        return hash(self::HASH_ALGORITHM, $bytes);
    }
}
