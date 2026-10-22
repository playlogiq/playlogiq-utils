<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\Integrity;

use PlaylogiqUtils\Integrity\CriticalComponentsInventory;
use PlaylogiqUtils\Integrity\HostIdentity;
use PlaylogiqUtils\Integrity\SettingsSnapshot;
use PlaylogiqUtils\Integrity\SettingsSnapshotStore;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot document is a pure function of (skin, values, trigger, previous), so this
 * runs with no container, no database and no bucket — same reasoning as
 * CriticalComponentsInventoryTest.
 *
 * What is worth asserting here is not the field list but the two properties the rest of
 * the scheme leans on: that identical configurations produce identical digests, and that
 * a changed value produces a different one. Everything else — dedupe at write time, the
 * verifier's live comparison — is built on those.
 */
class SettingsSnapshotTest extends TestCase
{
    /** @var array<string, mixed> */
    private $inventoryConfig = [
        'version'           => '1.0.0',
        'inventory_version' => '1.5.0',
        'components'        => [],
        'settings'          => [
            'registry' => [
                'tax'         => ['bet_tax', 'bet_tax_perc'],
                'self_limits' => ['deposit_self_limit'],
            ],
            'global_registry' => [
                'integrity_checks' => ['disable_SettingsIntegrityCheck'],
            ],
        ],
    ];

    public function test_it_builds_a_document_covering_every_registry_key(): void
    {
        $payload = $this->snapshot()->build(12, $this->values(), $this->trigger(), null);

        $this->assertSame(12, $payload['skin_id']);
        $this->assertSame('1.0.0', $payload['release_version']);
        $this->assertSame('1.5.0', $payload['inventory_version']);
        $this->assertSame(SettingsSnapshot::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertNull($payload['previous_snapshot']);

        // The registry, in full, including the key this skin has never set.
        $this->assertSame(
            ['bet_tax', 'bet_tax_perc', 'deposit_self_limit'],
            array_keys($payload['settings'])
        );
        $this->assertNull($payload['settings']['deposit_self_limit']);
    }

    public function test_the_taken_at_stamp_is_utc(): void
    {
        // Not cosmetic: SetTimezone leaves the process default on the operator's own
        // timezone during an authenticated request, and the job inherits nothing of the
        // sort — a snapshot stamped in local time could not be ordered against the audit
        // trail, which stores UTC.
        $payload = $this->snapshot()->build(12, $this->values(), $this->trigger(), null);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $payload['taken_at']
        );
    }

    public function test_the_settings_digest_ignores_everything_but_the_values(): void
    {
        $values = $this->values();

        $first  = $this->snapshot()->build(12, $values, $this->trigger('bet_tax'), null);
        $second = $this->snapshot()->build(12, $values, $this->trigger('bet_tax_perc'), [
            'key'    => 'skin_12/20260908T113848Z-a91c4f0e2b77.json',
            'sha256' => str_repeat('a', 64),
        ]);

        // Same configuration reached by a different route is the same configuration. This
        // is what stops a settings form save from filling an undeletable bucket with
        // identical documents.
        $this->assertSame($first['settings_sha256'], $second['settings_sha256']);
    }

    public function test_the_settings_digest_moves_when_a_value_moves(): void
    {
        $before = $this->snapshot()->build(12, $this->values(), $this->trigger(), null);

        $changed                  = $this->values();
        $changed['bet_tax_perc']  = '8.00';

        $after = $this->snapshot()->build(12, $changed, $this->trigger(), null);

        $this->assertNotSame($before['settings_sha256'], $after['settings_sha256']);
    }

    public function test_an_unset_value_is_not_an_empty_string(): void
    {
        $unset        = $this->values();
        $emptyString  = $this->values();

        $unset['deposit_self_limit']       = null;
        $emptyString['deposit_self_limit'] = '';

        // Some of these keys are flags where "never set" and "set to nothing" resolve to
        // different behaviour, so the record has to keep them apart.
        $this->assertNotSame(
            SettingsSnapshot::settingsDigest($unset),
            SettingsSnapshot::settingsDigest($emptyString)
        );
    }

    public function test_the_registry_digest_does_not_depend_on_declaration_order(): void
    {
        // The registry is grouped for review, and a group being reordered or renamed is
        // not a scope change. If it moved the digest, every such edit would look like the
        // registry had changed and would invalidate the dedupe.
        $this->assertSame(
            SettingsSnapshot::registryDigest(['a', 'b', 'c']),
            SettingsSnapshot::registryDigest(['c', 'a', 'b'])
        );

        $this->assertNotSame(
            SettingsSnapshot::registryDigest(['a', 'b']),
            SettingsSnapshot::registryDigest(['a', 'b', 'c'])
        );
    }

    public function test_the_document_is_canonical_and_byte_stable(): void
    {
        $payload = $this->snapshot()->build(12, $this->values(), $this->trigger(), null);
        $bytes   = SettingsSnapshot::canonicalJson($payload);

        $this->assertSame($bytes, SettingsSnapshot::canonicalJson(json_decode($bytes, true)));
        $this->assertSame("\n", substr($bytes, -1));
    }

    public function test_the_store_key_carries_the_skin_the_instant_and_the_digest(): void
    {
        $store = new SettingsSnapshotStore([
            'enabled' => true,
            'disk'    => 'integrity_snapshots',
            'prefix'  => '',
        ]);

        $bytes = "{\n    \"skin_id\": 12\n}\n";
        $key   = $store->keyFor(12, $bytes);

        $this->assertMatchesRegularExpression('#^skin_12/\d{8}T\d{6}Z-[0-9a-f]{12}\.json$#', $key);
        $this->assertStringContainsString(substr(SettingsSnapshot::digest($bytes), 0, 12), $key);
    }

    public function test_a_global_document_has_no_skin_and_covers_the_global_registry(): void
    {
        $payload = $this->snapshot()->build(null, ['disable_SettingsIntegrityCheck' => '0'], $this->trigger(), null);

        $this->assertNull($payload['skin_id']);
        $this->assertSame(
            SettingsSnapshot::registryDigest(['disable_SettingsIntegrityCheck']),
            $payload['settings_registry_sha256']
        );
    }

    public function test_the_global_scope_has_a_directory_and_metadata_of_its_own(): void
    {
        $store = new SettingsSnapshotStore([
            'enabled' => true,
            'disk'    => 'integrity_snapshots',
            'prefix'  => 'snapshots',
        ]);

        $this->assertSame('snapshots/' . SettingsSnapshotStore::GLOBAL_SCOPE, $store->directoryFor(null));
        $this->assertMatchesRegularExpression('#^snapshots/global/\d{8}T\d{6}Z-[0-9a-f]{12}\.json$#', $store->keyFor(null, '{}'));
        $this->assertSame(SettingsSnapshotStore::GLOBAL_SCOPE, SettingsSnapshotStore::metadataSkinId(null));
        $this->assertSame('12', SettingsSnapshotStore::metadataSkinId(12));
    }

    public function test_the_store_reports_itself_unconfigured_without_a_disk(): void
    {
        $store = new SettingsSnapshotStore(['enabled' => true, 'disk' => '', 'prefix' => '']);

        // Enabled with no disk is a half-finished deployment. Treating it as usable would
        // mean finding the missing half at the moment a regulated setting changes.
        $this->assertFalse($store->isConfigured());
    }

    private function snapshot(): SettingsSnapshot
    {
        return new SettingsSnapshot(
            new CriticalComponentsInventory($this->inventoryConfig, sys_get_temp_dir()),
            new HostIdentity()
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function values(): array
    {
        return [
            'bet_tax'            => '1',
            'bet_tax_perc'       => '7.50',
            'deposit_self_limit' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trigger(string $setting = 'bet_tax_perc'): array
    {
        return [
            'event'       => 'updated',
            'setting'     => $setting,
            'actor'       => ['type' => 'admin', 'admin_id' => 41],
            'occurred_at' => '2026-09-08 11:38:48',
        ];
    }
}
