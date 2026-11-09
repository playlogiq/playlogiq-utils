<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\Integrity;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\Integrity\Contracts\CriticalSettingsReader;
use PlaylogiqUtils\Integrity\Contracts\SignificantEvents;
use PlaylogiqUtils\Integrity\CriticalComponentsInventory;
use PlaylogiqUtils\Integrity\CriticalSettingsGuard;
use PlaylogiqUtils\Integrity\Exceptions\CriticalSettingsDriftException;
use PlaylogiqUtils\Integrity\Models\SettingsIntegrityCheck;
use PlaylogiqUtils\Integrity\SettingsIntegrityCheckLog;
use PlaylogiqUtils\Integrity\SettingsSnapshot;
use PlaylogiqUtils\Integrity\SettingsSnapshotStore;

/**
 * The save-time rule of CriticalSettingsGuard: a save over drift goes ahead when it writes at
 * least one of the drifted settings and is refused when it writes none of them, and the
 * snapshot taken after it never adopts a drifted value the save did not write.
 *
 * A bare container with just what the guard reaches for (config, auth, log, date), an
 * in-memory store and reader, and a history writer that keeps its rows: no database, no
 * bucket, no Testbench.
 */
class CriticalSettingsGuardTest extends TestCase
{
    /** @var array<string, string|null> the "database" */
    private $live = [];

    /** @var array<int, array<string, mixed>> snapshot payloads, oldest first */
    private $stored = [];

    /** @var array<int, array<string, mixed>> history rows the guard wrote */
    private $rows = [];

    /** @var string[] significant events written */
    private $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $container->instance('config', new class {
            /** @var array<string, mixed> */
            public $items = ['critical_components' => ['snapshots' => ['enabled' => true]]];

            public function get($key, $default = null)
            {
                return Arr::get($this->items, $key, $default);
            }
        });
        $container->instance('auth', new class {
            public function id()
            {
                return 7;
            }
        });
        $container->instance('log', new class {
            public function __call($method, $arguments)
            {
            }
        });
        $container->instance('date', new DateFactory());

        $this->live = ['bet_tax' => '10', 'bet_tax_perc' => '5', 'is_gli_regulated' => '1'];
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_no_drift_is_a_match_and_accepted(): void
    {
        $this->baseline();

        $this->guard()->check(3, ['bet_tax'], ['bet_tax' => '11']);

        $this->assertSame([[CriticalSettingsGuard::CHECK_MATCHES, SettingsIntegrityCheck::ACTION_ACCEPTED]], $this->outcomes());
        $this->assertSame([], $this->events);
    }

    public function test_a_drift_the_save_writes_none_of_is_rejected(): void
    {
        $this->baseline();
        $this->live['bet_tax_perc'] = '50';

        try {
            $this->guard()->check(3, ['bet_tax'], ['bet_tax' => '11']);
            $this->fail('The save was not refused.');
        } catch (CriticalSettingsDriftException $e) {
            $this->assertSame(['bet_tax_perc'], $e->settings());
        }

        $this->assertSame([[CriticalSettingsGuard::CHECK_DRIFT, SettingsIntegrityCheck::ACTION_REJECTED]], $this->outcomes());
        $this->assertSame(['50'], [$this->rows[0]['drift']['bet_tax_perc']['database']]);
        $this->assertSame([SignificantEvents::DRIFT], $this->events);
    }

    public function test_a_drift_the_save_writes_one_of_is_accepted_even_with_others_outside_it(): void
    {
        $this->baseline();
        $this->live['bet_tax']      = '99';
        $this->live['bet_tax_perc'] = '50';

        $this->guard()->check(3, ['bet_tax'], ['bet_tax' => '11']);

        $this->assertSame([[CriticalSettingsGuard::CHECK_DRIFT, SettingsIntegrityCheck::ACTION_ACCEPTED]], $this->outcomes());
        $this->assertSame(['bet_tax', 'bet_tax_perc'], array_keys($this->rows[0]['drift']));
        $this->assertSame([SignificantEvents::DRIFT], $this->events);
    }

    public function test_no_baseline_is_accepted(): void
    {
        $this->guard()->check(3, ['bet_tax']);

        $this->assertSame([[CriticalSettingsGuard::CHECK_NO_BASELINE, SettingsIntegrityCheck::ACTION_ACCEPTED]], $this->outcomes());
    }

    public function test_the_snapshot_after_the_save_keeps_the_old_value_of_a_drift_it_did_not_write(): void
    {
        $this->baseline();
        $this->live['bet_tax']      = '99';
        $this->live['bet_tax_perc'] = '50';

        $guard = $this->guard();
        $guard->check(3, ['bet_tax'], ['bet_tax' => '11']);
        $this->live['bet_tax'] = '11';

        $this->assertNotNull($guard->record(3, ['bet_tax']));

        $settings = end($this->stored)['settings'];
        // Written by the save: its new value. Not written: the last certified value, not the drift.
        $this->assertSame('11', $settings['bet_tax']);
        $this->assertSame('5', $settings['bet_tax_perc']);
        $this->assertSame(['bet_tax_perc' => ['snapshot' => '5', 'database' => '50']], $guard->drifted(3));
    }

    public function test_the_global_scope_follows_the_same_rule(): void
    {
        $this->live = ['disable_SettingsIntegrityCheck' => '0'];
        $this->baseline();
        $this->live['disable_SettingsIntegrityCheck'] = '1';

        $this->guard()->check(null, ['disable_SettingsIntegrityCheck'], ['disable_SettingsIntegrityCheck' => '1']);

        $this->assertSame([[CriticalSettingsGuard::CHECK_DRIFT, SettingsIntegrityCheck::ACTION_ACCEPTED]], $this->outcomes());
    }

    public function test_a_global_save_that_writes_none_of_the_drift_is_rejected(): void
    {
        // Two certified global keys: the save writes one, the other has drifted.
        $this->live = ['disable_Other' => '0', 'disable_SettingsIntegrityCheck' => '0'];
        $this->baseline();
        $this->live['disable_SettingsIntegrityCheck'] = '1';

        try {
            $this->guard(['integrity_checks' => ['disable_SettingsIntegrityCheck', 'disable_Other']])
                ->check(null, ['disable_Other'], ['disable_Other' => '1']);
            $this->fail('The save was not refused.');
        } catch (CriticalSettingsDriftException $e) {
            $this->assertSame(['disable_SettingsIntegrityCheck'], $e->settings());
        }

        $this->assertSame([[CriticalSettingsGuard::CHECK_DRIFT, SettingsIntegrityCheck::ACTION_REJECTED]], $this->outcomes());
        $this->assertSame([SignificantEvents::DRIFT], $this->events);
    }

    public function test_a_save_that_writes_a_drift_back_to_its_snapshot_value_writes_no_new_snapshot(): void
    {
        $this->baseline();
        $this->live['bet_tax']      = '99';
        $this->live['bet_tax_perc'] = '50';

        $guard = $this->guard();
        $guard->check(3, ['bet_tax'], ['bet_tax' => '10']);
        $this->live['bet_tax'] = '10';

        // bet_tax is back to its certified value and bet_tax_perc keeps it: the same document.
        $this->assertNull($guard->record(3, ['bet_tax']));
        $this->assertCount(1, $this->stored);
        $this->assertSame(['bet_tax_perc' => ['snapshot' => '5', 'database' => '50']], $guard->drifted(3));
    }

    public function test_the_save_that_re_enrols_a_skin_certifies_its_values_as_they_are(): void
    {
        // The exit snapshot: the skin left GLI scope, and was edited freely while outside it.
        $this->live['is_gli_regulated'] = '0';
        $this->baseline();
        $this->live['bet_tax_perc']     = '50';
        $this->live['is_gli_regulated'] = '1';

        $this->assertNotNull($this->guard()->record(3, ['is_gli_regulated']));

        $settings = end($this->stored)['settings'];
        $this->assertSame('50', $settings['bet_tax_perc']);
        $this->assertSame('1', $settings['is_gli_regulated']);
    }

    public function test_the_history_writer_sends_an_action_only_when_the_report_has_one(): void
    {
        // A table WITHOUT the action column: an insert naming it would fail.
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->schema()->create('settings_integrity_checks', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('skin_id')->nullable();
            $table->unsignedSmallInteger('trigger');
            $table->unsignedSmallInteger('status');
            $table->string('compared_to')->nullable();
            $table->text('drift')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
        });

        $report = ['skin_id' => 3, 'status' => 'matches', 'compared_to' => null, 'drift' => [], 'error' => null];
        $log    = new SettingsIntegrityCheckLog();

        $this->assertNotNull($log->record(SettingsIntegrityCheck::TRIGGER_MANUAL, $report, null));

        $capsule->schema()->table('settings_integrity_checks', function ($table) {
            $table->unsignedTinyInteger('action')->nullable();
        });
        $id = $log->record(SettingsIntegrityCheck::TRIGGER_CONFIGURATION_CHANGE, $report + ['action' => SettingsIntegrityCheck::ACTION_REJECTED], 7);

        $this->assertSame(SettingsIntegrityCheck::ACTION_REJECTED, SettingsIntegrityCheck::query()->find($id)->action);
        $this->assertNull(SettingsIntegrityCheck::query()->orderBy('id')->first()->action);
    }

    public function test_the_model_knows_the_actions_and_the_fixed_status(): void
    {
        $this->assertSame([1 => 'accepted', 2 => 'rejected'], SettingsIntegrityCheck::ACTIONS);
        $this->assertSame(SettingsIntegrityCheck::STATUS_FIXED, SettingsIntegrityCheck::statusFor(CriticalSettingsGuard::CHECK_FIXED));
        $this->assertSame('fixed', SettingsIntegrityCheck::STATUSES[7]);
        $this->assertContains('action', (new SettingsIntegrityCheck())->getFillable());
    }

    // ---- Helpers ------------------------------------------------------------------------

    /** Records the current live values as the newest snapshot, digests and all. */
    private function baseline(): void
    {
        $values = $this->liveValues();

        $this->stored[] = [
            'settings'                 => $values,
            'settings_sha256'          => SettingsSnapshot::settingsDigest($values),
            'settings_registry_sha256' => SettingsSnapshot::registryDigest(array_keys($values)),
        ];
    }

    /** @return array<int, array{0: string, 1: int|null}> */
    private function outcomes(): array
    {
        return array_map(function (array $row) {
            return [$row['status'], $row['action'] ?? null];
        }, $this->rows);
    }

    /** @param array<string, string[]>|null $globalRegistry */
    private function guard(?array $globalRegistry = null): CriticalSettingsGuard
    {
        $test = $this;

        $inventory = new CriticalComponentsInventory([
            'version'           => '1.0.0',
            'inventory_version' => '1.5.0',
            'components'        => [],
            'settings'          => [
                'registry'        => ['tax' => ['bet_tax', 'bet_tax_perc'], 'gli' => ['is_gli_regulated']],
                'global_registry' => $globalRegistry ?? ['integrity_checks' => ['disable_SettingsIntegrityCheck']],
            ],
        ], sys_get_temp_dir());

        $reader = new class($test) implements CriticalSettingsReader {
            /** @var CriticalSettingsGuardTest */
            private $test;

            public function __construct(CriticalSettingsGuardTest $test)
            {
                $this->test = $test;
            }

            public function regulatedSkinIds(): array
            {
                return [3];
            }

            public function isRegulated(int $skinId): bool
            {
                return true;
            }

            public function values(?int $skinId): array
            {
                return $this->test->liveValues();
            }
        };

        $store = new class($test) extends SettingsSnapshotStore {
            /** @var CriticalSettingsGuardTest */
            private $test;

            public function __construct(CriticalSettingsGuardTest $test)
            {
                parent::__construct([]);
                $this->test = $test;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function latest(?int $skinId): ?array
            {
                return $this->test->latestSnapshot();
            }

            public function put(?int $skinId, string $bytes, array $metadata = []): string
            {
                return $this->test->storeSnapshot(json_decode($bytes, true));
            }
        };

        $events = new class($test) implements SignificantEvents {
            /** @var CriticalSettingsGuardTest */
            private $test;

            public function __construct(CriticalSettingsGuardTest $test)
            {
                $this->test = $test;
            }

            public function write(string $event, ?int $skinId, array $context = []): void
            {
                $this->test->event($event);
            }

            public function controlProgramFailed(bool $unverifiable, ?int $instanceRowId, string $host, array $failedChecks, string $reason, ?int $adminId = null): void
            {
            }

            public function fleetCheckNotSent(int $checkId, string $reason, ?int $adminId = null): void
            {
            }
        };

        $checks = new class($test) extends SettingsIntegrityCheckLog {
            /** @var CriticalSettingsGuardTest */
            private $test;

            public function __construct(CriticalSettingsGuardTest $test)
            {
                $this->test = $test;
            }

            public function record(int $trigger, array $report, ?int $userId): ?int
            {
                $this->test->row($report);

                return 1;
            }
        };

        return new CriticalSettingsGuard($inventory, $reader, $store, $events, $checks);
    }

    /** @internal for the stubs above */
    public function liveValues(): array
    {
        ksort($this->live);

        return $this->live;
    }

    /** @internal */
    public function latestSnapshot(): ?array
    {
        if ($this->stored === []) {
            return null;
        }

        return ['key' => 'snapshot-' . count($this->stored), 'sha256' => 'x', 'payload' => end($this->stored)];
    }

    /** @internal */
    public function storeSnapshot(array $payload): string
    {
        $this->stored[] = $payload;

        return 'snapshot-' . count($this->stored);
    }

    /** @internal */
    public function event(string $event): void
    {
        $this->events[] = $event;
    }

    /** @internal */
    public function row(array $report): void
    {
        $this->rows[] = $report;
    }
}
