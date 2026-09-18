# Sharing the status-check subsystem via playlogiq-utils

Date: 2026-09-18
Status: approved, not yet implemented

## Problem

betmaker-bo PR #854 adds a health and readiness subsystem: an 11-probe
`/status` report, an 8-probe readiness set behind `GET checkHttpStatus`, two
value objects, and a 215-line annotated config. The code lives in the
application, so every other Playlogiq Laravel project that needs the same
endpoint would copy it — and the copies would drift the first time a probe or
a threshold changed.

The subsystem is a good candidate for extraction because it is almost entirely
`config()`-driven: the betmaker-specific components (`mysql_bo`, `mongodb`,
`passport_keys`) are selected by configuration, not hardcoded.

## Scope

Moves into `playlogiq/playlogiq-utils`:

- `StatusCheckService`
- `ComponentStatus`, `StatusReport`
- `config/status.php`
- `SecurityHeaders` middleware, with its cache-failure guard

Deliberately excluded:

- The `checkHttpStatus` route and its logging body. Each app decides what to
  log and what to return; the package exposes the report, not the endpoint.
- The tests from PR #854. The package has no test harness today, and adding
  PHPUnit plus Orchestra Testbench is its own piece of work.
- `PreventRequestsDuringMaintenance`. It extends a framework class and is
  necessarily per-application; see "Consumer steps" below.

## Package layout

```
src/Status/StatusCheckService.php      PlaylogiqUtils\Status\StatusCheckService
src/Status/ComponentStatus.php         PlaylogiqUtils\Status\ComponentStatus
src/Status/StatusReport.php            PlaylogiqUtils\Status\StatusReport
src/Status/StatusServiceProvider.php   PlaylogiqUtils\Status\StatusServiceProvider
src/Middleware/SecurityHeaders.php     PlaylogiqUtils\Middleware\SecurityHeaders
config/status.php
```

A single flat `Status\` namespace replaces the application's
`App\Services\Status` / `App\ValueObjects\Status` split. Three classes do not
need two directories, and a shallow namespace matches the package's existing
`Middleware\`, `Jobs\` and `Polyfills\` shape.

## Service provider

`StatusServiceProvider::register()` calls
`mergeConfigFrom(__DIR__.'/../../config/status.php', 'status')`.

`boot()` registers a publish group so an application can take the annotated
file locally when it wants to edit it rather than drive it from env:

```
php artisan vendor:publish --tag=playlogiq-status-config
```

The provider is listed under `extra.laravel.providers` in the package's
`composer.json`, so Laravel package auto-discovery registers it. A consuming
application therefore gets working defaults with no wiring at all.

No container binding is registered: `app(StatusCheckService::class)` already
resolves through the container's zero-argument autowiring.

## Configuration

The config file ships as written in PR #854, with the four changes below.

### `checks` ships verbatim

The `/status` probes already degrade gracefully: `probeMysql()`,
`probeMongodb()` and their peers return `ComponentStatus::SKIPPED` when the
connection they target is absent from `config('database.connections.*')`, and
`StatusReport::overallStatus()` treats `SKIPPED` as healthy. The full default
list is therefore safe in a project that has no Mongo, no `mysql_ro` and no
backoffice connection: those components are reported as skipped and the
endpoint still answers 200.

### `readiness.checks` gets a conservative default

The readiness probes behave the opposite way, by design. `probeAuthenticated
Database()` and `probeReadinessMongodb()` **throw** when their connection is
not configured, because readiness answers "may this instance receive traffic?"
and a missing core dependency is not a thing to skip past.

Shipping PR #854's readiness default —
`database,database_read,redis,mongodb,config,app_key,storage,passport_keys` —
would therefore make any project without a read replica, Mongo, or Passport
answer 503 immediately on install.

The package default is the universal core:

```php
'checks' => $statusList('STATUS_READY_CHECKS', 'database,redis,config,app_key,storage'),
```

`database_read`, `mongodb` and `passport_keys` become opt-in per application
through `STATUS_READY_CHECKS`.

### `mysql_ro` stops being hardcoded

`probeReadinessDatabaseRead()` and `probeMysqlReadOnlyConnection()` currently
name the `'mysql_ro'` connection in code. Both change to read a new top-level
key:

```php
'read_connection' => env('STATUS_READ_CONNECTION', 'mysql_ro'),
```

This mirrors `probeMysqlBackoffice()`, which already reads
`config('database.bo_connection', 'mysql_bo')`. The default preserves current
behaviour.

### Keys describing the endpoint are dropped

`StatusCheckService` reads exactly ten config keys: `checks`, `critical`,
`tcp_probe`, `tcp_timeout`, `queue_lag_warning_seconds`, `queue_count_failed`,
`disk_free_warning_percent`, `readiness.checks`,
`readiness.require_authentication` and `readiness.required_config`.

Three keys in PR #854's file are read by nothing: `detail`,
`min_interval_seconds` and `readiness.min_interval_seconds`. They describe the
detail level and the snapshot rate limit of the `/status` endpoint — behaviour
that lives in the route, which this package deliberately does not ship. Keeping
them would document a feature the package does not implement.

They are dropped from the package config, along with their comment blocks. An
application that implements the snapshot behaviour in its own route declares
its own keys for it. `StatusReport::toArray(bool $withDetails)` already takes
the detail level as an argument, so a route controls it directly.

### `excluded_integrations` empties out

`readiness.excluded_integrations` and `exclusion_rationale` are an inventory of
betmaker's own third parties — sportsbook providers, payment providers, KYC,
SMS. That is application knowledge, not shared knowledge. The package default
is an empty list with the explanatory comment retained; betmaker-bo keeps its
inventory in its published config.

## No behaviour change for betmaker-bo

betmaker-bo pins the readiness set it reviewed in PR #854:

```
STATUS_READY_CHECKS=database,database_read,redis,mongodb,config,app_key,storage,passport_keys
```

and publishes `config/status.php` to carry its `excluded_integrations`
inventory. Every other default already matches. The endpoint behaves exactly as
merged.

## SecurityHeaders

Ported with the cache guard from PR #854 — the global middleware must not 500
every request when the cache is the component that is down:

```php
try {
    $flag = (int) Cache::get('ff:csp:report_only', 0);
} catch (\Throwable $e) {
    $flag = 0;
}
```

Removed as unreachable: the commented-out CSP policy assignment, the
`allowListFromHost()` method, and the `brandFromFapiHost()` method. Both
methods are called only from inside the commented block. `handle()`,
`isHtml()` and `isFapiHost()` are kept.

## PHP compatibility

The package declares `php: >=7.4` and Laravel `^5.8|...|^9.0`. This is
preserved:

- `StatusCheckService`, `ComponentStatus` and `StatusReport` use typed
  properties, nullable types and `declare(strict_types=1)` — all PHP 7.4 — and
  contain no PHP 8-only syntax (no `match`, no nullsafe operator, no promoted
  constructor properties, no `str_*_with`).
- The only `str_starts_with()` calls in the tree are in `allowListFromHost()`
  and `brandFromFapiHost()`, both of which this spec deletes.

No new runtime dependency is added. `illuminate/support`, `illuminate/database`
and `illuminate/http` are already required.

## Consumer steps

For betmaker-bo, as a follow-up PR on top of #854:

1. Require `playlogiq/playlogiq-utils` (VCS repository entry plus
   `"dev-main"`, per the package README).
2. Delete `app/Services/Status/StatusCheckService.php`,
   `app/ValueObjects/Status/ComponentStatus.php`,
   `app/ValueObjects/Status/StatusReport.php`, and
   `app/Http/Middleware/SecurityHeaders.php`.
3. Repoint `routes/web.php` at `PlaylogiqUtils\Status\StatusCheckService` and
   `PlaylogiqUtils\Status\StatusReport`.
4. Repoint the middleware stack in `app/Http/Kernel.php` at
   `PlaylogiqUtils\Middleware\SecurityHeaders`.
5. Publish `config/status.php` and restore the `excluded_integrations`
   inventory into it.
6. Add `STATUS_READY_CHECKS` to `.env` and `.env.example`.
7. Update the test namespaces in `tests/Unit/ValueObjects/Status/
   StatusReportTest.php` and `tests/Feature/Http/Controllers/
   CheckHttpStatusTest.php`. The tests stay in the application.

Keep in `app/Http/Middleware/PreventRequestsDuringMaintenance.php`:

```php
protected $except = [
    'checkHttpStatus',
];
```

This class extends `Illuminate\Foundation\Http\Middleware\
PreventRequestsDuringMaintenance` and is generated per application, so the
exception cannot live in the package. The package README documents it as a
required step for any consumer that adds a health endpoint.

## README

Add a "Status checks" section covering: what the provider registers, the
difference between the `/status` report and the readiness set, how to opt into
`database_read` / `mongodb` / `passport_keys`, a minimal route example, and the
`PreventRequestsDuringMaintenance` step.
