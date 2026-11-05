<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Console;

use PlaylogiqUtils\Integrity\CriticalComponentsInventory;
use PlaylogiqUtils\Integrity\HostIdentity;
use PlaylogiqUtils\Integrity\SettingsSnapshot;
use PlaylogiqUtils\Integrity\SettingsSnapshotStore;
use Illuminate\Console\Command;
use PlaylogiqUtils\Integrity\Contracts\CriticalSettingsReader;
use Throwable;

/**
 * Produces a critical settings snapshot for one skin, or for the global settings with
 * --global, by hand.
 *
 * The by-hand counterpart to the guard's record(), and the same relationship
 * `integrity:verify` has to the fleet job: same reader, same document, same store, with
 * the result printed instead of logged.
 *
 * Three uses:
 *
 *   - seeding a skin that has never had a setting changed since the feature went live,
 *     so its history starts from a known state rather than from the first edit;
 *   - answering "what is this skin's certified configuration right now" without waiting
 *     for someone to change something;
 *   - seeing the document before the bucket exists, which is why printing is the default
 *     and storing takes a flag.
 *
 * It cannot sign and cannot delete, for the same reason `integrity:baseline` cannot sign:
 * a command that could would be the shortest path around everything the store is for.
 */
class IntegritySettingsSnapshot extends Command
{
    protected $signature = 'integrity:settings-snapshot
        {--skin= : Skin id to snapshot. Required unless --global is given.}
        {--global : Snapshot the global settings instead of a skin.}
        {--store : Write the snapshot to the configured store. Without it, nothing is written.}
        {--json : Print the document alone, with no table, for piping.}';

    protected $description = 'Snapshot one skin\'s, or the global, critical settings values (GLI-33).';

    public function handle(): int
    {
        try {
            return $this->snapshot();
        } catch (Throwable $e) {
            $this->error('Snapshot failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function snapshot(): int
    {
        $global = (bool) $this->option('global');
        $skinId = $this->skinId();

        if ($global === ($this->option('skin') !== null)) {
            $this->error('Pass exactly one of --skin or --global.');

            return self::FAILURE;
        }

        if (!$global && $skinId === null) {
            $this->error('--skin is required, and must be a positive integer.');

            return self::FAILURE;
        }

        $inventory = new CriticalComponentsInventory((array) config('critical_components'), base_path());
        $reader    = app(CriticalSettingsReader::class, ['inventory' => $inventory]);

        // GLI-33: a skin outside GLI scope has no certified configuration, so there is no
        // document to build — not even to print. Refused rather than printed-but-not-stored
        // because the table this prints IS the snapshot, and one produced for an unregulated
        // skin would be a record of something that was never certified. The global scope
        // has no such flag and is always in scope.
        if ($skinId !== null && !$reader->isRegulated($skinId)) {
            $this->error(sprintf(
                'Skin %d is not GLI regulated, so it has no critical settings snapshot. '
                . 'Enable "%s" on the skin\'s Responsible Gaming tab first.',
                $skinId,
                CriticalSettingsReader::REGULATED_KEY
            ));

            return self::FAILURE;
        }

        $values = $reader->values($skinId);

        $store    = SettingsSnapshotStore::fromConfig();
        $previous = null;

        // Only consulted when actually storing. Reading the newest object just to print a
        // document would make the command fail on an unconfigured store, which is exactly
        // the situation it is most useful in.
        if ($this->option('store')) {
            $latest = $store->latest($skinId);

            if ($latest !== null) {
                $previous = ['key' => $latest['key'], 'sha256' => $latest['sha256']];
            }
        }

        $payload = (new SettingsSnapshot($inventory, new HostIdentity()))->build(
            $skinId,
            $values,
            [
                'event'       => 'manual',
                'settings'    => [],
                'actor'       => ['type' => 'operator', 'admin_id' => null],
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ],
            $previous
        );

        $bytes = SettingsSnapshot::canonicalJson($payload);

        if ($this->option('json')) {
            $this->getOutput()->write($bytes);
        } else {
            $this->renderTable($payload, $values);
        }

        if (!$this->option('store')) {
            $this->line('');
            $this->warn('Nothing was written. Pass --store to append this to ' . $store->describe() . '.');

            return self::SUCCESS;
        }

        $key = $store->put($skinId, $bytes, [
            'skin-id'         => SettingsSnapshotStore::metadataSkinId($skinId),
            'settings-sha256' => (string) $payload['settings_sha256'],
            'trigger-event'   => 'manual',
        ]);

        $this->info('Stored ' . $key);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed>       $payload
     * @param array<string, string|null> $values
     */
    private function renderTable(array $payload, array $values): void
    {
        $this->table(['field', 'value'], [
            ['skin_id', SettingsSnapshotStore::metadataSkinId($payload['skin_id'])],
            ['taken_at', (string) $payload['taken_at']],
            ['release_version', (string) $payload['release_version']],
            ['inventory_version', (string) $payload['inventory_version']],
            ['covered settings', (string) count($values)],
            ['unset settings', (string) count(array_filter($values, 'is_null'))],
            ['settings_sha256', (string) $payload['settings_sha256']],
            ['registry_sha256', (string) $payload['settings_registry_sha256']],
        ]);

        $rows = [];

        foreach ($values as $key => $value) {
            // Distinguished from an empty string on purpose: "never set" and "set to
            // nothing" are different configurations, and some of these keys are flags
            // where that difference decides behaviour.
            $rows[] = [$key, $value === null ? '<unset>' : $value];
        }

        $this->table(['setting', 'value'], $rows);
    }

    private function skinId(): ?int
    {
        $skin = trim((string) $this->option('skin'));

        if ($skin === '' || !ctype_digit($skin) || (int) $skin < 1) {
            return null;
        }

        return (int) $skin;
    }
}
