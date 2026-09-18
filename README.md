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
