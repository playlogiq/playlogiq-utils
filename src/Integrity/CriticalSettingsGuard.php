<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use PlaylogiqUtils\Integrity\Contracts\CriticalSettingsReader;
use PlaylogiqUtils\Integrity\Contracts\SignificantEvents;
use PlaylogiqUtils\Integrity\Exceptions\CriticalSettingsDriftException;
use PlaylogiqUtils\Integrity\Models\SettingsIntegrityCheck;
use Throwable;

/**
 * GLI-33 — guards a settings save, and records the result.
 *
 * Two calls, either side of the write:
 *
 *   check()   before anything is written. Compares the skin's live critical settings
 *             against the newest snapshot. They should be identical: every authorised
 *             change goes through here and leaves a snapshot behind, so a difference
 *             means the stored configuration moved by some other route.
 *
 *   record()  after the write, synchronously. One snapshot per save.
 *
 * And one that is not part of a save at all:
 *
 *   inspect() reports the same comparison check() makes, and writes the same significant
 *             events, without writing anything to the store. It exists because "has this
 *             skin's configuration moved?" is a question worth being able to ask on its
 *             own — see the note on the method for why it deliberately cannot answer it
 *             by recording a new baseline.
 *
 * WHY SYNCHRONOUS. This replaced a queued job, and the reason is check(): a snapshot
 * written some seconds after the save is not a baseline the *next* save can be compared
 * against. Two saves in quick succession would have found the newest snapshot still
 * describing the state before the first one, and reported every field the operator had
 * just legitimately changed as tampering. The comparison only means anything if the
 * record is complete by the time the next save starts.
 *
 * WHAT HAPPENS WHEN THERE IS NOTHING TO COMPARE. Every skin has a first time, and it is
 * the save that enrols it: check() runs before the write, so the flag still reads off,
 * the skin is not yet in scope, and record() afterwards lays down the baseline. Nothing
 * is missing and nothing is recorded — that is the genesis working.
 *
 * A regulated skin that reaches check() with no baseline is a different thing entirely,
 * and is treated as one. See the note there.
 *
 * record() also treats it as the thing to fix rather than to report: for a skin already in
 * scope and holding no document, ANY save writes the first one — including a save of an
 * unrelated tab, and including a save that changed nothing. There is no other way for such
 * a skin to acquire a baseline, and leaving it without one is the condition the check is
 * meant to surface, not a state worth preserving.
 *
 * WHAT HAPPENS ON A MISMATCH. A significant event, always — that is the finding, and it
 * is recorded whether or not the save proceeds. Then it splits:
 *
 *   - the mismatch is in a setting this save is writing: proceed. The operator is
 *     replacing that value anyway, and the snapshot taken afterwards records what they
 *     chose. Refusing would leave them unable to correct the very field that is wrong.
 *   - the mismatch is anywhere else: refuse the save. Continuing would write a new
 *     snapshot that silently adopts the unexplained value as certified, which is exactly
 *     the laundering this exists to prevent.
 *
 * EVERY COMPARISON IS IN THE HISTORY. Whenever check() actually compares — a save that
 * touches the certified configuration of a regulated skin — it leaves a
 * settings_integrity_checks row with trigger TRIGGER_CONFIGURATION_CHANGE, whatever it found:
 * a match, a drift (blocking or not), a missing baseline, or a comparison that failed. The
 * same history the backoffice button writes to, so "was this skin checked, and what did it
 * say" has one answer. A save that is never compared — an unrelated tab, a skin outside GLI
 * scope, snapshots switched off — leaves none, because nothing was checked.
 *
 * ON FAILING OPEN. An unreachable store, a malformed document, a reader that throws: all
 * logged and then allowed through. The configuration must not depend on the availability
 * of the thing that only observes it — a bucket outage that froze every settings save in
 * the backoffice would be a worse failure than a late detection, and the verifier still
 * catches the divergence on its next run. A genuine mismatch is the one thing that does
 * block, because that is a finding rather than an outage.
 *
 * THE GLOBAL SCOPE. A null skin id means the global settings registry. It has no GLI flag
 * of its own, so it is always in scope; everything else above applies to it unchanged.
 */
class CriticalSettingsGuard
{
    /**
     * Stands in for the incoming value of a setting this save does not write.
     *
     * A drift report covers every setting that disagrees, and the ones outside the save
     * are the ones that block it — so they are exactly what somebody reads the log line
     * for. Leaving the field out would make them look like an oversight; null would be a
     * lie, because null is a value a setting can genuinely hold.
     */
    const NOT_IN_SAVE = '(not written by this save)';

    /**
     * Outcomes of inspect(). Reported rather than thrown — the caller is a page asking a
     * question, and every one of these is an answer to it rather than a failure to answer.
     */
    const CHECK_MATCHES       = 'matches';
    const CHECK_DRIFT         = 'drift';
    const CHECK_NO_BASELINE   = 'no_baseline';
    const CHECK_NOT_REGULATED = 'not_regulated';
    const CHECK_DISABLED      = 'disabled';
    const CHECK_FAILED        = 'failed';

    /** @var CriticalComponentsInventory */
    private $inventory;

    /** @var CriticalSettingsReader */
    private $reader;

    /** @var SettingsSnapshotStore */
    private $store;

    /** @var SignificantEvents */
    private $events;

    /** @var SettingsIntegrityCheckLog|null null records no history — the stubbed guards in tests */
    private $checks;

    /**
     * Whether each skin was under GLI rules when check() ran, i.e. before this save was
     * applied. record() cannot work it out on its own: by then the flag already reads
     * whatever the save set it to, and "was regulated, now is not" is indistinguishable
     * from "never was" unless somebody looked first.
     *
     * @var array<int, bool>
     */
    private $regulatedBefore = [];

    public function __construct(
        CriticalComponentsInventory $inventory,
        CriticalSettingsReader $reader,
        SettingsSnapshotStore $store,
        SignificantEvents $events,
        ?SettingsIntegrityCheckLog $checks = null
    ) {
        $this->inventory = $inventory;
        $this->reader    = $reader;
        $this->store     = $store;
        $this->events    = $events;
        $this->checks    = $checks;
    }

    public static function fromConfig(): self
    {
        $inventory = new CriticalComponentsInventory((array) config('critical_components'), base_path());

        return new self(
            $inventory,
            app(CriticalSettingsReader::class, ['inventory' => $inventory]),
            SettingsSnapshotStore::fromConfig(),
            app(SignificantEvents::class),
            new SettingsIntegrityCheckLog()
        );
    }

    /**
     * @param string[]             $scope    setting keys this save is about to write
     * @param array<string, mixed> $incoming the values it means to write them to, where
     *                                       the caller has them — reported as-is so a
     *                                       drift line says what was about to replace
     *                                       what
     *
     * @throws CriticalSettingsDriftException when a setting outside $scope disagrees
     */
    public function check(?int $skinId, array $scope, array $incoming = []): void
    {
        // Only saves that touch the certified configuration are gated. A save of some
        // unrelated tab is not the moment to discover a mismatch, and blocking it would
        // take the whole backoffice for that skin offline over a setting it never names.
        if ($this->criticalIn($skinId, $scope) === []) {
            return;
        }

        try {
            if (!$this->enabled()) {
                return;
            }

            $inGliScope = $this->inGliScope($skinId);

            if ($skinId !== null) {
                $this->regulatedBefore[$skinId] = $inGliScope;
            }

            if (!$inGliScope) {
                return;
            }

            $latest = $this->store->latest($skinId);

            if ($latest === null) {
                // A regulated skin with no baseline. Not the ordinary first-snapshot
                // case: that one is the save that ENROLS a skin, and it never reaches
                // here — check() runs before the write, so the flag still reads off and
                // the early return above catches it. Arriving here means the skin was
                // already in scope and its record is missing: the feature was switched on
                // after the fact, the store was lost, or somebody emptied it.
                //
                // Recorded and allowed through. Refusing would mean that enabling
                // snapshots, or losing a bucket, freezes every settings save until
                // somebody with a shell seeds each skin by hand. The cost is honest and
                // worth stating: the save that follows mints a baseline from whatever the
                // database currently holds, without any of it having been checked. That
                // is exactly what clearing the store would achieve, which is why this
                // event is written at critical rather than quietly logged.
                $this->events->write(SignificantEvents::MISSING_BASELINE, $skinId, [
                    'saving'   => array_values($scope),
                    'admin_id' => Auth::id(),
                ]);

                $this->logCheck($skinId, self::CHECK_NO_BASELINE);

                return;
            }

            $drift = $this->drift($skinId, $latest);
        } catch (CriticalSettingsDriftException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::critical(
                'CriticalSettingsGuard: could not check ' . SettingsSnapshotStore::scopeName($skinId) . ': ' . $e->getMessage()
            );

            $this->logCheck($skinId, self::CHECK_FAILED, null, [], $e->getMessage());

            return;
        }

        if ($drift === []) {
            $this->logCheck($skinId, self::CHECK_MATCHES, $latest['key']);

            return;
        }

        $explained = $this->explain($drift, $incoming);

        $this->events->write(SignificantEvents::DRIFT, $skinId, [
            'drift'       => $explained,
            'compared_to' => $latest['key'],
            'saving'      => array_values($scope),
            'admin_id'    => Auth::id(),
        ]);

        // Before the refusal below, so a blocked save is in the history too — that is the
        // row an auditor most needs.
        $this->logCheck($skinId, self::CHECK_DRIFT, $latest['key'], $explained);

        $outside = array_values(array_diff(array_keys($drift), $scope));

        if ($outside !== []) {
            // Through the container, so the application can bind a subclass that words and
            // renders the refusal its own way.
            throw app(CriticalSettingsDriftException::class, ['settings' => $outside, 'global' => $skinId === null]);
        }
    }

    /**
     * Writes this save's snapshot. Returns the key, or null when nothing was written.
     *
     * Never throws: by the time this runs the settings are already saved, and turning a
     * completed change into a 500 would leave the operator unsure what happened.
     *
     * @param string[] $scope setting keys this save wrote
     */
    public function record(?int $skinId, array $scope): ?string
    {
        try {
            if (!$this->enabled()) {
                return null;
            }

            $critical = $this->criticalIn($skinId, $scope);

            // A save that names no certified setting normally leaves no document behind:
            // it changed nothing this subsystem describes, and listing the store on every
            // unrelated backoffice save would be a cost paid for nothing.
            //
            // One exception, and it is cheap to detect. A regulated skin with no baseline
            // at all has to get one from somewhere, and the only thing that writes one is a
            // save. Requiring that save to touch a certified value would leave such a skin
            // in scope and unrecorded until somebody happened to edit the right field —
            // which is precisely the state MISSING_BASELINE exists to complain about. So
            // any save of a regulated skin lays the first document, whether or not it moved
            // a certified value, and whether or not it moved anything at all.
            //
            // Ordered cheapest first: config, then one indexed read, then the store. An
            // unregulated skin — most of them — costs a single query and never lists.
            //
            // The global scope never takes the exception: setSetting() writes job progress
            // markers all day, so only a save naming a certified global key lists the store.
            if ($critical === [] && ($skinId === null || !$this->reader->isRegulated($skinId))) {
                return null;
            }

            $latest = $this->store->latest($skinId);

            // Self-limiting: once the baseline exists, unrelated saves go back to being
            // free.
            if ($critical === [] && $latest !== null) {
                return null;
            }

            if (!$this->inScope($skinId, $latest)) {
                return null;
            }

            $values = $this->reader->values($skinId);
            $digest = SettingsSnapshot::settingsDigest($values);

            $previous = null;

            if ($latest !== null) {
                $previous = ['key' => $latest['key'], 'sha256' => $latest['sha256']];

                if ($this->unchanged($skinId, $latest['payload'], $digest)) {
                    // A save that rewrote rows without moving any value. The audit trail
                    // records that it happened; a second identical document in a store
                    // nothing can delete from would only make the history harder to read.
                    return null;
                }
            }

            $payload = (new SettingsSnapshot($this->inventory, new HostIdentity()))
                ->build($skinId, $values, $this->trigger($skinId, $scope), $previous);

            $bytes = SettingsSnapshot::canonicalJson($payload);

            $key = $this->store->put($skinId, $bytes, [
                'skin-id'           => SettingsSnapshotStore::metadataSkinId($skinId),
                'settings-sha256'   => $digest,
                'trigger-event'     => 'saved',
                'release-version'   => $this->inventory->version(),
                'inventory-version' => $this->inventory->inventoryVersion(),
            ]);

            Log::info(sprintf(
                'CriticalSettingsGuard: stored — scope=%s settings=%s key=%s',
                SettingsSnapshotStore::scopeName($skinId),
                substr($digest, 0, 12),
                $key
            ));

            return $key;
        } catch (Throwable $e) {
            // The outcome is live configuration with no record of how it got there, which
            // is indistinguishable from what check() exists to detect. Loud, and left for
            // the verifier to catch on its next run.
            Log::critical(sprintf(
                'CriticalSettingsGuard: snapshot FAILED for %s (%s): %s',
                SettingsSnapshotStore::scopeName($skinId),
                implode(', ', $this->criticalIn($skinId, $scope)),
                $e->getMessage()
            ));

            return null;
        }
    }

    /**
     * Compares this skin's live settings against its newest snapshot, and reports what it
     * found. Reads only.
     *
     * Same comparison as check(), same significant events, and nothing written to the
     * store. The operator-facing counterpart of what the guard does silently before every
     * save, for the question that has no save attached to it.
     *
     * WHY IT DOES NOT RECORD WHAT IT FINDS. Because a snapshot is a record of an
     * AUTHORISED CHANGE, and this authorises nothing — nobody decided anything, they asked
     * a question. A document written here would say a configuration was certified at a
     * moment when the only thing that happened was somebody looking at it, and if the
     * values had drifted it would adopt the drifted ones as the new baseline. That is the
     * laundering check() refuses during a save, and refusing it during a save while
     * offering it as a button would be worse than not offering the button.
     *
     * So drift stays drift until somebody saves the skin's settings, which is the act that
     * decides what the values should be. Until then this keeps reporting it, and the
     * significant event log keeps a line per time it was asked.
     *
     * Never throws. Every outcome is a CHECK_* status the caller can render.
     *
     * @return array{
     *     skin_id: int|null, status: string, compared_to: string|null,
     *     drift: array<string, array<string, string|null>>, settings: array<string, string|null>,
     *     checked_at: string, error: string|null
     * }
     */
    public function inspect(?int $skinId): array
    {
        $report = [
            'skin_id'     => $skinId,
            'status'      => self::CHECK_FAILED,
            'compared_to' => null,
            'drift'       => [],
            'settings'    => [],
            'checked_at'  => now('UTC')->toDateTimeString(),
            'error'       => null,
        ];

        try {
            if (!$this->enabled()) {
                $report['status'] = self::CHECK_DISABLED;

                return $report;
            }

            // Same refusal as the command's, and for the same reason: a skin outside GLI
            // scope has no certified configuration, so there is nothing to compare against
            // and nothing that a difference would mean. Not an error — an answer.
            if (!$this->inGliScope($skinId)) {
                $report['status'] = self::CHECK_NOT_REGULATED;

                return $report;
            }

            $report['settings'] = $this->reader->values($skinId);

            $latest = $this->store->latest($skinId);

            if ($latest === null) {
                // Same finding as check()'s, and the same event: a regulated skin with no
                // baseline is what clearing the store would look like. Unlike during a
                // save, nothing here fixes it — the next save of this skin's settings does.
                $this->events->write(SignificantEvents::MISSING_BASELINE, $skinId, [
                    'saving'   => [],
                    'admin_id' => Auth::id(),
                ]);

                $report['status'] = self::CHECK_NO_BASELINE;

                return $report;
            }

            $report['compared_to'] = $latest['key'];

            $drift = $this->drift($skinId, $latest);

            if ($drift === []) {
                $report['status'] = self::CHECK_MATCHES;

                return $report;
            }

            // The same shape check() logs, incoming column and all: it reads "(not written
            // by this save)" for every key, which is the literal truth here. One format for
            // the finding, whichever door it came through.
            $report['drift']  = $this->explain($drift, []);
            $report['status'] = self::CHECK_DRIFT;

            $this->events->write(SignificantEvents::DRIFT, $skinId, [
                'drift'       => $report['drift'],
                'compared_to' => $latest['key'],
                'saving'      => [],
                'admin_id'    => Auth::id(),
            ]);

            return $report;
        } catch (Throwable $e) {
            Log::critical(sprintf(
                'CriticalSettingsGuard: check FAILED for %s: %s',
                SettingsSnapshotStore::scopeName($skinId),
                $e->getMessage()
            ));

            $report['error'] = $e->getMessage();

            return $report;
        }
    }

    /**
     * The settings that disagree with the newest snapshot, for a page that is about to
     * offer them for editing. Reads only, and quietly.
     *
     * Same comparison as inspect(), without its significant events. Those record that
     * somebody asked; this runs whenever a skin tab is opened, and a line per page view
     * would bury the findings it exists to surface. The event is still written, by check(),
     * the moment anybody tries to save.
     *
     * Fails open to "nothing moved", like check(): a store outage must not stop the
     * backoffice from rendering a skin.
     *
     * @return array<string, array{snapshot: string|null, database: string|null}>
     */
    public function drifted(?int $skinId): array
    {
        try {
            if (!$this->enabled() || !$this->inGliScope($skinId)) {
                return [];
            }

            $latest = $this->store->latest($skinId);

            return $latest === null ? [] : $this->drift($skinId, $latest);
        } catch (Throwable $e) {
            Log::critical(sprintf(
                'CriticalSettingsGuard: could not compare %s for display: %s',
                SettingsSnapshotStore::scopeName($skinId),
                $e->getMessage()
            ));

            return [];
        }
    }

    /**
     * Registry keys whose stored value disagrees with the newest snapshot, each with the
     * two values that disagree.
     *
     * @param array{key: string, sha256: string, payload: array<string, mixed>} $latest
     *
     * @return array<string, array{snapshot: string|null, database: string|null}>
     */
    private function drift(?int $skinId, array $latest): array
    {
        $recorded = isset($latest['payload']['settings']) && is_array($latest['payload']['settings'])
            ? $latest['payload']['settings']
            : [];

        $live  = $this->reader->values($skinId);
        $drift = [];

        foreach ($recorded as $key => $value) {
            // A key the registry has since dropped, or gained after this document was
            // written. That is a scope change, recorded in settings_registry_sha256, and
            // saying nothing about whether anybody tampered with anything.
            if (!array_key_exists($key, $live)) {
                continue;
            }

            $was = $value === null ? null : (string) $value;

            if ($was !== $live[$key]) {
                $drift[(string) $key] = ['snapshot' => $was, 'database' => $live[$key]];
            }
        }

        ksort($drift);

        return $drift;
    }

    /**
     * The three values a reader of the log needs: what the snapshot recorded, what the
     * database holds now, and what this save was about to write.
     *
     * @param array<string, array{snapshot: string|null, database: string|null}> $drift
     * @param array<string, mixed>                                               $incoming
     *
     * @return array<string, array<string, string|null>>
     */
    private function explain(array $drift, array $incoming): array
    {
        $explained = [];

        foreach ($drift as $key => $values) {
            $explained[$key] = [
                'snapshot' => $values['snapshot'],
                'database' => $values['database'],
                'incoming' => array_key_exists($key, $incoming)
                    ? $this->readable($incoming[$key])
                    : self::NOT_IN_SAVE,
            ];
        }

        return $explained;
    }

    /**
     * Settings are stored as strings, but a caller can hand over an int, a bool or the
     * array some tabs post before it is encoded. Rendered rather than cast so the log
     * shows what was actually offered.
     *
     * @param mixed $value
     */
    private function readable($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    /**
     * Is there a certified configuration here to record?
     *
     * Normally: the skin is under GLI rules. The exception is the save that takes it out
     * of scope — by the time record() runs the flag already reads off, and dropping that
     * snapshot would make the exit invisible. The newest document settles it, and the
     * document written now records the flag as off, so the next save finds the door shut.
     *
     * @param array{key: string, sha256: string, payload: array<string, mixed>}|null $latest
     */
    private function inScope(?int $skinId, ?array $latest): bool
    {
        if ($this->inGliScope($skinId)) {
            return true;
        }

        // This save is the exit: it was in scope when check() looked, moments ago.
        if (!empty($this->regulatedBefore[$skinId])) {
            return true;
        }

        // Or the exit happened without check() seeing it — snapshots switched on midway,
        // a write from somewhere that did not go through the door. The newest document
        // settles it, and the one written now records the flag as off, so the next save
        // finds the door shut.
        $before = $latest === null
            ? null
            : ($latest['payload']['settings'][CriticalSettingsReader::REGULATED_KEY] ?? null);

        return (string) $before === '1';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function unchanged(?int $skinId, array $payload, string $digest): bool
    {
        $storedSettings = isset($payload['settings_sha256']) ? (string) $payload['settings_sha256'] : '';
        $storedRegistry = isset($payload['settings_registry_sha256']) ? (string) $payload['settings_registry_sha256'] : '';

        return $storedSettings === $digest
            && $storedRegistry === SettingsSnapshot::registryDigest($this->inventory->settingsKeysFor($skinId));
    }

    /**
     * @param string[] $scope
     *
     * @return array<string, mixed>
     */
    private function trigger(?int $skinId, array $scope): array
    {
        $adminId = Auth::id();

        return [
            'event' => 'saved',

            // The list, not a single name: one save is one snapshot, and naming only the
            // first of several settings would misreport what caused the document.
            'settings' => $this->criticalIn($skinId, $scope),

            'actor' => [
                'type'     => $adminId ? 'admin' : 'system',
                'admin_id' => $adminId ? (int) $adminId : null,
            ],

            // Explicitly UTC: SetTimezone makes the process default the operator's own
            // timezone on authenticated web requests, so a bare now() stores their clock.
            'occurred_at' => now('UTC')->toDateTimeString(),
        ];
    }

    /**
     * check()'s comparison, as a row in the Settings Integrity Check history. Never throws:
     * SettingsIntegrityCheckLog logs a failed write rather than letting it decide the save.
     *
     * @param array<string, array<string, string|null>> $drift
     */
    private function logCheck(
        ?int $skinId,
        string $status,
        ?string $comparedTo = null,
        array $drift = [],
        ?string $error = null
    ): void {
        if ($this->checks === null) {
            return;
        }

        $adminId = Auth::id();

        $this->checks->record(SettingsIntegrityCheck::TRIGGER_CONFIGURATION_CHANGE, [
            'skin_id'     => $skinId,
            'status'      => $status,
            'compared_to' => $comparedTo,
            'drift'       => $drift,
            'error'       => $error,
        ], $adminId ? (int) $adminId : null);
    }

    /**
     * @param string[] $scope
     *
     * @return string[]
     */
    private function criticalIn(?int $skinId, array $scope): array
    {
        $critical = array_values(array_intersect($scope, $this->inventory->settingsKeysFor($skinId)));
        sort($critical);

        return $critical;
    }

    /** A skin is in scope when it is GLI regulated; the global scope always is. */
    private function inGliScope(?int $skinId): bool
    {
        return $skinId === null || $this->reader->isRegulated($skinId);
    }

    private function enabled(): bool
    {
        return (bool) config('critical_components.snapshots.enabled', false)
            && $this->store->isConfigured();
    }
}
