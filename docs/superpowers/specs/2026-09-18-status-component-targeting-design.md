# Per-project component targeting for status checks

Date: 2026-09-18
Status: approved, not yet implemented
Amends: `2026-09-18-status-check-sharing-design.md` (its "Configuration" section)

## Problem

The shared status subsystem assumes every project runs the same services under
the same names. Two assumptions are wrong:

**Connection names are hardcoded.** Six of them, in `StatusCheckService`:

| Lines | Probe | Hardcoded target |
|---|---|---|
| 193, 198 | `probeMysqlWrite`, `probeMysqlRead` | `'mysql'` |
| 276, 292 | `probeMongodb` | `'mongodb'` |
| 348 | `probeRedisDefault` | `'default'` |
| 353 | `probeRedisOthers` | `'others'` |
| 711, 723 | `probeReadinessRedis` | `'default'` |
| 752, 768 | `probeReadinessMongodb` | `'mongodb'` |

A project whose Redis connection is named `sessions`, or whose MySQL connection
is named `primary`, cannot point the checks at it. Only the replica
(`status.read_connection`) and the backoffice connection
(`database.bo_connection`) are configurable today.

**Enablement, criticality and targeting are spread across three keys.**
`status.checks` says what runs, `status.critical` says what is fatal, and the
target lives either in code or in a separate key. Answering "what does this
project check, and where does it point?" means reading three places and the
service source.

The `/status` probes skip a missing connection gracefully, so the second problem
is mostly ergonomic there. It is not ergonomic in readiness, where a probe
throws: a project must know to remove `mongodb` from `STATUS_READY_CHECKS` or
its instances go permanently unhealthy.

## Scope

In: config-driven targets for the eleven components the package already knows,
a per-component `enabled` flag, and folding `critical` into the same structure.

Out: an open registry where a project declares components the package does not
know about (a second Mongo, a third Redis, an arbitrary HTTP dependency). The
component set stays closed and documented. Revisit once a second and third
project have shown what they actually need.

## The `components` map

`status.checks`, `status.critical` and `status.read_connection` are replaced by
one block, keyed by the component names that appear in the `/status` payload:

```php
'components' => [
    'mysql'         => ['enabled' => env('STATUS_CHECK_MYSQL', true),         'connection' => env('STATUS_CONN_MYSQL', 'mysql'),           'critical' => true],
    'mysql_read'    => ['enabled' => env('STATUS_CHECK_MYSQL_READ', true),    'connection' => env('STATUS_CONN_MYSQL', 'mysql')],
    'mysql_ro'      => ['enabled' => env('STATUS_CHECK_MYSQL_RO', false),     'connection' => env('STATUS_CONN_MYSQL_RO', 'mysql_ro')],
    'mysql_bo'      => ['enabled' => env('STATUS_CHECK_MYSQL_BO', false),     'connection' => env('STATUS_CONN_MYSQL_BO', 'mysql_bo')],
    'mongodb'       => ['enabled' => env('STATUS_CHECK_MONGODB', false),      'connection' => env('STATUS_CONN_MONGODB', 'mongodb')],
    'redis'         => ['enabled' => env('STATUS_CHECK_REDIS', true),         'connection' => env('STATUS_CONN_REDIS', 'default'),         'critical' => true],
    'redis_others'  => ['enabled' => env('STATUS_CHECK_REDIS_OTHERS', false), 'connection' => env('STATUS_CONN_REDIS_OTHERS', 'others')],
    'cache'         => ['enabled' => env('STATUS_CHECK_CACHE', true),         'store'      => env('STATUS_CACHE_STORE', null), 'critical' => true],
    'queue'         => ['enabled' => env('STATUS_CHECK_QUEUE', true)],
    'storage'       => ['enabled' => env('STATUS_CHECK_STORAGE', true)],
    'passport_keys' => ['enabled' => env('STATUS_CHECK_PASSPORT_KEYS', false)],
],
```

Three things to know about the shape:

`mysql` and `mysql_read` share `STATUS_CONN_MYSQL` on purpose. They are the
write and read PDO of a single connection, so giving them separate target
variables would invite pointing them at different databases, which the report
would then silently misdescribe.

`store => null` on `cache` means "follow `cache.default`", which is today's
behaviour. A non-null value targets a named store.

A missing `critical` key means false. Only `mysql`, `redis` and `cache` default
to true, matching the current `STATUS_CRITICAL_CHECKS` default.

### Defaults reflect what a typical project runs

`mysql_ro`, `mysql_bo`, `mongodb`, `redis_others` and `passport_keys` ship
**disabled**. The previous design shipped all eleven enabled and relied on the
skip-when-unconfigured behaviour to keep that harmless. That works for the
report but makes the config read as though every project has a backoffice
database. Defaulting them off states the assumption instead of hiding it;
betmaker-bo enables the five it runs.

### `enabled: false` wins everywhere

A disabled component is skipped by `run()` and excluded from readiness, even
when `readiness.checks` names it. "This project does not run Mongo" is one
fact, and it is stated once. Without this rule the same fact would have to be
repeated in two places and could disagree with itself.

## Readiness

Readiness keeps a membership list, naming components from the map above plus
the two self-checks that have no target:

```php
'readiness' => [
    'checks' => $statusList('STATUS_READY_CHECKS', 'mysql,redis,config,app_key,storage'),
    // require_authentication, required_config, excluded_integrations unchanged
],
```

`critical` is not consulted for readiness: every member of the readiness set is
required, which is what distinguishes readiness from the report.

### Two readiness components are renamed

`database` becomes `mysql`, and `database_read` becomes `mysql_ro`. This is the
cost of declaring each connection once: the readiness set now names the same
components the report does, rather than a parallel vocabulary for the same
services.

Consequences, both in betmaker-bo:

- The readiness log payload's `components.database` and
  `components.database_read` keys become `components.mysql` and
  `components.mysql_ro`. Any log query or dashboard matching those keys needs
  updating.
- `tests/Feature/Http/Controllers/CheckHttpStatusTest.php` asserts on component
  names and must be updated in the same PR.

There is a second, quieter change here. `probeReadinessDatabase` currently
targets `config('database.default', 'mysql')` while `probeMysqlWrite` targets
the literal `'mysql'` — so today the report and readiness can probe different
connections without saying so. After this change both follow
`components.mysql.connection`. In an application where `database.default` is
`'mysql'`, which is the case in betmaker-bo, nothing changes; in one where it
is not, readiness starts probing the connection the config names rather than
the one the framework defaults to.

## Service changes

`CHECKS` and `READINESS_CHECKS` merge into one map carrying both probe variants
per component:

```php
private const COMPONENTS = [
    'mysql'         => ['probe' => 'probeMysql',        'ready' => 'probeAuthenticatedDatabase', 'target' => 'connection', 'role' => 'primary'],
    'mysql_read'    => ['probe' => 'probeMysql',                                                 'target' => 'connection', 'read' => true],
    'mysql_ro'      => ['probe' => 'probeMysql',        'ready' => 'probeAuthenticatedDatabase', 'target' => 'connection', 'read' => true, 'role' => 'read replica'],
    'mysql_bo'      => ['probe' => 'probeMysql',                                                 'target' => 'connection'],
    'mongodb'       => ['probe' => 'probeMongodb',      'ready' => 'probeReadinessMongodb',      'target' => 'connection'],
    'redis'         => ['probe' => 'probeRedis',        'ready' => 'probeReadinessRedis',        'target' => 'connection'],
    'redis_others'  => ['probe' => 'probeRedis',                                                 'target' => 'connection'],
    'cache'         => ['probe' => 'probeCache',                                                 'target' => 'store'],
    'queue'         => ['probe' => 'probeQueue'],
    'storage'       => ['probe' => 'probeStorage',      'ready' => 'probeStorage'],
    'passport_keys' => ['probe' => 'probePassportKeys', 'ready' => 'probePassportKeys'],
    'config'        => [                                'ready' => 'probeConfig'],
    'app_key'       => [                                'ready' => 'probeAppKey'],
];
```

A component with no `ready` entry cannot appear in the readiness set; naming it
in `readiness.checks` is a configuration error (see below). A component with no
`probe` entry — `config` and `app_key` — never appears in the report.

`target` names which config key holds the component's target: `connection` for
the database and Redis components, `store` for `cache`, absent for the ones
that have no target. `read` selects the read PDO of a read/write split
connection, and `role` is the label `probeAuthenticatedDatabase()` already puts
in its readiness details ("primary", "read replica").

Eight wrapper methods exist only to hardcode a target and are deleted:
`probeMysqlWrite`, `probeMysqlRead`, `probeMysqlReadOnlyConnection`,
`probeMysqlBackoffice`, `probeRedisDefault`, `probeRedisOthers`,
`probeReadinessDatabase` and `probeReadinessDatabaseRead`. Their bodies become a
target lookup in `run()` and `runReadiness()`, which resolve the connection or
store from the component's config entry and pass it to the shared probe.

Every probe body — the MySQL, Mongo, Redis, cache, queue, storage and Passport
logic — is unchanged. `probeCache` gains one change it needs in order to honour
a target at all: it currently round-trips through `Cache::put()` / `Cache::get()`
on the default store while only *reporting* `cache.default` in its details. It
must round-trip through `Cache::store($store)` so a configured store is actually
the one exercised.

`probeRedis` and `probeMysql` already take a connection argument and already
return `SKIPPED` when that connection is absent, so they need no change.

## Validation

A name in `readiness.checks` that is not a key of `COMPONENTS`, or that names a
component with no `ready` probe, is a configuration error that would otherwise
fail silently by simply not running. `runReadiness()` reports it as a failed
component named after the offending entry, with the error
`unknown readiness component [<name>]`. A failed component in the readiness set
makes the report unhealthy, so a typo in `STATUS_READY_CHECKS` surfaces as a
503 with a precise message rather than a quietly reduced check set.

The same rule is not applied to the `components` map itself: an unrecognised key
there is ignored, because a project that publishes the config and adds a
forward-looking entry should not break on upgrade.

## Where this lands

PR #3 on `playlogiq-utils` is open, unmerged, and has no consumers. This design
amends that PR rather than following it: shipping a config contract and
replacing it one commit later would leave two shapes in the history and a
migration note for a migration nobody performed.

The betmaker-bo switchover (Task 7 of the existing plan) is updated in place:
its `.env` pin becomes the new per-component variables, and the readiness
component rename is applied to its test.

## What betmaker-bo sets

```
STATUS_CHECK_MYSQL_RO=true
STATUS_CHECK_MYSQL_BO=true
STATUS_CHECK_MONGODB=true
STATUS_CHECK_REDIS_OTHERS=true
STATUS_CHECK_PASSPORT_KEYS=true

STATUS_READY_CHECKS=mysql,mysql_ro,redis,mongodb,config,app_key,storage,passport_keys
```

This reproduces PR #854's behaviour exactly: all eleven components in the
report, the same eight in the readiness set, the same connections. Only the two
renamed readiness keys differ in the output.
