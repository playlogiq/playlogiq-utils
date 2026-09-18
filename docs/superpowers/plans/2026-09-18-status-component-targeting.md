# Per-Project Component Targeting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each project point the status checks at its own connection names and switch off the services it does not run, from one config block.

**Architecture:** `status.checks`, `status.critical` and `status.read_connection` collapse into a single `status.components` map holding `enabled`, a target (`connection` or `store`), and `critical` per component. In the service, the two probe-name constants merge into one `COMPONENTS` map carrying both probe variants plus target metadata; `run()` and `runReadiness()` resolve each component's target from config and pass it into the already-generic probe bodies. Eight wrapper methods that existed only to hardcode a target are deleted.

**Tech Stack:** PHP 7.4+, Laravel 5.8–9 (`illuminate/support`, `illuminate/database`, `illuminate/encryption`, `laravel-zero/foundation`), Composer VCS package distribution, Laravel package auto-discovery.

**Spec:** `docs/superpowers/specs/2026-09-18-status-component-targeting-design.md`

## Global Constraints

- **PHP floor is `>=7.4`.** No `match`, no nullsafe `?->`, no promoted constructor properties, no `str_starts_with` / `str_contains` / `str_ends_with`, no union types, no enums, no first-class callable syntax. Typed properties, nullable types, `declare(strict_types=1)`, arrow functions and argument unpacking (`...$args`) are fine.
- **Laravel floor is `^5.8`**, ceiling `^9.0`.
- **No new Composer dependencies.** `illuminate/encryption` was added by the previous plan and is already in `require`; nothing further.
- **Namespace root is `PlaylogiqUtils\`**, PSR-4 mapped to `src/`.
- **This amends PR #3, it does not follow it.** PR #3 is open, unmerged and has no consumers, so the `checks` / `critical` / `read_connection` keys are replaced outright. There is no deprecation path and no backward-compatibility shim to write.
- **Probe bodies do not change**, with exactly one exception: `probeCache` (Task 3). The MySQL, Mongo, Redis, queue, storage and Passport probe logic is already correct and already generic — this work changes only which target they are handed.
- **No test harness.** Verification is `plqlint` plus throwaway smoke scripts in `$SCRATCH`, never committed. The resolution logic added here is genuinely unit-testable without infrastructure, so Tasks 1–4 are test-first.

## Toolchain

**The host's default `php` is broken** — `/opt/homebrew/bin/php` is php@7.1 with a missing `libaspell` dylib and will not start. Never invoke bare `php`. Export these once per shell:

```bash
export SCRATCH=/private/tmp/claude-501/-Users-mateomartinez-development-pq-docker-src-playlogiq-utils/f35c9d30-ac0f-4dce-b9f6-66a93b66d1d8/scratchpad
export PHP=/opt/homebrew/opt/php@8.4/bin/php
export REPO=/Users/mateomartinez/development/pq-docker/src/playlogiq-utils/.worktrees/PQPL-6496-status-checks
```

| Job | Command | Why |
|---|---|---|
| Syntax check | `"$SCRATCH/plqlint" <file>` | Lints inside the `app` container on **PHP 7.4.33**, the package's floor. |
| Run a script | `$PHP <script>` | Host php@8.4 — the only working host PHP. |
| Composer | `$PHP /usr/local/bin/composer <cmd>` | Never as a two-word `$COMPOSER` variable; it will not word-split. |

`plqlint` already exists from the previous plan. If it is missing, recreate it per the Toolchain section of `2026-09-18-status-check-sharing.md`.

**Faking `config()` in smoke scripts.** Laravel's `config()` helper is declared inside `if (! function_exists('config'))` in `vendor/illuminate/support/helpers.php`, which composer loads via its `files` autoload. Declaring `config()` *before* `require`ing the autoloader therefore wins, and no container is needed. Every smoke script below uses this shared prelude — write it once:

```bash
cat > "$SCRATCH/config_stub.php" <<'STUB'
<?php

// Declared before vendor/autoload.php so Laravel's function_exists()-guarded
// config() helper never takes effect. $GLOBALS['__cfg'] holds a nested array;
// keys are read with dot notation, exactly like the real helper.
$GLOBALS['__cfg'] = [];

function config($key = null, $default = null)
{
    if ($key === null) {
        return $GLOBALS['__cfg'];
    }

    $cur = $GLOBALS['__cfg'];

    foreach (explode('.', $key) as $segment) {
        if (! is_array($cur) || ! array_key_exists($segment, $cur)) {
            return $default;
        }

        $cur = $cur[$segment];
    }

    return $cur;
}
STUB
echo "config stub written"
```

---

## File Structure

| File | Change |
|---|---|
| `config/status.php` | `checks` / `critical` / `read_connection` replaced by the `components` map; `readiness.checks` default renamed. |
| `src/Status/StatusCheckService.php` | `CHECKS` + `READINESS_CHECKS` → one `COMPONENTS` map; `measure()` takes probe arguments; `run()` / `runReadiness()` resolve targets; eight wrappers deleted; `probeCache` honours its store. |
| `README.md` | Status-checks section rewritten for the new config shape and env vars. |
| `docs/superpowers/plans/2026-09-18-status-check-sharing.md` | Task 7 (betmaker-bo switchover) updated for the new env vars and the readiness rename. |

---

## Task 1: Replace the config keys with the components map

Config first, because the service's resolution logic in Task 2 is written against this shape and its smoke test reads this file.

**Files:**
- Modify: `config/status.php`

**Interfaces:**

- Consumes: nothing.
- Produces: the `status.components` and `status.readiness.checks` config shape.
  - `status.components` — map of component name → `['enabled' => bool, 'connection' => string, 'store' => ?string, 'critical' => bool]`. `connection` and `store` are present only where that component has a target; `critical` absent means false.
  - `status.checks`, `status.critical` and `status.read_connection` no longer exist.

- [ ] **Step 1: Write the failing config-shape check**

Create `$SCRATCH/config_shape_smoke.php`:

```php
<?php

$failures = 0;

function check(string $label, $actual, $expected): void
{
    global $failures;

    if ($actual === $expected) {
        echo "PASS  {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL  {$label}: expected " . var_export($expected, true)
        . ', got ' . var_export($actual, true) . "\n";
}

function env($key, $default = null)
{
    return $default;
}

$c = require '/Users/mateomartinez/development/pq-docker/src/playlogiq-utils/.worktrees/PQPL-6496-status-checks/config/status.php';

// The three replaced keys are gone.
check('checks removed', array_key_exists('checks', $c), false);
check('critical removed', array_key_exists('critical', $c), false);
check('read_connection removed', array_key_exists('read_connection', $c), false);

// The components map exists, in report order.
check('components present', is_array($c['components'] ?? null), true);
check('component order', array_keys($c['components']), [
    'mysql', 'mysql_read', 'mysql_ro', 'mysql_bo', 'mongodb',
    'redis', 'redis_others', 'cache', 'queue', 'storage', 'passport_keys',
]);

// Services most projects do not run ship disabled.
foreach (['mysql_ro', 'mysql_bo', 'mongodb', 'redis_others', 'passport_keys'] as $off) {
    check("{$off} defaults off", $c['components'][$off]['enabled'], false);
}

// The universal ones ship enabled.
foreach (['mysql', 'mysql_read', 'redis', 'cache', 'queue', 'storage'] as $on) {
    check("{$on} defaults on", $c['components'][$on]['enabled'], true);
}

// Criticality moved into the map, and only these three are critical.
$critical = [];
foreach ($c['components'] as $name => $cfg) {
    if (! empty($cfg['critical'])) {
        $critical[] = $name;
    }
}
check('critical set', $critical, ['mysql', 'redis', 'cache']);

// Targets.
check('mysql target', $c['components']['mysql']['connection'], 'mysql');
check('mysql_read shares the mysql target', $c['components']['mysql_read']['connection'], 'mysql');
check('mysql_ro target', $c['components']['mysql_ro']['connection'], 'mysql_ro');
check('mysql_bo target', $c['components']['mysql_bo']['connection'], 'mysql_bo');
check('mongodb target', $c['components']['mongodb']['connection'], 'mongodb');
check('redis target', $c['components']['redis']['connection'], 'default');
check('redis_others target', $c['components']['redis_others']['connection'], 'others');
check('cache store follows cache.default', $c['components']['cache']['store'], null);

// Components without a target declare neither key.
foreach (['queue', 'storage', 'passport_keys'] as $targetless) {
    check("{$targetless} has no connection", array_key_exists('connection', $c['components'][$targetless]), false);
    check("{$targetless} has no store", array_key_exists('store', $c['components'][$targetless]), false);
}

// Readiness names components from the map, plus the two self-checks.
check('readiness default', $c['readiness']['checks'], ['mysql', 'redis', 'config', 'app_key', 'storage']);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$PHP "$SCRATCH/config_shape_smoke.php"
```

Expected: FAIL lines for `checks removed`, `critical removed`, `read_connection removed` and `components present`, then a failure count. The current config still has the old shape.

- [ ] **Step 3: Replace the `checks` and `critical` blocks with the components map**

In `config/status.php`, delete the `Enabled Checks` comment block and its `'checks' => …` line, the `Critical Checks` comment block and its `'critical' => …` line, and the `Read Replica Connection` comment block and its `'read_connection' => …` line. Replace all three with:

```php
    /*
    |--------------------------------------------------------------------------
    | Components
    |--------------------------------------------------------------------------
    |
    | Every component the status report knows how to probe, in report order.
    | One entry per component, holding three things:
    |
    |   enabled     Whether this project runs the service at all. A disabled
    |               component is absent from the report AND excluded from
    |               readiness, even if readiness.checks names it — "we do not
    |               run Mongo" is one fact, stated once.
    |   connection  The database or Redis connection to probe. Projects that
    |   / store     name their connections differently point these at their own
    |               names. `cache` takes a `store` instead; null follows
    |               cache.default.
    |   critical    Only a failing critical component makes GET /status answer
    |               503. Everything else degrades the report while still
    |               answering 200, so a peripheral outage does not deregister
    |               every instance behind the load balancer. Absent means false.
    |
    | Components a typical project does not run ship disabled: a read replica,
    | a backoffice database, Mongo, a second Redis, and Passport keys. Turn on
    | what you have.
    |
    | Readiness reuses these targets; see the readiness block below.
    |
    */

    'components' => [

        'mysql' => [
            'enabled' => (bool) env('STATUS_CHECK_MYSQL', true),
            'connection' => env('STATUS_CONN_MYSQL', 'mysql'),
            'critical' => true,
        ],

        // The read PDO of the same connection as `mysql`, which is why it
        // shares STATUS_CONN_MYSQL: pointing them at different databases would
        // make the report describe a split that does not exist.
        'mysql_read' => [
            'enabled' => (bool) env('STATUS_CHECK_MYSQL_READ', true),
            'connection' => env('STATUS_CONN_MYSQL', 'mysql'),
        ],

        'mysql_ro' => [
            'enabled' => (bool) env('STATUS_CHECK_MYSQL_RO', false),
            'connection' => env('STATUS_CONN_MYSQL_RO', 'mysql_ro'),
        ],

        'mysql_bo' => [
            'enabled' => (bool) env('STATUS_CHECK_MYSQL_BO', false),
            'connection' => env('STATUS_CONN_MYSQL_BO', 'mysql_bo'),
        ],

        'mongodb' => [
            'enabled' => (bool) env('STATUS_CHECK_MONGODB', false),
            'connection' => env('STATUS_CONN_MONGODB', 'mongodb'),
        ],

        'redis' => [
            'enabled' => (bool) env('STATUS_CHECK_REDIS', true),
            'connection' => env('STATUS_CONN_REDIS', 'default'),
            'critical' => true,
        ],

        'redis_others' => [
            'enabled' => (bool) env('STATUS_CHECK_REDIS_OTHERS', false),
            'connection' => env('STATUS_CONN_REDIS_OTHERS', 'others'),
        ],

        'cache' => [
            'enabled' => (bool) env('STATUS_CHECK_CACHE', true),
            'store' => env('STATUS_CACHE_STORE', null),
            'critical' => true,
        ],

        'queue' => [
            'enabled' => (bool) env('STATUS_CHECK_QUEUE', true),
        ],

        'storage' => [
            'enabled' => (bool) env('STATUS_CHECK_STORAGE', true),
        ],

        'passport_keys' => [
            'enabled' => (bool) env('STATUS_CHECK_PASSPORT_KEYS', false),
        ],

    ],
```

- [ ] **Step 4: Rename the readiness default**

The readiness set now names components from the map above rather than a parallel vocabulary. Change:

```php
        'checks' => $statusList('STATUS_READY_CHECKS', 'database,redis,config,app_key,storage'),
```

to:

```php
        'checks' => $statusList('STATUS_READY_CHECKS', 'mysql,redis,config,app_key,storage'),
```

And in the readiness comment block above it, replace the component glossary lines:

```
    |   database        primary MySQL — connect (which authenticates) + query
    |   database_read   the mysql_ro replica core models read through
```

with:

```
    |   mysql           primary MySQL — connect (which authenticates) + query
    |   mysql_ro        the read replica core models read through
```

so the glossary matches the names the set now uses.

- [ ] **Step 5: Run the smoke script to verify it passes**

```bash
$PHP "$SCRATCH/config_shape_smoke.php"
```

Expected: every line `PASS`, final line `ALL PASS`, exit 0.

- [ ] **Step 6: Lint**

```bash
"$SCRATCH/plqlint" config/status.php
```

Expected: `OK (php7.4)  config/status.php`.

- [ ] **Step 7: Commit**

```bash
cd "$REPO"
git add config/status.php
git commit -m "PQPL-6496: replace status check lists with a components map

Each component now declares enabled, its connection or store target, and
whether it is critical, in one place. Services a typical project does not
run ship disabled rather than relying on skip-when-unconfigured to make
them harmless.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Resolve targets in the service

The core of the work. Two constants become one, `measure()` learns to pass arguments, and the eight target-hardcoding wrappers disappear.

**Files:**
- Modify: `src/Status/StatusCheckService.php`

**Interfaces:**

- Consumes: `status.components` and `status.readiness.checks` from Task 1.
- Produces:
  - `const COMPONENTS` — component name → `['probe' => ?string, 'ready' => ?string, 'target' => ?string, 'read' => ?bool, 'role' => ?string]`
  - `resolveComponents(string $set): array` (private) — `$set` is `'report'` or `'readiness'`; returns component name → `['probe' => string, 'args' => array, 'critical' => bool]` for every component that should run, in `COMPONENTS` order.
  - `measure(string $name, string $probe, array $args = []): ComponentStatus` — unchanged behaviour, now forwards `$args` to the probe.
  - `availableChecks(): array` — names with a `probe` entry.
  - `availableReadinessChecks(): array` — names with a `ready` entry.
  - `run(array $only = []): StatusReport` and `runReadiness(): StatusReport` — signatures unchanged.

- [ ] **Step 1: Write the failing resolution test**

This is the whole point of the task, and it runs without a database: `resolveComponents()` decides what runs and with which target, and nothing else.

Create `$SCRATCH/resolve_smoke.php`:

```php
<?php

require '/private/tmp/claude-501/-Users-mateomartinez-development-pq-docker-src-playlogiq-utils/f35c9d30-ac0f-4dce-b9f6-66a93b66d1d8/scratchpad/config_stub.php';
require '/Users/mateomartinez/development/pq-docker/src/playlogiq-utils/.worktrees/PQPL-6496-status-checks/vendor/autoload.php';

use PlaylogiqUtils\Status\StatusCheckService;

$failures = 0;

function check(string $label, $actual, $expected): void
{
    global $failures;

    if ($actual === $expected) {
        echo "PASS  {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL  {$label}: expected " . var_export($expected, true)
        . ', got ' . var_export($actual, true) . "\n";
}

function resolve(array $components, array $readiness, string $set): array
{
    $GLOBALS['__cfg'] = [
        'status' => [
            'components' => $components,
            'readiness' => ['checks' => $readiness],
        ],
    ];

    $m = new ReflectionMethod(StatusCheckService::class, 'resolveComponents');
    $m->setAccessible(true);

    return $m->invoke(new StatusCheckService(), $set);
}

// A disabled component never runs, in either set.
$r = resolve(
    [
        'mysql' => ['enabled' => true, 'connection' => 'primary', 'critical' => true],
        'mongodb' => ['enabled' => false, 'connection' => 'mongodb'],
    ],
    ['mysql', 'mongodb'],
    'report'
);
check('enabled component runs', array_keys($r), ['mysql']);
check('target reaches the probe', $r['mysql']['args'][0], 'primary');
check('critical carried through', $r['mysql']['critical'], true);

// enabled:false beats readiness membership — the rule the whole design rests on.
$r = resolve(
    [
        'mysql' => ['enabled' => true, 'connection' => 'primary'],
        'mongodb' => ['enabled' => false, 'connection' => 'mongodb'],
    ],
    ['mysql', 'mongodb'],
    'readiness'
);
check('disabled excluded from readiness', array_keys($r), ['mysql']);

// Readiness membership is opt-in: an enabled component not listed stays out.
$r = resolve(
    [
        'mysql' => ['enabled' => true, 'connection' => 'primary'],
        'redis' => ['enabled' => true, 'connection' => 'default'],
    ],
    ['mysql'],
    'readiness'
);
check('unlisted component stays out of readiness', array_keys($r), ['mysql']);

// Readiness uses the strict probe variant, the report uses the plain one.
$r = resolve(['mysql' => ['enabled' => true, 'connection' => 'primary']], ['mysql'], 'report');
check('report probe', $r['mysql']['probe'], 'probeMysql');
$r = resolve(['mysql' => ['enabled' => true, 'connection' => 'primary']], ['mysql'], 'readiness');
check('readiness probe', $r['mysql']['probe'], 'probeAuthenticatedDatabase');
check('readiness role argument', $r['mysql']['args'][2], 'primary');

// A component with no report probe never appears in the report.
$r = resolve(['config' => ['enabled' => true]], ['config'], 'report');
check('config absent from report', array_keys($r), []);
$r = resolve(['config' => ['enabled' => true]], ['config'], 'readiness');
check('config present in readiness', array_keys($r), ['config']);
check('config takes no arguments', $r['config']['args'], []);

// A component absent from config entirely defaults to enabled, so that a
// project publishing an older config file does not silently lose checks.
$r = resolve([], ['mysql'], 'readiness');
check('missing config entry defaults enabled', array_keys($r), ['mysql']);
check('missing entry falls back to the default target', $r['mysql']['args'][0], 'mysql');

// Read PDO selection.
$r = resolve(['mysql_read' => ['enabled' => true, 'connection' => 'primary']], [], 'report');
check('mysql_read uses the read PDO', $r['mysql_read']['args'][1], true);
$r = resolve(['mysql' => ['enabled' => true, 'connection' => 'primary']], [], 'report');
check('mysql uses the write PDO', $r['mysql']['args'][1], false);

// Cache targets a store, not a connection.
$r = resolve(['cache' => ['enabled' => true, 'store' => 'redis']], [], 'report');
check('cache store reaches the probe', $r['cache']['args'][0], 'redis');

// Report order follows COMPONENTS, not config order.
$r = resolve(
    [
        'queue' => ['enabled' => true],
        'mysql' => ['enabled' => true, 'connection' => 'mysql'],
        'cache' => ['enabled' => true, 'store' => null],
    ],
    [],
    'report'
);
check('report order is canonical', array_keys($r), ['mysql', 'cache', 'queue']);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$PHP "$SCRATCH/config_stub.php" >/dev/null 2>&1 || true
$PHP "$SCRATCH/resolve_smoke.php"
```

Expected: a fatal `ReflectionException: Method PlaylogiqUtils\Status\StatusCheckService::resolveComponents() does not exist`. If the config stub has not been written yet, write it first from the Toolchain section.

- [ ] **Step 3: Replace the two constants with one**

Delete the `CHECKS` constant (its docblock and all 11 entries) and the `READINESS_CHECKS` constant (its docblock and all 8 entries). In their place:

```php
    /**
     * Every component, in report order.
     *
     * `probe`  the method that probes it for GET /status; absent means the
     *          component is readiness-only (the config and APP_KEY self-checks).
     * `ready`  the method that probes it for readiness; absent means the
     *          component cannot be part of the readiness set. Readiness uses a
     *          stricter variant where one exists: it authenticates, and it
     *          fails rather than skips when the connection is missing, because
     *          readiness answers "may this instance take traffic?".
     * `target` which config key holds the component's target — `connection`
     *          for databases and Redis, `store` for the cache, absent for the
     *          components that have no target.
     * `read`   probe the read host of a read/write split connection.
     * `role`   the label probeAuthenticatedDatabase() reports.
     */
    private const COMPONENTS = [
        'mysql' => ['probe' => 'probeMysql', 'ready' => 'probeAuthenticatedDatabase', 'target' => 'connection', 'role' => 'primary'],
        'mysql_read' => ['probe' => 'probeMysql', 'target' => 'connection', 'read' => true],
        'mysql_ro' => ['probe' => 'probeMysql', 'ready' => 'probeAuthenticatedDatabase', 'target' => 'connection', 'read' => true, 'role' => 'read replica'],
        'mysql_bo' => ['probe' => 'probeMysql', 'target' => 'connection'],
        'mongodb' => ['probe' => 'probeMongodb', 'ready' => 'probeReadinessMongodb', 'target' => 'connection'],
        'redis' => ['probe' => 'probeRedis', 'ready' => 'probeReadinessRedis', 'target' => 'connection'],
        'redis_others' => ['probe' => 'probeRedis', 'target' => 'connection'],
        'cache' => ['probe' => 'probeCache', 'target' => 'store'],
        'queue' => ['probe' => 'probeQueue'],
        'storage' => ['probe' => 'probeStorage', 'ready' => 'probeStorage'],
        'passport_keys' => ['probe' => 'probePassportKeys', 'ready' => 'probePassportKeys'],
        'config' => ['ready' => 'probeConfig'],
        'app_key' => ['ready' => 'probeAppKey'],
    ];

    /**
     * Default target per component, used when the config has no entry for it.
     * Keeps a project that publishes an older config file working rather than
     * silently dropping checks.
     */
    private const DEFAULT_TARGETS = [
        'mysql' => 'mysql',
        'mysql_read' => 'mysql',
        'mysql_ro' => 'mysql_ro',
        'mysql_bo' => 'mysql_bo',
        'mongodb' => 'mongodb',
        'redis' => 'default',
        'redis_others' => 'others',
        'cache' => null,
    ];
```

- [ ] **Step 4: Rewrite the two `available*` methods**

Replace both bodies so they read from the merged map:

```php
    /**
     * @return string[] every component name the report knows how to probe
     */
    public static function availableChecks(): array
    {
        $names = [];

        foreach (self::COMPONENTS as $name => $meta) {
            if (isset($meta['probe'])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return string[] every component name the readiness set can contain
     */
    public static function availableReadinessChecks(): array
    {
        $names = [];

        foreach (self::COMPONENTS as $name => $meta) {
            if (isset($meta['ready'])) {
                $names[] = $name;
            }
        }

        return $names;
    }
```

- [ ] **Step 5: Add `resolveComponents()`**

Insert directly above `measure()`:

```php
    /**
     * Decides which components run and with which arguments.
     *
     * @param string $set 'report' or 'readiness'
     * @return array<string, array{probe: string, args: array, critical: bool}>
     */
    private function resolveComponents(string $set): array
    {
        $configured = (array) config('status.components', []);
        $readinessSet = (array) config('status.readiness.checks', self::availableReadinessChecks());
        $readiness = $set === 'readiness';
        $resolved = [];

        foreach (self::COMPONENTS as $name => $meta) {
            $probe = $readiness ? ($meta['ready'] ?? null) : ($meta['probe'] ?? null);

            if ($probe === null) {
                continue;
            }

            $entry = (array) ($configured[$name] ?? []);

            // A component absent from config counts as enabled: a published
            // config file predating a new component should gain it, not lose it.
            if (! (bool) ($entry['enabled'] ?? true)) {
                continue;
            }

            if ($readiness && ! in_array($name, $readinessSet, true)) {
                continue;
            }

            $resolved[$name] = [
                'probe' => $probe,
                'args' => $this->probeArguments($name, $meta, $entry, $readiness),
                'critical' => $readiness ? true : (bool) ($entry['critical'] ?? false),
            ];
        }

        return $resolved;
    }

    /**
     * Builds the positional argument list for a component's probe.
     *
     * @param array<string, mixed> $meta  the COMPONENTS entry
     * @param array<string, mixed> $entry the config entry
     */
    private function probeArguments(string $name, array $meta, array $entry, bool $readiness): array
    {
        $target = $meta['target'] ?? null;

        if ($target === null) {
            return [];
        }

        $value = array_key_exists($target, $entry)
            ? $entry[$target]
            : (self::DEFAULT_TARGETS[$name] ?? null);

        if ($target === 'store') {
            return [$value];
        }

        $args = [(string) $value, (bool) ($meta['read'] ?? false)];

        if ($readiness && isset($meta['role'])) {
            $args[] = $meta['role'];
        }

        return $args;
    }
```

- [ ] **Step 6: Teach `measure()` to forward arguments**

Change its signature and the one call it makes:

```php
    private function measure(string $name, string $probe, array $args = []): ComponentStatus
```

and inside the `try`:

```php
            $result = $this->{$probe}(...$args);
```

Everything else in `measure()` is unchanged.

- [ ] **Step 7: Rewrite `run()` and `runReadiness()`**

```php
    /**
     * @param string[] $only restrict the run to these component names
     */
    public function run(array $only = []): StatusReport
    {
        $startedAt = microtime(true);
        $components = [];

        foreach ($this->resolveComponents('report') as $name => $plan) {
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            $components[$name] = $this->measure($name, $plan['probe'], $plan['args'])
                ->withCritical($plan['critical']);
        }

        return new StatusReport($components, $this->appMeta(), (microtime(true) - $startedAt) * 1000);
    }
```

```php
    /**
     * Runs the readiness set. Every component is marked critical, so a single
     * failure makes the report unhealthy and the endpoint answer 503.
     */
    public function runReadiness(): StatusReport
    {
        $startedAt = microtime(true);
        $components = [];

        foreach ($this->resolveComponents('readiness') as $name => $plan) {
            $components[$name] = $this->measure($name, $plan['probe'], $plan['args'])
                ->withCritical(true);
        }

        return new StatusReport($components, $this->appMeta(), (microtime(true) - $startedAt) * 1000);
    }
```

- [ ] **Step 8: Delete the eight target-hardcoding wrappers**

Each exists only to bake in a name that now comes from config. Delete these methods entirely, docblocks included:

- `probeMysqlWrite()`
- `probeMysqlRead()`
- `probeMysqlReadOnlyConnection()`
- `probeMysqlBackoffice()`
- `probeRedisDefault()`
- `probeRedisOthers()`
- `probeReadinessDatabase()`
- `probeReadinessDatabaseRead()`

`probeMysql(string $connection, bool $useReadPdo)`, `probeRedis(string $connection)` and `probeAuthenticatedDatabase(string $connection, bool $useReadPdo, string $role)` stay exactly as they are — they already take the target as an argument.

- [ ] **Step 9: Parameterise the three probes that still hardcode a name**

`probeMongodb()` takes the connection and stops reading `'mongodb'` literally. Change its signature and the two literals in its body:

```php
    private function probeMongodb(string $connection): array
    {
        $config = (array) config('database.connections.' . $connection);

        if ($config === []) {
            return ['status' => ComponentStatus::SKIPPED, 'error' => "connection [{$connection}] is not configured"];
        }
```

and further down, `DB::connection('mongodb')` becomes `DB::connection($connection)`.

`probeReadinessMongodb()` likewise:

```php
    private function probeReadinessMongodb(string $connection): array
    {
        $config = (array) config('database.connections.' . $connection);

        if ($config === []) {
            throw new RuntimeException("connection [{$connection}] is not configured");
        }
```

and `DB::connection('mongodb')` becomes `DB::connection($connection)`. Its closing `withAuthenticationVerdict(...)` call passes `'mongodb'` as its last argument — that is the *component label*, not a connection name, and stays as is.

`probeReadinessRedis()` takes the connection and replaces three literals:

```php
    private function probeReadinessRedis(string $connection): array
    {
        $seeds = $this->redisSeeds($connection);

        if ($seeds === []) {
            throw new RuntimeException("redis connection [{$connection}] is not configured");
        }
```

then `Redis::connection('default')` becomes `Redis::connection($connection)`, and in the details array `'connection' => 'default'` becomes `'connection' => $connection`.

Note that `probeArguments()` hands every connection-targeted probe the same shape, `[$connection, $useReadPdo]`, so `probeMongodb`, `probeReadinessMongodb`, `probeRedis` and `probeReadinessRedis` each receive a second argument they do not declare. **This is fine and needs no extra parameter** — PHP accepts surplus arguments to a userland function and simply discards them, on 7.4 and 8.x alike. Verify it rather than take it on faith:

```bash
printf '<?php\ndeclare(strict_types=1);\nclass T { private function one(string $a): string { return $a; } public function go() { return $this->one("x", false); } }\necho (new T)->go(), "\\n";\n' > /tmp/argtest.php
docker cp /tmp/argtest.php app:/tmp/argtest.php >/dev/null && docker exec app php /tmp/argtest.php 2>&1 | grep -v -i xdebug
```

Expected: `x` — no error, under `declare(strict_types=1)` on PHP 7.4. Do **not** add unused `$useReadPdo` parameters to the Mongo and Redis probes to absorb the argument; they would be dead parameters solving a problem the language does not have.

- [ ] **Step 10: Lint and check the PHP 7.4 floor**

```bash
cd "$REPO"
"$SCRATCH/plqlint" src/Status/StatusCheckService.php
grep -nE 'str_starts_with|str_contains|str_ends_with|\?->|\bmatch\s*\(' src/Status/StatusCheckService.php ; echo "php8 grep exit: $?"
```

Expected: `OK (php7.4)`, then nothing with `exit: 1`.

- [ ] **Step 11: Confirm no hardcoded targets survive**

```bash
grep -nE "probeMysql\('|probeRedis\('|DB::connection\('mongodb'|Redis::connection\('default'|redisSeeds\('" src/Status/StatusCheckService.php ; echo "exit: $?"
grep -n "probeMysqlWrite\|probeMysqlRead\b\|probeMysqlReadOnlyConnection\|probeMysqlBackoffice\|probeRedisDefault\|probeRedisOthers\|probeReadinessDatabase" src/Status/StatusCheckService.php ; echo "exit: $?"
```

Expected: nothing from either, `exit: 1` twice. The first proves no probe pins a target; the second proves all eight wrappers are gone.

- [ ] **Step 12: Run the resolution smoke script to verify it passes**

```bash
$PHP "$SCRATCH/resolve_smoke.php"
```

Expected: every line `PASS`, final line `ALL PASS`, exit 0.

- [ ] **Step 13: Confirm the test can fail**

Temporarily change `resolveComponents()`'s enabled check from `?? true` to `?? false`, re-run, and confirm `missing config entry defaults enabled` fails. Change it back and re-run to green.

```bash
$PHP "$SCRATCH/resolve_smoke.php"
```

- [ ] **Step 14: Commit**

```bash
cd "$REPO"
git add src/Status/StatusCheckService.php
git commit -m "PQPL-6496: resolve component targets from config

Merges the two probe-name constants into one COMPONENTS map carrying
both probe variants plus target metadata, and adds resolveComponents()
to decide what runs with which arguments. Deletes the eight wrapper
methods that existed only to hardcode a connection name, and
parameterises the Mongo and Redis probes that still pinned one.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Make `probeCache` honour its store

Task 1 gave `cache` a `store` target and Task 2 passes it in, but the probe currently round-trips through the *default* store while only reporting the configured one — so a configured store would be described and not exercised. This task closes that gap.

**Files:**
- Modify: `src/Status/StatusCheckService.php` — `probeCache()`

**Interfaces:**

- Consumes: `probeArguments()` from Task 2, which passes `[$store]` where `$store` is `?string`.
- Produces: `probeCache(?string $store = null): array` — `null` means follow `cache.default`.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/cache_store_smoke.php`. It fakes the `Cache` facade so no cache backend is needed, and records which store was asked for:

```php
<?php

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static $storeRequested = 'NONE';
        public static $values = [];

        public static function store($name = null)
        {
            self::$storeRequested = $name === null ? 'DEFAULT' : $name;

            return new static();
        }

        public function put($key, $value, $ttl = null)
        {
            self::$values[$key] = $value;
        }

        public function get($key, $default = null)
        {
            return self::$values[$key] ?? $default;
        }

        public function forget($key)
        {
            unset(self::$values[$key]);
        }
    }
}

namespace {
    require '/private/tmp/claude-501/-Users-mateomartinez-development-pq-docker-src-playlogiq-utils/f35c9d30-ac0f-4dce-b9f6-66a93b66d1d8/scratchpad/config_stub.php';
    require '/Users/mateomartinez/development/pq-docker/src/playlogiq-utils/.worktrees/PQPL-6496-status-checks/vendor/autoload.php';

    use Illuminate\Support\Facades\Cache;
    use PlaylogiqUtils\Status\StatusCheckService;

    $failures = 0;

    function check(string $label, $actual, $expected): void
    {
        global $failures;

        if ($actual === $expected) {
            echo "PASS  {$label}\n";
            return;
        }

        $failures++;
        echo "FAIL  {$label}: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true) . "\n";
    }

    function probeCacheWith($store): array
    {
        $GLOBALS['__cfg'] = ['cache' => ['default' => 'file', 'stores' => ['file' => ['driver' => 'file']]]];
        Cache::$storeRequested = 'NONE';

        $m = new ReflectionMethod(StatusCheckService::class, 'probeCache');
        $m->setAccessible(true);

        return (array) $m->invoke(new StatusCheckService(), $store);
    }

    // A named store is the one actually exercised, not just the one reported.
    $r = probeCacheWith('redis');
    check('named store is exercised', Cache::$storeRequested, 'redis');
    check('named store is reported', $r['details']['store'], 'redis');

    // null follows cache.default, which is today's behaviour.
    $r = probeCacheWith(null);
    check('null uses the default store', Cache::$storeRequested, 'DEFAULT');
    check('null reports cache.default', $r['details']['store'], 'file');

    echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
    exit($failures === 0 ? 0 : 1);
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$PHP "$SCRATCH/cache_store_smoke.php"
```

Expected: `FAIL  named store is exercised: expected 'redis', got 'NONE'` — `probeCache` currently calls `Cache::put()` directly and never calls `Cache::store()` at all. It will also fail on the argument count, since `probeCache()` takes none yet.

- [ ] **Step 3: Rewrite `probeCache`**

Replace the whole method:

```php
    /**
     * A keyed put/get/forget round-trip against the store this component
     * targets — proving the store actually accepts writes, rather than only
     * reporting which store is configured.
     *
     * @param string|null $store null follows cache.default
     */
    private function probeCache(?string $store = null): array
    {
        $name = $store !== null && $store !== '' ? $store : (string) config('cache.default');

        $key = 'status:probe:' . Str::random(16);
        $value = (string) microtime(true);

        $cache = Cache::store($store !== null && $store !== '' ? $store : null);
        $cache->put($key, $value, 10);
        $readBack = $cache->get($key);
        $cache->forget($key);

        if ((string) $readBack !== $value) {
            throw new RuntimeException('put/get round-trip returned a different value');
        }

        return [
            'details' => [
                'store' => $name,
                'driver' => config("cache.stores.{$name}.driver"),
                'connection' => config("cache.stores.{$name}.connection"),
                'round_trip' => 'ok',
            ],
        ];
    }
```

- [ ] **Step 4: Run the smoke script to verify it passes**

```bash
$PHP "$SCRATCH/cache_store_smoke.php"
```

Expected: four `PASS` lines, `ALL PASS`, exit 0.

- [ ] **Step 5: Lint**

```bash
"$SCRATCH/plqlint" src/Status/StatusCheckService.php
```

Expected: `OK (php7.4)`.

- [ ] **Step 6: Commit**

```bash
cd "$REPO"
git add src/Status/StatusCheckService.php
git commit -m "PQPL-6496: probe the cache store the component targets

probeCache round-tripped through the default store while reporting the
configured one, so a targeted store would be described and never
exercised. It now resolves the store and probes that.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Report an unknown readiness component

A typo in `STATUS_READY_CHECKS` currently fails silently — the name matches nothing, so the check simply does not run and the endpoint answers 200 with a quietly reduced set. Readiness gating a load balancer is exactly where silence is worst.

**Files:**
- Modify: `src/Status/StatusCheckService.php` — `runReadiness()`

**Interfaces:**

- Consumes: `resolveComponents('readiness')` from Task 2.
- Produces: `runReadiness()` adds a failed component per unknown name, named after the offending entry, with the error `unknown readiness component [<name>]`.

- [ ] **Step 1: Write the failing test**

Append to `$SCRATCH/resolve_smoke.php`, immediately before the final `echo`:

```php
// A name in readiness.checks that no component provides is a configuration
// error, and must be loud: readiness gates the load balancer.
$GLOBALS['__cfg'] = [
    'status' => [
        'components' => ['mysql' => ['enabled' => true, 'connection' => 'primary']],
        'readiness' => ['checks' => ['mysql', 'redsi', 'cache']],
    ],
];

$m = new ReflectionMethod(StatusCheckService::class, 'unknownReadinessComponents');
$m->setAccessible(true);
$unknown = $m->invoke(new StatusCheckService());

// 'redsi' is a typo and matches nothing; 'cache' is a real component but has
// no readiness probe, so it cannot be part of the readiness set either.
check('unknown readiness names detected', $unknown, ['redsi', 'cache']);
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$PHP "$SCRATCH/resolve_smoke.php"
```

Expected: the earlier assertions still pass, then a fatal `ReflectionException: Method PlaylogiqUtils\Status\StatusCheckService::unknownReadinessComponents() does not exist`.

- [ ] **Step 3: Add the detector**

Insert directly below `resolveComponents()`:

```php
    /**
     * Names in readiness.checks that no component can satisfy — a typo, or a
     * component that has no readiness probe. Returned rather than ignored
     * because a silently reduced readiness set is worse than a loud failure.
     *
     * @return string[]
     */
    private function unknownReadinessComponents(): array
    {
        $configured = (array) config('status.readiness.checks', self::availableReadinessChecks());
        $available = self::availableReadinessChecks();
        $unknown = [];

        foreach ($configured as $name) {
            if (! in_array($name, $available, true)) {
                $unknown[] = (string) $name;
            }
        }

        return $unknown;
    }
```

- [ ] **Step 4: Report them from `runReadiness()`**

Add the loop directly after the existing `foreach` over `resolveComponents('readiness')`, before the `return`:

```php
        foreach ($this->unknownReadinessComponents() as $name) {
            $components[$name] = ComponentStatus::failed(
                $name,
                "unknown readiness component [{$name}]"
            )->withCritical(true);
        }
```

A failed critical component makes the report unhealthy, so a typo now surfaces as a 503 naming the offending entry rather than as a quietly smaller check set.

- [ ] **Step 5: Run the smoke script to verify it passes**

```bash
$PHP "$SCRATCH/resolve_smoke.php"
```

Expected: every line `PASS`, `ALL PASS`, exit 0.

- [ ] **Step 6: Lint**

```bash
"$SCRATCH/plqlint" src/Status/StatusCheckService.php
```

Expected: `OK (php7.4)`.

- [ ] **Step 7: Commit**

```bash
cd "$REPO"
git add src/Status/StatusCheckService.php
git commit -m "PQPL-6496: fail loudly on an unknown readiness component

A typo in STATUS_READY_CHECKS matched nothing and silently shrank the
readiness set. Unknown names are now reported as failed components, so
the endpoint answers 503 naming the offending entry.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Rewrite the README section and update the betmaker-bo steps

The README currently documents `STATUS_CHECKS` and `STATUS_CRITICAL_CHECKS`, which no longer exist, and the betmaker-bo switchover steps in the previous plan pin an env var list that no longer applies.

**Files:**
- Modify: `README.md` — the "Status checks" section
- Modify: `docs/superpowers/plans/2026-09-18-status-check-sharing.md` — Task 7 Step 3 and Step 8

**Interfaces:**

- Consumes: the config shape from Task 1 and the behaviour from Tasks 2–4.
- Produces: nothing code depends on.

- [ ] **Step 1: Replace the "Two sets of checks" and "Environment variables" subsections**

In `README.md`, replace everything from `### Two sets of checks` down to (but not including) `### A health endpoint` with:

````markdown
### Configuring components

`config/status.php` has one `components` block. Each entry says whether this
project runs the service, which connection or store to probe, and whether its
failure is fatal:

```php
'components' => [
    'mysql' => [
        'enabled'    => env('STATUS_CHECK_MYSQL', true),
        'connection' => env('STATUS_CONN_MYSQL', 'mysql'),
        'critical'   => true,
    ],
    'mongodb' => [
        'enabled'    => env('STATUS_CHECK_MONGODB', false),
        'connection' => env('STATUS_CONN_MONGODB', 'mongodb'),
    ],
],
```

A project whose Redis connection is called `sessions` sets
`STATUS_CONN_REDIS=sessions`. A project with no Mongo leaves
`STATUS_CHECK_MONGODB` off, which is the default.

**`enabled => false` wins everywhere.** A disabled component is absent from the
report *and* excluded from readiness, even if `readiness.checks` names it.

These ship **disabled**, because most projects do not run them: `mysql_ro`,
`mysql_bo`, `mongodb`, `redis_others`, `passport_keys`.

### Two sets of checks

`run()` returns the diagnostic report. Only components marked `critical` can
make it unhealthy; a component whose connection is not configured is reported
as `skipped`, which counts as healthy.

`runReadiness()` answers a single question: may this instance take traffic?
Every member of the set is required, and a readiness probe **fails** rather than
skips when its connection is absent — which is why the readiness default is
only the components every project has:

```
mysql, redis, config, app_key, storage
```

`config` and `app_key` are self-checks with no target. The rest name components
from the map above and reuse their connections, so a connection name is
declared once.

Add to the set with `STATUS_READY_CHECKS`. A name that matches no component —
a typo, or a component with no readiness probe such as `cache` — is reported as
a failed component and answers 503, rather than silently shrinking the set.
````

Then replace the entire `### Environment variables` table with:

````markdown
### Environment variables

Enable or disable a component:

| Variable | Default |
|---|---|
| `STATUS_CHECK_MYSQL` | `true` |
| `STATUS_CHECK_MYSQL_READ` | `true` |
| `STATUS_CHECK_MYSQL_RO` | `false` |
| `STATUS_CHECK_MYSQL_BO` | `false` |
| `STATUS_CHECK_MONGODB` | `false` |
| `STATUS_CHECK_REDIS` | `true` |
| `STATUS_CHECK_REDIS_OTHERS` | `false` |
| `STATUS_CHECK_CACHE` | `true` |
| `STATUS_CHECK_QUEUE` | `true` |
| `STATUS_CHECK_STORAGE` | `true` |
| `STATUS_CHECK_PASSPORT_KEYS` | `false` |

Point a component at your own connection or store:

| Variable | Default | Targets |
|---|---|---|
| `STATUS_CONN_MYSQL` | `mysql` | `mysql` and `mysql_read` |
| `STATUS_CONN_MYSQL_RO` | `mysql_ro` | `mysql_ro` |
| `STATUS_CONN_MYSQL_BO` | `mysql_bo` | `mysql_bo` |
| `STATUS_CONN_MONGODB` | `mongodb` | `mongodb` |
| `STATUS_CONN_REDIS` | `default` | `redis` |
| `STATUS_CONN_REDIS_OTHERS` | `others` | `redis_others` |
| `STATUS_CACHE_STORE` | unset | `cache`; unset follows `cache.default` |

Everything else:

| Variable | Default | Effect |
|---|---|---|
| `STATUS_READY_CHECKS` | `mysql,redis,config,app_key,storage` | the readiness set |
| `STATUS_READY_REQUIRE_AUTH` | `false` | treat an unauthenticated core component as a readiness failure |
| `STATUS_TCP_PROBE` | `true` | TCP pre-flight before each driver call |
| `STATUS_TCP_TIMEOUT` | `2.0` | pre-flight timeout, seconds |
| `STATUS_QUEUE_LAG_WARNING` | `300` | oldest unreserved job older than this degrades the queue; `0` disables |
| `STATUS_QUEUE_COUNT_FAILED` | `true` | `COUNT(*)` over `failed_jobs`; disable if that table is large |
| `STATUS_DISK_FREE_WARNING` | `10.0` | free storage below this percent degrades storage |
````

- [ ] **Step 2: Verify every documented variable exists in the config, and vice versa**

```bash
cd "$REPO"
grep -o '`STATUS_[A-Z_]*`' README.md | tr -d '`' | sort -u > /tmp/readme_vars.txt
grep -o 'STATUS_[A-Z_]*' config/status.php | sort -u > /tmp/cfg_vars.txt
echo "--- documented but not in config ---"; comm -23 /tmp/readme_vars.txt /tmp/cfg_vars.txt
echo "--- in config but undocumented ---"; comm -13 /tmp/readme_vars.txt /tmp/cfg_vars.txt
```

Expected: both lists empty.

- [ ] **Step 3: Update the betmaker-bo env pin in the previous plan**

In `docs/superpowers/plans/2026-09-18-status-check-sharing.md`, Task 7 Step 3, replace the two-line env block with:

```
STATUS_CHECK_MYSQL_RO=true
STATUS_CHECK_MYSQL_BO=true
STATUS_CHECK_MONGODB=true
STATUS_CHECK_REDIS_OTHERS=true
STATUS_CHECK_PASSPORT_KEYS=true

STATUS_READY_CHECKS=mysql,mysql_ro,redis,mongodb,config,app_key,storage,passport_keys
```

This reproduces PR #854's behaviour exactly: all eleven components in the report, the same eight in the readiness set, the same connections. `STATUS_CONN_*` are all left at their defaults because betmaker-bo's connection names already match them.

- [ ] **Step 4: Add the readiness rename to the betmaker-bo test step**

In the same file, Task 7 Step 8, append after the existing instruction:

> `CheckHttpStatusTest` also asserts on readiness component names. Two changed: `database` is now `mysql` and `database_read` is now `mysql_ro`. Update those assertions, and warn the team that the readiness log payload's `components.database` / `components.database_read` keys changed with them — any log query or dashboard matching those names needs the same edit.

- [ ] **Step 5: Commit**

```bash
cd "$REPO"
git add README.md docs/superpowers/plans/2026-09-18-status-check-sharing.md
git commit -m "PQPL-6496: document the components map

Rewrites the README status section for the per-component config and the
new env vars, and updates the betmaker-bo switchover steps for the new
pins and the two renamed readiness components.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Full verification and push to PR #3

**Files:** none modified.

**Interfaces:** none.

- [ ] **Step 1: Lint everything on the 7.4 floor**

```bash
cd "$REPO"
"$SCRATCH/plqlint" $(find src config -name '*.php' | sort)
```

Expected: `OK (php7.4)` for all twelve files, exit 0.

- [ ] **Step 2: Run every smoke suite**

```bash
$PHP "$SCRATCH/status_vo_smoke.php"
$PHP "$SCRATCH/security_headers_smoke.php"
$PHP "$SCRATCH/config_shape_smoke.php"
$PHP "$SCRATCH/resolve_smoke.php"
$PHP "$SCRATCH/cache_store_smoke.php"
```

Expected: `ALL PASS` from each, exit 0 each.

- [ ] **Step 3: Confirm the old config keys are referenced nowhere**

```bash
grep -rn "status\.checks\|status\.critical\|status\.read_connection\|STATUS_CHECKS\|STATUS_CRITICAL_CHECKS\|STATUS_READ_CONNECTION" src/ config/ README.md ; echo "exit: $?"
```

Expected: nothing, `exit: 1`. Any hit is a leftover reference to a key that no longer exists.

- [ ] **Step 4: Confirm the classes still resolve**

```bash
$PHP /usr/local/bin/composer dump-autoload -q
$PHP -d error_reporting="E_ALL & ~E_DEPRECATED" -r 'require "vendor/autoload.php";
foreach (["PlaylogiqUtils\\Status\\StatusCheckService","PlaylogiqUtils\\Status\\ComponentStatus","PlaylogiqUtils\\Status\\StatusReport","PlaylogiqUtils\\Status\\StatusServiceProvider","PlaylogiqUtils\\Middleware\\SecurityHeaders"] as $c) {
  printf("%-52s %s\n", $c, class_exists($c) ? "resolves" : "MISSING");
}
echo "report:    ", implode(",", PlaylogiqUtils\Status\StatusCheckService::availableChecks()), "\n";
echo "readiness: ", implode(",", PlaylogiqUtils\Status\StatusCheckService::availableReadinessChecks()), "\n";' 2>/dev/null
```

Expected: all five resolve; report lists the eleven report components; readiness lists `mysql,mysql_ro,mongodb,redis,storage,passport_keys,config,app_key`.

- [ ] **Step 5: Push to the existing PR**

```bash
cd "$REPO"
git push
```

The branch already tracks `origin/PQPL-6496-status-checks`, so this updates PR #3 in place.

- [ ] **Step 6: Update the PR description**

The current description documents the old `checks` / `critical` / `read_connection` shape, which no longer exists. Replace it wholesale:

```bash
cd "$REPO"
gh pr edit 3 --repo playlogiq/playlogiq-utils --body "$(cat <<'BODY'
Extracts the status-check subsystem from betmaker-bo PR #854 into this package, and makes every check point at connection names the consuming project chooses.

## What moved

- `PlaylogiqUtils\Status\StatusCheckService` — 11 report probes, 8 readiness probes
- `PlaylogiqUtils\Status\ComponentStatus` / `StatusReport` — value objects
- `PlaylogiqUtils\Status\StatusServiceProvider` — merges the default config, auto-discovered
- `config/status.php` — annotated defaults
- `PlaylogiqUtils\Middleware\SecurityHeaders` — with its cache-failure guard

Deliberately **not** moved: the `checkHttpStatus` route (each app decides what to log and return) and the tests (this package has no test harness).

## One config block per component

`checks`, `critical` and `read_connection` are replaced by a single `components` map. Each entry says whether this project runs the service, which connection or store to probe, and whether its failure is fatal:

```php
'mysql' => [
    'enabled'    => env('STATUS_CHECK_MYSQL', true),
    'connection' => env('STATUS_CONN_MYSQL', 'mysql'),
    'critical'   => true,
],
```

This closes a real gap: six connection names were hardcoded in the probes (`mysql`, `mongodb`, Redis `default` and `others`, twice over for the readiness variants). A project whose Redis connection is called `sessions` can now set `STATUS_CONN_REDIS=sessions` instead of being unable to use the check at all.

**`enabled => false` wins everywhere** — a disabled component is absent from the report *and* excluded from readiness, even if `readiness.checks` names it. "We don't run Mongo" is one fact, stated once.

**Five components now ship disabled** — `mysql_ro`, `mysql_bo`, `mongodb`, `redis_others`, `passport_keys` — because most projects don't run them. The previous shape enabled all eleven and leaned on skip-when-unconfigured to keep that harmless.

## Report vs readiness

The report's probes return `skipped` for a missing connection, which counts as healthy. The readiness probes deliberately **fail** instead, because readiness answers "may this instance take traffic?". That asymmetry is why the readiness default is only the components every project has: `mysql, redis, config, app_key, storage`.

A name in `STATUS_READY_CHECKS` matching no component — a typo, or a component with no readiness probe — is now reported as a failed component and answers 503, instead of silently shrinking the check set.

## Breaking for betmaker-bo

**Two readiness components are renamed:** `database` → `mysql`, `database_read` → `mysql_ro`, so both sets name the same components instead of keeping a parallel vocabulary for the same services. This changes `components.database` / `components.database_read` in the readiness log payload — any log query or dashboard matching those keys needs updating — and `CheckHttpStatusTest` changes with it.

betmaker-bo's behaviour is otherwise unchanged: it enables its five extra components and pins the full readiness set by env, reproducing PR #854 exactly.

## Also fixed

`probeCache` round-tripped through the *default* cache store while reporting the *configured* one, so a targeted store would have been described and never exercised. It now probes what it names.

## New dependency

`illuminate/encryption`, same constraint as its siblings. `probeAppKey()` calls `Encrypter::supported()` statically and round-trips through `Crypt`, and it is not pulled in transitively by `laravel-zero/foundation` — confirmed by `class_exists()` against the installed tree. `app_key` is in the default readiness set, so this path runs on a default install.

## Verification

No test harness in this package, so: every file lints clean on **PHP 7.4.33** (the declared floor, via the `app` container — the host `php` is a broken php@7.1); five smoke suites covering the report verdict logic, the `SecurityHeaders` cache guard, the config shape, component/target resolution, and cache store targeting — each confirmed to fail before passing; all five classes resolve through the autoloader; no stale references to the removed config keys.

Design and plans: `docs/superpowers/specs/`, `docs/superpowers/plans/`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
BODY
)"
```

---

## Notes for the reviewer

**The readiness rename is the only externally visible break.** `database` → `mysql` and `database_read` → `mysql_ro` change keys in betmaker-bo's readiness log payload. Nothing consumes the package yet, so the cost is one test file and a heads-up to whoever greps those logs.

**Defaults flipped for five components.** `mysql_ro`, `mysql_bo`, `mongodb`, `redis_others` and `passport_keys` now ship disabled. The previous shape enabled all eleven and leaned on skip-when-unconfigured to keep that harmless — true for the report, but it made the config read as though every project has a backoffice database. betmaker-bo re-enables its five by env.

**`probeCache` changes what it exercises.** It previously round-tripped the default store while reporting the configured one. Now it probes what it names. In any project where `STATUS_CACHE_STORE` is unset, behaviour is identical.

**One argument shape serves every connection-targeted probe.** `probeArguments()` always passes `[$connection, $useReadPdo]`, so the Mongo and Redis probes receive a second argument they never declare. PHP discards surplus arguments to userland functions — verified on 7.4 under `strict_types` — so no dead parameters were added to absorb it. The alternative, a per-probe argument builder, would cost more than the asymmetry does.
