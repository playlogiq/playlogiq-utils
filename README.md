🍔 Playlogiq Utils: A comprehensive library of reusable PHP functions and classes to facilitate rapid development and maintain consistency across Playlogiq's codebase.


How to add composer directives:

The instructions for using a custom VCS repository are added to your project's composer.json file, not the GitHub README of the package you want to install.


📦 Adding Composer Directives 


Add the Repository to your Project's composer.json
In the repositories section of the composer.json for the project where you want to install the package, add an entry for the GitHub repository:



    "repositories": [
    
    {
    
    "type": "vcs",
    
    "url": "https://github.com/playlogiq/playlogiq-utils.git"
    
    }
    



"type": "vcs": This tells Composer to treat the URL as a Version Control System repository.

"url": This is the URL of the GitHub repository.


🎷 Require the Package


Once the repository is defined, you can require the package using the package name defined in its own composer.json file (which may differ from the repository name).



    // ... (repositories section above)
    
    "require": {
    
        "playlogiq/playlogiq-utils": "dev-main"
        
    }
    


🏷️ Changing the Branch Reference in composer.json


To require a specific branch X of a package using a VCS repository, you need to use the dev- prefix followed by the branch name as the version constraint in your require block.


The format for requiring a branch is:

"dev-X"

Where X is the branch name.

Example

If the package you are installing has a branch named feature/bugfix-123, you would require it as:



    "repositories": [
    
        {
        
            "type": "vcs",
            
            "url": "https://github.com/playlogiq/playlogiq-utils.git"
            
        }
        
    ],
    
    "require": {
    
        "playlogiq/playlogiq-utils": "dev-feature/bugfix-123"
        
    }
    


When the branch name is non-numeric (like main, develop, or feature/bugfix-123), you must prefix it with dev-.

Composer recognizes this dev- prefix and fetches the code from the specified branch.


Note on Numeric Branches

If your branch name looks like a version (e.g., 1.x or 2.0), Composer uses a slightly different format:

For a branch named 1.x, you would use the constraint "1.x-dev". Composer appends -dev instead of prepending dev-.

For non-version-like names, the dev-X format is the correct one.

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

> **Upgrading from the previous checks/critical-list config?** Two config
> indirections went away along with it. `mysql_bo` used to resolve its
> connection through `config('database.bo_connection')`, and the readiness
> database probe used to read `config('database.default')`; both now fall back
> to a literal default (`mysql_bo` and `mysql` respectively) when a component
> has no entry in `status.components`. A project running the current
> `config/status.php` is unaffected, since every component there already ships
> an explicit `connection` with an env override. The case that bites is a
> project still on an **older published config** whose default database
> connection isn't literally named `mysql` — set `STATUS_CONN_MYSQL` (and
> `STATUS_CONN_MYSQL_BO` if that check is in use) explicitly.

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

### Security headers

`PlaylogiqUtils\Middleware\SecurityHeaders` is a global middleware that strips
CSP and framing headers from HTML responses when the `ff:csp:report_only` cache
flag is set. It swallows a cache failure and falls back to "flag off", so a
Redis outage cannot 500 every request — including the health endpoint.
