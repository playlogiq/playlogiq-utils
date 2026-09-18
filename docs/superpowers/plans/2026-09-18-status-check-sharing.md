# Status Check Sharing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the status-check subsystem from betmaker-bo PR #854 into the shared `playlogiq/playlogiq-utils` package so every Playlogiq Laravel project gets the same health and readiness probes.

**Architecture:** Three classes plus a config file move into a new `PlaylogiqUtils\Status` namespace, wired by an auto-discovered service provider that merges a default `config/status.php`. The `/status` probes already skip missing connections, so their defaults ship verbatim; the readiness probes throw on a missing connection, so their default narrows to a universal core and betmaker-bo pins its full set via env. `SecurityHeaders` moves alongside, with its cache-failure guard and without its unreachable CSP code.

**Tech Stack:** PHP 7.4+, Laravel 5.8–9 (`illuminate/support`, `illuminate/database`, `illuminate/http`, `laravel-zero/foundation`), Composer VCS package distribution, Laravel package auto-discovery.

**Spec:** `docs/superpowers/specs/2026-09-18-status-check-sharing-design.md`

## Global Constraints

- **PHP floor is `>=7.4`.** No `match`, no nullsafe `?->`, no promoted constructor properties, no `str_starts_with` / `str_contains` / `str_ends_with`, no union types, no enums, no first-class callable syntax. Typed properties, nullable types, `declare(strict_types=1)` and arrow functions are fine.
- **Laravel floor is `^5.8`**, ceiling `^9.0`. Do not use APIs newer than Laravel 5.8 without a guard.
- **No new Composer dependencies**, runtime or dev. Everything needed is already in `require`.
- **Namespace root is `PlaylogiqUtils\`**, PSR-4 mapped to `src/`.
- **No test harness.** The approved scope excludes PHPUnit and Orchestra Testbench. Verification in this plan is `php -l` plus throwaway smoke scripts run from the scratchpad directory and never committed. Where a class is plain PHP with no framework dependency (the two value objects), the smoke script is written first and must fail before the code is in place.
- **Scratchpad for throwaway scripts:** `/private/tmp/claude-501/-Users-mateomartinez-development-pq-docker-src-playlogiq-utils/f35c9d30-ac0f-4dce-b9f6-66a93b66d1d8/scratchpad`. Referred to below as `$SCRATCH`. Set it once per shell: `set -x SCRATCH /private/tmp/claude-501/-Users-mateomartinez-development-pq-docker-src-playlogiq-utils/f35c9d30-ac0f-4dce-b9f6-66a93b66d1d8/scratchpad` (fish) — the user's shell is fish, not bash.
- **Repo:** all work in Tasks 1–6 happens in `/Users/mateomartinez/development/pq-docker/src/playlogiq-utils`. Task 7 is a separate repo and a separate PR.
- **Source of truth for ported code:** `refs/pull/854/head` of `playlogiq/betmaker-bo`. Never retype these files by hand — download them, then apply the edits each task specifies.

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Status/ComponentStatus.php` | Value object: one component's probe result. Plain PHP, no framework dependency. |
| `src/Status/StatusReport.php` | Value object: aggregate of component results plus the HTTP verdict. Plain PHP, no framework dependency. |
| `src/Status/StatusCheckService.php` | All probes. Reads `config('status.*')`; the only file that touches DB, Redis, cache, queue and disk. |
| `src/Status/StatusServiceProvider.php` | Merges the default config; registers the publish tag. |
| `config/status.php` | Annotated defaults for every key the service reads. |
| `src/Middleware/SecurityHeaders.php` | Global response-header middleware, hardened against a cache outage. |
| `composer.json` | Adds `extra.laravel.providers` for auto-discovery. |
| `README.md` | "Status checks" usage section. |

---

## Task 1: Port the value objects

`ComponentStatus` and `StatusReport` are pure PHP — no Laravel imports at all — so they are the one part of this port that can be genuinely tested, and they are the vocabulary every later task speaks. They ship together because `StatusReport` consumes `ComponentStatus`; neither is independently useful.

**Files:**
- Create: `src/Status/ComponentStatus.php`
- Create: `src/Status/StatusReport.php`
- Throwaway: `$SCRATCH/status_vo_smoke.php`

**Interfaces:**

- Consumes: nothing.
- Produces:
  - `PlaylogiqUtils\Status\ComponentStatus`
    - constants `OK = 'ok'`, `DEGRADED = 'degraded'`, `FAILED = 'failed'`, `SKIPPED = 'skipped'`
    - `__construct(string $name, string $status, bool $critical = false, ?float $latencyMs = null, array $details = [], ?string $error = null)`
    - `static ok(string $name, ?float $latencyMs = null, array $details = []): self`
    - `static degraded(string $name, string $error, ?float $latencyMs = null, array $details = []): self`
    - `static failed(string $name, string $error, ?float $latencyMs = null, array $details = []): self`
    - `static skipped(string $name, string $reason, array $details = []): self`
    - `withCritical(bool $critical): self` — returns a new instance
    - `name(): string`, `status(): string`, `isCritical(): bool`, `hasFailed(): bool`, `isHealthy(): bool`
    - `toArray(bool $withDetails): array`
  - `PlaylogiqUtils\Status\StatusReport`
    - constants `OK = 'ok'`, `DEGRADED = 'degraded'`, `UNHEALTHY = 'unhealthy'`
    - `__construct(array $components, array $app, float $durationMs)` — `$components` keyed by component name
    - `overallStatus(): string`, `httpStatus(): int`, `failedComponents(): array`, `degradedComponents(): array`, `toArray(bool $withDetails): array`

- [ ] **Step 1: Write the failing smoke script**

This pins the three behaviours the rest of the system depends on: `SKIPPED` counts as healthy (which is what makes the `/status` defaults safe in any project), a failed *critical* component is the only thing that produces `UNHEALTHY`, and a failed non-critical component only degrades.

Create `$SCRATCH/status_vo_smoke.php`:

```php
<?php

declare(strict_types=1);

require '/Users/mateomartinez/development/pq-docker/src/playlogiq-utils/src/Status/ComponentStatus.php';
require '/Users/mateomartinez/development/pq-docker/src/playlogiq-utils/src/Status/StatusReport.php';

use PlaylogiqUtils\Status\ComponentStatus;
use PlaylogiqUtils\Status\StatusReport;

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

// A skipped component is healthy: this is what lets the package ship the full
// default check list to a project that has no Mongo and no read replica.
check('skipped is healthy', ComponentStatus::skipped('mongodb', 'not configured')->isHealthy(), true);
check('skipped has not failed', ComponentStatus::skipped('mongodb', 'not configured')->hasFailed(), false);
check('ok is healthy', ComponentStatus::ok('redis', 1.5)->isHealthy(), true);
check('degraded is not healthy', ComponentStatus::degraded('queue', 'lag')->isHealthy(), false);
check('failed has failed', ComponentStatus::failed('mysql', 'down')->hasFailed(), true);

// withCritical does not mutate the receiver.
$base = ComponentStatus::ok('mysql');
$crit = $base->withCritical(true);
check('withCritical returns a new object', $base->isCritical(), false);
check('withCritical sets the flag', $crit->isCritical(), true);
check('withCritical keeps the name', $crit->name(), 'mysql');

// toArray hides details and error text unless asked.
$summary = ComponentStatus::failed('mysql', 'connection refused', 12.0, ['host' => 'db'])->toArray(false);
check('summary omits details', array_key_exists('details', $summary), false);
check('summary omits error', array_key_exists('error', $summary), false);
$full = ComponentStatus::failed('mysql', 'connection refused', 12.0, ['host' => 'db'])->toArray(true);
check('full includes error', $full['error'], 'connection refused');
check('full includes details', $full['details'], ['host' => 'db']);

// Report verdicts.
$allOk = new StatusReport([
    'mysql' => ComponentStatus::ok('mysql', 1.0)->withCritical(true),
    'mongodb' => ComponentStatus::skipped('mongodb', 'not configured'),
], [], 5.0);
check('all ok plus skipped is ok', $allOk->overallStatus(), StatusReport::OK);
check('ok answers 200', $allOk->httpStatus(), 200);

$nonCriticalDown = new StatusReport([
    'mysql' => ComponentStatus::ok('mysql', 1.0)->withCritical(true),
    'queue' => ComponentStatus::failed('queue', 'no workers'),
], [], 5.0);
check('non-critical failure degrades', $nonCriticalDown->overallStatus(), StatusReport::DEGRADED);
check('degraded still answers 200', $nonCriticalDown->httpStatus(), 200);
check('failed list names the component', $nonCriticalDown->failedComponents(), ['queue']);

$criticalDown = new StatusReport([
    'mysql' => ComponentStatus::failed('mysql', 'down')->withCritical(true),
], [], 5.0);
check('critical failure is unhealthy', $criticalDown->overallStatus(), StatusReport::UNHEALTHY);
check('unhealthy answers 503', $criticalDown->httpStatus(), 503);

$warned = new StatusReport([
    'queue' => ComponentStatus::degraded('queue', 'lag 600s'),
], [], 5.0);
check('degraded list names the component', $warned->degradedComponents(), ['queue']);
check('degraded is not in the failed list', $warned->failedComponents(), []);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php "$SCRATCH/status_vo_smoke.php"
```

Expected: a fatal error — `Failed to open stream: No such file or directory` for `src/Status/ComponentStatus.php`. The files do not exist yet.

- [ ] **Step 3: Download the two value objects from the PR**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
mkdir -p src/Status

gh api "repos/playlogiq/betmaker-bo/contents/app/ValueObjects/Status/ComponentStatus.php?ref=refs/pull/854/head" \
  --jq .content | base64 -d > src/Status/ComponentStatus.php

gh api "repos/playlogiq/betmaker-bo/contents/app/ValueObjects/Status/StatusReport.php?ref=refs/pull/854/head" \
  --jq .content | base64 -d > src/Status/StatusReport.php
```

- [ ] **Step 4: Rewrite the namespace**

Both files declare `namespace App\ValueObjects\Status;`. Replace it in both:

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
sed -i '' 's/^namespace App\\ValueObjects\\Status;$/namespace PlaylogiqUtils\\Status;/' \
  src/Status/ComponentStatus.php src/Status/StatusReport.php
```

Verify exactly two matches and no leftovers:

```bash
grep -n '^namespace' src/Status/ComponentStatus.php src/Status/StatusReport.php
grep -rn 'App\\ValueObjects' src/ ; echo "exit: $?"
```

Expected: both files print `namespace PlaylogiqUtils\Status;`, and the second grep prints nothing with `exit: 1`.

`StatusReport` references `ComponentStatus::DEGRADED` unqualified and both classes now sit in the same namespace, so no `use` statement is needed and none should be added.

- [ ] **Step 5: Lint both files**

```bash
php -l src/Status/ComponentStatus.php
php -l src/Status/StatusReport.php
```

Expected: `No syntax errors detected` for each.

- [ ] **Step 6: Run the smoke script to verify it passes**

```bash
php "$SCRATCH/status_vo_smoke.php"
```

Expected: every line `PASS`, final line `ALL PASS`, exit code 0.

- [ ] **Step 7: Commit**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
git add src/Status/ComponentStatus.php src/Status/StatusReport.php
git commit -m "PQPL-6496: add status value objects

Ports ComponentStatus and StatusReport from betmaker-bo PR #854 into
PlaylogiqUtils\\Status. Plain PHP with no framework dependency.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Port StatusCheckService

985 lines of probes. Everything is `config()`-driven already; the only code changes are the namespace, the dropped `use` statements, and de-hardcoding the read-replica connection name.

**Files:**
- Create: `src/Status/StatusCheckService.php`

**Interfaces:**

- Consumes: `PlaylogiqUtils\Status\ComponentStatus` and `PlaylogiqUtils\Status\StatusReport` from Task 1 (same namespace, so no `use` needed).
- Produces: `PlaylogiqUtils\Status\StatusCheckService`
  - `run(array $only = []): StatusReport` — the `/status` report; `$only` restricts the run to named components
  - `runReadiness(): StatusReport` — the readiness set; every component is marked critical
  - `static availableChecks(): array` — `['mysql','mysql_read','mysql_ro','mysql_bo','mongodb','redis','redis_others','cache','queue','storage','passport_keys']`
  - `static availableReadinessChecks(): array` — `['database','database_read','redis','mongodb','config','app_key','storage','passport_keys']`
  - `const REQUIRED_CONFIG_KEYS` — the seven fallback config keys
  - Reads these config keys and no others: `status.checks`, `status.critical`, `status.read_connection`, `status.tcp_probe`, `status.tcp_timeout`, `status.queue_lag_warning_seconds`, `status.queue_count_failed`, `status.disk_free_warning_percent`, `status.readiness.checks`, `status.readiness.require_authentication`, `status.readiness.required_config`

- [ ] **Step 1: Download the service**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
gh api "repos/playlogiq/betmaker-bo/contents/app/Services/Status/StatusCheckService.php?ref=refs/pull/854/head" \
  --jq .content | base64 -d > src/Status/StatusCheckService.php
wc -l src/Status/StatusCheckService.php
```

Expected: `985 src/Status/StatusCheckService.php`. If the count differs, the PR has moved on — stop and re-read the file before continuing.

- [ ] **Step 2: Rewrite the namespace and drop the two now-redundant imports**

Lines 5–8 of the downloaded file read:

```php
namespace App\Services\Status;

use App\ValueObjects\Status\ComponentStatus;
use App\ValueObjects\Status\StatusReport;
```

The value objects now live in the same namespace as the service, so both `use` lines must go — leaving them would import non-existent classes.

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
sed -i '' \
  -e 's/^namespace App\\Services\\Status;$/namespace PlaylogiqUtils\\Status;/' \
  -e '/^use App\\ValueObjects\\Status\\ComponentStatus;$/d' \
  -e '/^use App\\ValueObjects\\Status\\StatusReport;$/d' \
  src/Status/StatusCheckService.php
```

Verify:

```bash
sed -n '1,16p' src/Status/StatusCheckService.php
grep -rn 'App\\' src/Status/StatusCheckService.php ; echo "exit: $?"
```

Expected: the header shows `namespace PlaylogiqUtils\Status;` followed directly by `use Illuminate\Encryption\Encrypter;`, and the grep prints nothing with `exit: 1`.

- [ ] **Step 3: De-hardcode the read-replica connection**

Two methods name `'mysql_ro'` in code. Both change to read `status.read_connection`, matching how `probeMysqlBackoffice()` already reads `config('database.bo_connection', 'mysql_bo')`.

`probeMysqlReadOnlyConnection()` currently reads:

```php
    private function probeMysqlReadOnlyConnection(): array
    {
        return $this->probeMysql('mysql_ro', true);
    }
```

Replace its body with:

```php
    private function probeMysqlReadOnlyConnection(): array
    {
        return $this->probeMysql((string) config('status.read_connection', 'mysql_ro'), true);
    }
```

`probeReadinessDatabaseRead()` currently reads:

```php
    private function probeReadinessDatabaseRead(): array
    {
        // Core models (User, Game, Setting, Leaderboard, …) declare
        // $connection = 'mysql_ro', so an instance whose replica is unreachable
        // cannot serve player traffic even though its writer is fine.
        return $this->probeAuthenticatedDatabase('mysql_ro', true, 'read replica');
    }
```

Replace the whole method with this — note the comment loses its betmaker model names, which mean nothing in a shared package:

```php
    private function probeReadinessDatabaseRead(): array
    {
        // Applications whose models read through a replica cannot serve traffic
        // when that replica is unreachable, even though the writer is fine.
        // Which connection that is comes from status.read_connection.
        return $this->probeAuthenticatedDatabase(
            (string) config('status.read_connection', 'mysql_ro'),
            true,
            'read replica'
        );
    }
```

- [ ] **Step 4: Verify no hardcoded `mysql_ro` remains in code**

```bash
grep -n "mysql_ro" src/Status/StatusCheckService.php
```

Expected: exactly one line — the `'mysql_ro' => 'probeMysqlReadOnlyConnection',` entry in the `CHECKS` constant. That one is a *component name* in the report payload, not a connection name, and must not change: renaming it would rename the key in every consumer's `/status` JSON. The two `config('status.read_connection', 'mysql_ro')` defaults will also appear; those are correct. No other matches.

- [ ] **Step 5: Lint**

```bash
php -l src/Status/StatusCheckService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 6: Verify no PHP 8 syntax slipped in**

```bash
grep -nE 'str_starts_with|str_contains|str_ends_with|\?->|\bmatch\s*\(|\benum\s' src/Status/StatusCheckService.php ; echo "exit: $?"
```

Expected: nothing, `exit: 1`. The package floor is PHP 7.4.

- [ ] **Step 7: Verify the class loads through the package autoloader**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
composer dump-autoload 2>&1 | tail -3
php -r 'require "vendor/autoload.php"; $r = new ReflectionClass(PlaylogiqUtils\Status\StatusCheckService::class); echo $r->getName(), "\n"; print_r(PlaylogiqUtils\Status\StatusCheckService::availableChecks()); print_r(PlaylogiqUtils\Status\StatusCheckService::availableReadinessChecks());'
```

Expected: the class name, then the 11 check names, then the 8 readiness names. Both static methods are pure `array_keys()` over class constants and touch no framework code, so they run without a booted application.

If `vendor/` does not exist yet, run `composer install` first.

- [ ] **Step 8: Commit**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
git add src/Status/StatusCheckService.php
git commit -m "PQPL-6496: add StatusCheckService

Ports the probe service from betmaker-bo PR #854. The read-replica
connection name now comes from status.read_connection instead of being
hardcoded to mysql_ro, so projects with a differently named replica can
use the readiness check.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Package the config file

The heart of the port. Four changes from PR #854's file, each with a different reason — read the spec's "Configuration" section before starting.

**Files:**
- Create: `config/status.php`

**Interfaces:**

- Consumes: nothing at runtime. Must define every key Task 2 listed under "Reads these config keys".
- Produces: the `status.*` config namespace. Task 4 merges this file; Task 6 documents its env vars.

- [ ] **Step 1: Download the config**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
mkdir -p config
gh api "repos/playlogiq/betmaker-bo/contents/config/status.php?ref=refs/pull/854/head" \
  --jq .content | base64 -d > config/status.php
wc -l config/status.php
```

Expected: `215 config/status.php`.

- [ ] **Step 2: Narrow the readiness default**

This is the change that makes the package safe to install anywhere. The `/status` probes return `SKIPPED` for a missing connection, but the readiness probes **throw** — so PR #854's readiness default would make any project without a read replica, Mongo or Passport answer 503 on install.

Find:

```php
        'checks' => $statusList('STATUS_READY_CHECKS', 'database,database_read,redis,mongodb,config,app_key,storage,passport_keys'),
```

Replace with:

```php
        /*
        | The package default is the set every Playlogiq application has. The
        | readiness probes deliberately FAIL rather than skip when a connection
        | is absent — readiness answers "may this instance take traffic?", and a
        | missing core dependency is not something to wave through — so naming a
        | component here that a project does not run means a permanent 503.
        |
        | Opt in to the rest per application via STATUS_READY_CHECKS:
        |   database_read   a read replica, named by status.read_connection
        |   mongodb         a mongodb connection and the mongodb extension
        |   passport_keys   Laravel Passport OAuth signing keys
        */
        'checks' => $statusList('STATUS_READY_CHECKS', 'database,redis,config,app_key,storage'),
```

- [ ] **Step 3: Add the read-connection key**

Task 2 made `probeMysqlReadOnlyConnection()` and `probeReadinessDatabaseRead()` read `status.read_connection`. Without this key they still work (both pass `'mysql_ro'` as the `config()` default), but the key must be documented or nobody will know it exists.

Insert immediately after the `'critical' => ...` line and before the "Detail Level" comment block:

```php
    /*
    |--------------------------------------------------------------------------
    | Read Replica Connection
    |--------------------------------------------------------------------------
    |
    | The database connection the `mysql_ro` check and the `database_read`
    | readiness check probe. Applications that name their replica connection
    | something else point this at it; applications with no replica leave
    | `database_read` out of the readiness set entirely.
    |
    */

    'read_connection' => env('STATUS_READ_CONNECTION', 'mysql_ro'),
```

- [ ] **Step 4: Drop the three keys nothing reads**

`StatusCheckService` never reads `detail`, `min_interval_seconds` or `readiness.min_interval_seconds`. They describe the detail level and the snapshot rate limit of an endpoint the package does not ship. Keeping them would document a feature that does not exist here.

Delete, each together with the comment block directly above it:

1. The `Detail Level` comment block and `'detail' => env('STATUS_DETAIL', 'full'),`
2. The `Snapshot Interval` comment block and `'min_interval_seconds' => (int) env('STATUS_MIN_INTERVAL', 5),`
3. Inside the `readiness` array, the comment block beginning `Readiness gets its own, much shorter snapshot window` and `'min_interval_seconds' => (int) env('STATUS_READY_MIN_INTERVAL', 2),`

`StatusReport::toArray(bool $withDetails)` already takes the detail level as an argument, so a consumer's route controls it directly.

- [ ] **Step 5: Empty the integration exclusion list**

`excluded_integrations` is an inventory of betmaker's own third parties — sportsbook providers, payment providers, KYC, SMS, Zendesk. That is application knowledge. Replace the populated array, keeping the comment block above it exactly as it is:

```php
        'excluded_integrations' => [
            // Per application: 'sportsbook' => ['provider_a', 'provider_b'], …
        ],
```

Leave `'exclusion_rationale' => ...` as written — the reasoning is general.

- [ ] **Step 6: Verify the file parses and defines exactly the expected keys**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
php -l config/status.php
php -r 'function env($k, $d = null) { return $d; } $c = require "config/status.php"; ksort($c); print_r(array_keys($c)); print_r(array_keys($c["readiness"])); print_r($c["readiness"]["checks"]); print_r($c["checks"]); echo "read_connection: ", $c["read_connection"], "\n";'
```

The `env()` stub is needed because the config file calls `env()` at top level and there is no Laravel application booted here.

Expected top-level keys, exactly: `checks`, `critical`, `disk_free_warning_percent`, `queue_count_failed`, `queue_lag_warning_seconds`, `read_connection`, `readiness`, `tcp_probe`, `tcp_timeout`.

Expected `readiness` keys, exactly: `checks`, `require_authentication`, `required_config`, `excluded_integrations`, `exclusion_rationale`.

Expected `readiness.checks`: `database`, `redis`, `config`, `app_key`, `storage` — five entries.

Expected `checks`: the full eleven — `mysql`, `mysql_read`, `mysql_ro`, `mysql_bo`, `mongodb`, `redis`, `redis_others`, `cache`, `queue`, `storage`, `passport_keys`.

Expected `read_connection`: `mysql_ro`.

If `detail` or either `min_interval_seconds` appears, Step 4 was incomplete.

- [ ] **Step 7: Cross-check the config against what the service actually reads**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
grep -o "config('status[^)']*'" src/Status/StatusCheckService.php | sed "s/config('status\.//;s/'$//" | sort -u
```

Expected, exactly ten lines: `checks`, `critical`, `disk_free_warning_percent`, `queue_count_failed`, `queue_lag_warning_seconds`, `read_connection`, `readiness.checks`, `readiness.require_authentication`, `readiness.required_config`, `tcp_probe`, `tcp_timeout`.

Every one must exist in the config from Step 6. `excluded_integrations` and `exclusion_rationale` are read by nothing in the package — they exist to be echoed by a consumer's route — and that is intentional.

- [ ] **Step 8: Commit**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
git add config/status.php
git commit -m "PQPL-6496: add default status config

Ports config/status.php from betmaker-bo PR #854. The readiness default
narrows to the components every project has, because a readiness probe
fails rather than skips when its connection is absent. Adds
read_connection; drops detail and the snapshot intervals, which describe
an endpoint this package does not ship; empties the betmaker-specific
integration exclusion list.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Service provider and auto-discovery

Makes the config reach a consuming application with no wiring at all. This is the package's first service provider, so it sets the pattern.

**Files:**
- Create: `src/Status/StatusServiceProvider.php`
- Modify: `composer.json` — add an `extra` block

**Interfaces:**

- Consumes: `config/status.php` from Task 3.
- Produces: `PlaylogiqUtils\Status\StatusServiceProvider`, auto-registered by Laravel package discovery. Publish tag `playlogiq-status-config`.

- [ ] **Step 1: Write the provider**

Create `src/Status/StatusServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Status;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the status-check defaults.
 *
 * Auto-discovered via composer.json's extra.laravel.providers, so a consuming
 * application gets working defaults without touching config/app.php. Nothing is
 * bound into the container: StatusCheckService has a zero-argument constructor
 * and the container autowires it.
 */
class StatusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'status');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => $this->app->configPath('status.php'),
        ], 'playlogiq-status-config');
    }

    private function configPath(): string
    {
        return __DIR__ . '/../../config/status.php';
    }
}
```

Two things worth knowing. `mergeConfigFrom` is shallow — it merges only the top level — so an application that publishes the file and deletes a key inside `readiness` loses that key's default rather than inheriting it. That is standard Laravel behaviour and the README (Task 6) must say so. And the publish is guarded by `runningInConsole()` because `publishes()` is only ever consumed by `vendor:publish`.

- [ ] **Step 2: Lint and verify the config path resolves**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
php -l src/Status/StatusServiceProvider.php
php -r 'echo realpath("src/Status/../../config/status.php") ?: "PATH NOT FOUND", "\n";'
```

Expected: `No syntax errors detected`, then the absolute path to `config/status.php`. If it prints `PATH NOT FOUND`, Task 3 did not create the file where this provider expects it.

- [ ] **Step 3: Register the provider for auto-discovery**

`composer.json` currently has no `extra` block. Add one after the `require` block — mind the comma after `require`'s closing brace:

```json
  "extra": {
    "laravel": {
      "providers": [
        "PlaylogiqUtils\\Status\\StatusServiceProvider"
      ]
    }
  }
```

The double backslashes are required: this is JSON, and `\S` would otherwise be an invalid escape.

- [ ] **Step 4: Verify composer.json is valid and the provider name is exact**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
composer validate --no-check-publish 2>&1 | tail -5
php -r 'require "vendor/autoload.php"; $j = json_decode(file_get_contents("composer.json"), true); $p = $j["extra"]["laravel"]["providers"][0]; echo $p, "\n"; echo class_exists($p) ? "class resolves\n" : "CLASS DOES NOT RESOLVE\n";'
```

Expected: `./composer.json is valid`, then `PlaylogiqUtils\Status\StatusServiceProvider`, then `class resolves`.

A typo here fails silently in consumers — Laravel skips a provider class it cannot find — so this check matters more than it looks.

- [ ] **Step 5: Verify the merged config is what a consumer would see**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
php -r '
function env($k, $d = null) { return $d; }
$c = require "config/status.php";
echo "readiness.checks:  ", implode(",", $c["readiness"]["checks"]), "\n";
echo "checks:            ", implode(",", $c["checks"]), "\n";
echo "critical:          ", implode(",", $c["critical"]), "\n";
'
```

Expected:

```
readiness.checks:  database,redis,config,app_key,storage
checks:            mysql,mysql_read,mysql_ro,mysql_bo,mongodb,redis,redis_others,cache,queue,storage,passport_keys
critical:          mysql,redis,cache
```

- [ ] **Step 6: Commit**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
git add src/Status/StatusServiceProvider.php composer.json
git commit -m "PQPL-6496: register status config via service provider

Merges the package default config and offers vendor:publish for
overrides. Auto-discovered through extra.laravel.providers, so a
consuming app needs no wiring.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Port SecurityHeaders

Independent of Tasks 1–4 — it shares only the reason for moving. The cache guard is what ties it to the status work: a global middleware that throws on a cache outage would 500 the very endpoint whose job is to report the cache as down.

**Files:**
- Create: `src/Middleware/SecurityHeaders.php`

**Interfaces:**

- Consumes: nothing from earlier tasks.
- Produces: `PlaylogiqUtils\Middleware\SecurityHeaders` with `handle(Request $request, Closure $next)`. Drop-in replacement for `App\Http\Middleware\SecurityHeaders` in a consumer's HTTP kernel.

- [ ] **Step 1: Write the file**

Write it out directly rather than downloading and stripping — more than half the original is unreachable, so the edit list would be longer than the result.

Create `src/Middleware/SecurityHeaders.php`:

```php
<?php

namespace PlaylogiqUtils\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        // This middleware is global, so a cache outage here would 500 every
        // single request — including the health endpoint, whose whole job is to
        // report that the cache is the component that is down. Fall back to
        // "flag off".
        try {
            $flag = (int) Cache::get('ff:csp:report_only', 0);
        } catch (\Throwable $e) {
            $flag = 0;
        }

        $response = $next($request);

        if ($flag !== 1) {
            return $response;
        }

        if (! $this->isHtml($request, $response)) {
            return $response;
        }

        $response->headers->remove('Content-Security-Policy');
        $response->headers->remove('Content-Security-Policy-Report-Only');
        $response->headers->remove('Report-To');
        $response->headers->remove('Reporting-Endpoints');
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    private function isHtml(Request $request, $response): bool
    {
        $ct = strtolower((string) $response->headers->get('Content-Type', ''));

        if (strpos($ct, 'text/html') !== false || strpos($ct, 'application/xhtml+xml') !== false) {
            return true;
        }

        $accept = strtolower((string) $request->headers->get('Accept', ''));

        if ($ct === '' && ($accept === '' || $accept === '*/*' || strpos($accept, 'text/html') !== false)) {
            return true;
        }

        return false;
    }
}
```

What was removed and why:

- `use Illuminate\Support\Facades\Log;` — imported, never called.
- `$host = strtolower($request->getHost() ?? '');` and `$isFapiHost()` — `$host` fed only `$isFapi`, and `$isFapi` was used only inside the commented-out policy block. Both are now dead.
- `$policy`, `$ru` / `config('services.security.csp_report_uri')`, and the `$response->headers->set(...)` call — the `set()` line is commented out in the original, so `$policy` is built and thrown away. The four `remove()` calls are the middleware's only real effect.
- `allowListFromHost()` and `brandFromFapiHost()` — called only from the commented-out block. Deleting them is also what keeps the package on PHP 7.4: they held the file's only `str_starts_with()` calls.

Behaviour is unchanged: with the flag off it returns early, and with the flag on and an HTML response it removes the same five headers.

- [ ] **Step 2: Lint and confirm the PHP 7.4 floor holds**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
php -l src/Middleware/SecurityHeaders.php
grep -nE 'str_starts_with|str_contains|str_ends_with|\?->|\bmatch\s*\(' src/Middleware/SecurityHeaders.php ; echo "exit: $?"
```

Expected: `No syntax errors detected`, then nothing with `exit: 1`.

- [ ] **Step 3: Verify the cache guard actually catches**

This is the only behavioural change in the file, so prove it rather than assume it. The script fakes a throwing `Cache` facade, so it needs no Laravel application:

Create `$SCRATCH/security_headers_smoke.php`:

```php
<?php

// Proves the try/catch around Cache::get survives a cache outage. A real
// Illuminate app is not booted; the facade is faked with a class alias.

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static $throw = false;

        public static function get($key, $default = null)
        {
            if (self::$throw) {
                throw new \RuntimeException('Connection refused [tcp://redis:6379]');
            }

            return $default;
        }
    }
}

namespace {
    // The fake Cache facade above is already declared, so the autoloader is
    // never asked for the real one. Everything else comes from vendor.
    require '/Users/mateomartinez/development/pq-docker/src/playlogiq-utils/vendor/autoload.php';

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;

    $failures = 0;

    Cache::$throw = true;

    $middleware = new PlaylogiqUtils\Middleware\SecurityHeaders();
    $request = Request::create('/checkHttpStatus', 'GET');

    try {
        $response = $middleware->handle($request, function () {
            return new Illuminate\Http\Response('ok', 200);
        });

        echo $response->getContent() === 'ok'
            ? "PASS  cache outage does not break the request\n"
            : "FAIL  unexpected body: " . $response->getContent() . "\n";

        if ($response->getContent() !== 'ok') {
            $failures++;
        }
    } catch (\Throwable $e) {
        $failures++;
        echo "FAIL  cache outage propagated: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }

    echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILURE(S)\n";
    exit($failures === 0 ? 0 : 1);
}
```

```bash
php "$SCRATCH/security_headers_smoke.php"
```

Expected: `PASS  cache outage does not break the request`, then `ALL PASS`, exit 0.

To confirm the test can fail, temporarily change `catch (\Throwable $e)` to `catch (\InvalidArgumentException $e)` in the middleware, re-run — it must report `FAIL  cache outage propagated: RuntimeException` — then change it back and re-run to green.

- [ ] **Step 4: Commit**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
git add src/Middleware/SecurityHeaders.php
git commit -m "PQPL-6496: add SecurityHeaders middleware

Ports the middleware from betmaker-bo PR #854 with its cache-failure
guard: a global middleware must not 500 every request when the cache is
the component that is down. Drops the commented-out CSP policy code and
the two helpers only it called, which also keeps the file on PHP 7.4.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Document it in the README

Without this, a consumer cannot know that `database_read` is opt-in or that the maintenance-mode exception is their job — the two ways this integration goes wrong in production.

**Files:**
- Modify: `README.md` — append a section at the end

**Interfaces:**

- Consumes: the public surface from Tasks 1–5.
- Produces: nothing code depends on.

- [ ] **Step 1: Append the section**

Append to `README.md`:

````markdown

---

## 🩺 Status checks

Health and readiness probes for MySQL, Redis, Mongo, the cache, the queue,
storage and Passport keys.

`PlaylogiqUtils\Status\StatusServiceProvider` is auto-discovered, so installing
the package is the whole setup. It merges a default `config/status.php`; publish
it only if you want to edit the file rather than drive it from env:

```bash
php artisan vendor:publish --tag=playlogiq-status-config
```

`mergeConfigFrom` merges only the **top level**. If you publish the file and
delete a key inside `readiness`, that key loses its default rather than
inheriting it — keep the published file complete.

### Two sets of checks

`run()` returns the diagnostic report: eleven components, and only the ones
named in `status.critical` can make it unhealthy. A component whose connection
is not configured is reported as `skipped`, which counts as healthy — so the
default list is safe in a project with no Mongo and no read replica.

`runReadiness()` answers a single question: may this instance take traffic?
Every component in the set is required, and a readiness probe **fails** rather
than skips when its connection is absent.

That is why the readiness default is only the components every project has:

```
database, redis, config, app_key, storage
```

Opt into the rest per project via `STATUS_READY_CHECKS`:

| Name | Needs |
|---|---|
| `database_read` | a read replica connection, named by `STATUS_READ_CONNECTION` (default `mysql_ro`) |
| `mongodb` | a `mongodb` database connection and the `mongodb` PHP extension |
| `passport_keys` | Laravel Passport OAuth signing keys |

Naming a component your project does not run means a permanent 503.

### A health endpoint

The package ships no route — each app decides what to log and what to return:

```php
use Illuminate\Support\Facades\Log;
use PlaylogiqUtils\Status\StatusCheckService;
use PlaylogiqUtils\Status\StatusReport;

Route::get('checkHttpStatus', function () {
    $report = app(StatusCheckService::class)->runReadiness();
    $healthy = $report->overallStatus() !== StatusReport::UNHEALTHY;

    Log::info('readiness', ['healthy' => $healthy, 'report' => $report->toArray(true)]);

    return response($healthy ? 'OK' : 'UNAVAILABLE', $report->httpStatus())
        ->header('Content-Type', 'text/plain')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
});
```

`toArray(true)` includes per-component details and error text; `toArray(false)`
gives status, criticality and latency only. Details expose internal topology
(hosts, ports, versions), so keep them out of a response reachable from the
public internet — log them instead, as above.

### Required: exempt the endpoint from maintenance mode

Add the route to your own `app/Http/Middleware/PreventRequestsDuringMaintenance.php`:

```php
protected $except = [
    'checkHttpStatus',
];
```

This class extends a framework class and is generated per application, so it
cannot live in the package. Without this, the endpoint stops answering during
`php artisan down` — which is exactly when the pipeline needs to see whether the
instance can reach its database.

### Environment variables

| Variable | Default | Effect |
|---|---|---|
| `STATUS_CHECKS` | all eleven | components in the `run()` report |
| `STATUS_CRITICAL_CHECKS` | `mysql,redis,cache` | which failures make `run()` unhealthy |
| `STATUS_READY_CHECKS` | `database,redis,config,app_key,storage` | the readiness set |
| `STATUS_READ_CONNECTION` | `mysql_ro` | replica connection for `mysql_ro` and `database_read` |
| `STATUS_READY_REQUIRE_AUTH` | `false` | treat an unauthenticated core component as a readiness failure |
| `STATUS_TCP_PROBE` | `true` | TCP pre-flight before each driver call |
| `STATUS_TCP_TIMEOUT` | `2.0` | pre-flight timeout, seconds |
| `STATUS_QUEUE_LAG_WARNING` | `300` | oldest unreserved job older than this degrades the queue; `0` disables |
| `STATUS_QUEUE_COUNT_FAILED` | `true` | `COUNT(*)` over `failed_jobs`; disable if that table is large |
| `STATUS_DISK_FREE_WARNING` | `10.0` | free storage below this percent degrades storage |

### Security headers

`PlaylogiqUtils\Middleware\SecurityHeaders` is a global middleware that strips
CSP and framing headers from HTML responses when the `ff:csp:report_only` cache
flag is set. It swallows a cache failure and falls back to "flag off", so a
Redis outage cannot 500 every request — including the health endpoint.
````

- [ ] **Step 2: Verify every documented default matches the code**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
grep -o "env('STATUS[^)]*)" config/status.php
grep -n "STATUS_READ_CONNECTION\|STATUS_READY_CHECKS\|STATUS_CHECKS\|STATUS_CRITICAL" README.md
```

Expected: every `STATUS_*` variable in the config appears in the README table with the same default, and the README introduces none the config does not define.

- [ ] **Step 3: Commit**

```bash
cd /Users/mateomartinez/development/pq-docker/src/playlogiq-utils
git add README.md
git commit -m "PQPL-6496: document status checks in the README

Covers the report/readiness split, why readiness components are opt-in,
an endpoint example, the env vars, and the per-app maintenance-mode
exception the package cannot ship.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Switch betmaker-bo over to the package

A different repository and a separate PR, stacked on top of PR #854. Do not start until Tasks 1–6 are merged to `main` in playlogiq-utils, because the app requires `dev-main`.

**Files** (in `playlogiq/betmaker-bo`):
- Modify: `composer.json` — `repositories` and `require`
- Delete: `app/Services/Status/StatusCheckService.php`
- Delete: `app/ValueObjects/Status/ComponentStatus.php`
- Delete: `app/ValueObjects/Status/StatusReport.php`
- Delete: `app/Http/Middleware/SecurityHeaders.php`
- Modify: `routes/web.php` — the `checkHttpStatus` closure
- Modify: `app/Http/Kernel.php` — the `SecurityHeaders` entry
- Modify: `config/status.php` — published, carrying the integration inventory
- Modify: `.env`, `.env.example` — `STATUS_READY_CHECKS`
- Modify: `tests/Unit/ValueObjects/Status/StatusReportTest.php`
- Modify: `tests/Feature/Http/Controllers/CheckHttpStatusTest.php`
- Keep unchanged: `app/Http/Middleware/PreventRequestsDuringMaintenance.php`

**Interfaces:**

- Consumes: everything Tasks 1–6 produced.
- Produces: nothing other code depends on.

- [ ] **Step 1: Branch and require the package**

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
git checkout main && git pull
git checkout -b PQPL-6496-status-checks-from-utils
```

Add to `composer.json` — `repositories` may already exist, in which case append the entry rather than creating the key:

```json
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/playlogiq/playlogiq-utils.git"
    }
  ]
```

Then:

```bash
composer require playlogiq/playlogiq-utils:dev-main
```

- [ ] **Step 2: Verify the provider auto-discovered**

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
php artisan package:discover
php artisan config:clear
php artisan tinker --execute='dump(config("status.readiness.checks"));'
```

Expected: `package:discover` lists `playlogiq/playlogiq-utils`, and the dump shows the package default `['database','redis','config','app_key','storage']`. betmaker-bo's own `config/status.php` from PR #854 is still on disk and takes precedence over the merge, so if the dump shows the eight-name list instead, that is expected at this point — Step 5 reconciles it.

- [ ] **Step 3: Pin the readiness set before deleting anything**

This preserves PR #854's reviewed behaviour exactly. Do it first, so no window exists where the endpoint runs a narrower set.

Add to `.env` and `.env.example`:

```
STATUS_READY_CHECKS=database,database_read,redis,mongodb,config,app_key,storage,passport_keys
STATUS_READ_CONNECTION=mysql_ro
```

- [ ] **Step 4: Delete the four app files**

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
git rm app/Services/Status/StatusCheckService.php \
       app/ValueObjects/Status/ComponentStatus.php \
       app/ValueObjects/Status/StatusReport.php \
       app/Http/Middleware/SecurityHeaders.php
rmdir app/Services/Status app/ValueObjects/Status 2>/dev/null || true
```

- [ ] **Step 5: Reconcile the published config**

betmaker-bo's `config/status.php` is PR #854's file. Replace it with the package's, then restore the integration inventory:

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
cp config/status.php /tmp/status-bo-original.php
php artisan vendor:publish --tag=playlogiq-status-config --force
```

Then reopen `config/status.php` and copy the `readiness.excluded_integrations` array back from `/tmp/status-bo-original.php` — the nine categories from `sportsbook` through `edge_and_geo`. Leave every other key at the package default; `.env` from Step 3 supplies the readiness set.

- [ ] **Step 6: Repoint the route**

In `routes/web.php`, the `checkHttpStatus` closure references two classes by their old FQCN. Change:

```php
    $report = app(\App\Services\Status\StatusCheckService::class)->runReadiness();
    $healthy = $report->overallStatus() !== \App\ValueObjects\Status\StatusReport::UNHEALTHY;
```

to:

```php
    $report = app(\PlaylogiqUtils\Status\StatusCheckService::class)->runReadiness();
    $healthy = $report->overallStatus() !== \PlaylogiqUtils\Status\StatusReport::UNHEALTHY;
```

Everything else in the closure — the hash id, both `Log::info` branches, the `text/plain` and `Cache-Control` headers — stays exactly as merged.

- [ ] **Step 7: Repoint the middleware stack**

In `app/Http/Kernel.php`, replace `\App\Http\Middleware\SecurityHeaders::class` with `\PlaylogiqUtils\Middleware\SecurityHeaders::class`. It may appear in more than one group:

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
grep -n "SecurityHeaders" app/Http/Kernel.php
```

Change every occurrence, then re-run the grep to confirm no `App\Http\Middleware\SecurityHeaders` remains.

- [ ] **Step 8: Repoint the tests**

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
grep -rn "App\\\\ValueObjects\\\\Status\|App\\\\Services\\\\Status" tests/
```

In each hit, change the `use` statements to `PlaylogiqUtils\Status\ComponentStatus`, `PlaylogiqUtils\Status\StatusReport` and `PlaylogiqUtils\Status\StatusCheckService`. The tests keep their current file paths and class names — moving them is not part of this work.

- [ ] **Step 9: Verify nothing still points at the old namespaces**

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
grep -rn "App\\\\Services\\\\Status\|App\\\\ValueObjects\\\\Status\|App\\\\Http\\\\Middleware\\\\SecurityHeaders" \
  app/ routes/ config/ tests/ ; echo "exit: $?"
```

Expected: nothing, `exit: 1`.

- [ ] **Step 10: Run the tests**

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
php artisan config:clear
./vendor/bin/phpunit tests/Unit/ValueObjects/Status/StatusReportTest.php
./vendor/bin/phpunit tests/Feature/Http/Controllers/CheckHttpStatusTest.php
```

Expected: both green. `CheckHttpStatusTest` exercises the real endpoint, so a failure here means the readiness set resolved differently than before — check that Step 3's `.env` value is in effect with `php artisan tinker --execute='dump(config("status.readiness.checks"));'`, which must print all eight names.

- [ ] **Step 11: Hit the endpoint against real infrastructure**

The tests do not prove the probes work; only a live run does.

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
curl -i http://localhost/checkHttpStatus
```

Expected: `200` and a 32-character hex id in the body. Then find the log line for that id and confirm every one of the eight readiness components is present and `ok`:

```bash
grep "readiness <the-id>" storage/logs/laravel.log | tail -1 | python3 -m json.tool | head -60
```

If a component reports `failed`, diagnose it before opening the PR — the point of this step is that a namespace change cannot silently break a probe.

- [ ] **Step 12: Commit and open the PR**

```bash
cd /Users/mateomartinez/development/pq-docker/src/betmaker-bo
git add -A
git commit -m "PQPL-6496: use status checks from playlogiq-utils

Removes StatusCheckService, the two status value objects and
SecurityHeaders in favour of the shared package. STATUS_READY_CHECKS
pins the readiness set from PR #854, whose default narrows in the
package so projects without a replica, Mongo or Passport are not
permanently unhealthy.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
git push -u origin PQPL-6496-status-checks-from-utils
gh pr create --fill
```

Add to the PR description:

```
🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

---

## Notes for the reviewer

Three decisions in this plan are worth a second look, because each trades something:

**The narrowed readiness default is a behaviour change for anyone who copies PR #854's config.** It is the change that makes the package installable anywhere, and betmaker-bo is insulated by an explicit env pin — but a project that publishes the config and never sets `STATUS_READY_CHECKS` gets a weaker readiness check than PR #854 describes. That is the safe direction to fail in (a missing probe, not a permanent 503), though it is a real difference.

**`mysql_ro` survives as a component name while ceasing to be a connection name.** Task 2 Step 4 guards this. Renaming the `CHECKS` key would rename the field in every consumer's `/status` payload and break anything parsing it.

**`dev-main` means betmaker-bo tracks the package's main branch.** Any commit to playlogiq-utils reaches betmaker-bo on its next `composer update`. That matches how the package is already consumed, but it does mean Task 7 must not merge before Tasks 1–6.
