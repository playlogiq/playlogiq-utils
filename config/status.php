<?php

declare(strict_types=1);

/**
 * Reads a comma separated env list, falling back to $default when the variable
 * is unset or empty — an empty STATUS_CHECKS must not silently disable every
 * check.
 */
$statusList = static function (string $key, string $default): array {
    $raw = (string) env($key, '');
    $names = array_values(array_filter(array_map('trim', explode(',', $raw))));

    if ($names !== []) {
        return $names;
    }

    return array_values(array_filter(array_map('trim', explode(',', $default))));
};

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled Checks
    |--------------------------------------------------------------------------
    |
    | Components reported by GET /status, in report order. Remove a name here
    | (or via STATUS_CHECKS, a comma separated list) to stop probing it — a
    | disabled component is simply absent from the payload.
    |
    | Available: mysql, mysql_read, mysql_ro, mysql_bo, mongodb, redis,
    |            redis_others, cache, queue, storage, passport_keys
    |
    */

    'checks' => $statusList('STATUS_CHECKS', 'mysql,mysql_read,mysql_ro,mysql_bo,mongodb,redis,redis_others,cache,queue,storage,passport_keys'),

    /*
    |--------------------------------------------------------------------------
    | Critical Checks
    |--------------------------------------------------------------------------
    |
    | Only a failing check listed here makes GET /status answer 503. Everything
    | else degrades the report ("degraded") while still answering 200, so a
    | peripheral outage does not deregister every instance behind the load
    | balancer. Keep this to the components the app genuinely cannot serve
    | traffic without: the primary MySQL writer, the Redis cache backend and
    | the cache store itself.
    |
    */

    'critical' => $statusList('STATUS_CRITICAL_CHECKS', 'mysql,redis,cache'),

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

    /*
    |--------------------------------------------------------------------------
    | TCP Pre-probe
    |--------------------------------------------------------------------------
    |
    | Each network component is TCP-probed with a short timeout before the real
    | driver call is attempted, so an unreachable host fails in seconds instead
    | of hanging on the driver's own (much longer) connect timeout.
    |
    */

    'tcp_probe' => (bool) env('STATUS_TCP_PROBE', true),

    'tcp_timeout' => (float) env('STATUS_TCP_TIMEOUT', 2.0),

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | queue_lag_warning_seconds  Oldest unreserved job older than this marks the
    |                            queue degraded — the usual symptom of dead or
    |                            stuck workers. 0 disables the check.
    | queue_count_failed         COUNT(*) over failed_jobs. Disable it if that
    |                            table has grown large enough for the count to
    |                            be slow.
    | disk_free_warning_percent  Free space on the storage volume below this
    |                            marks storage degraded.
    |
    */

    'queue_lag_warning_seconds' => (int) env('STATUS_QUEUE_LAG_WARNING', 300),

    'queue_count_failed' => (bool) env('STATUS_QUEUE_COUNT_FAILED', true),

    'disk_free_warning_percent' => (float) env('STATUS_DISK_FREE_WARNING', 10.0),

    /*
    |--------------------------------------------------------------------------
    | Readiness
    |--------------------------------------------------------------------------
    |
    | GET /status/ready (alias GET /ready) decides whether this instance may
    | receive traffic. It is the path the load balancer target group is gated
    | on, so it answers 200 only when the instance can actually serve a player
    | request end to end, and 503 otherwise.
    |
    | `checks` is the readiness set. Unlike /status there is no critical list:
    | every component here is required, and any one of them failing means the
    | instance is not ready.
    |
    |   database        primary MySQL — connect (which authenticates) + query
    |   database_read   the mysql_ro replica core models read through
    |   redis           cache/session backend, write+read round-trip
    |   mongodb         authorised command, not just a pre-auth ping
    |   config          essential configuration resolves
    |   app_key         APP_KEY present, valid for the cipher, and functional
    |   storage         storage volume genuinely writable
    |   passport_keys   OAuth signing keys usable (no keys, no player can auth)
    |
    */

    'readiness' => [

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

        /*
        | Whether the ABSENCE of credentials on a core component fails readiness.
        |
        | Readiness always proves that credentials which ARE configured were
        | accepted — a rejected password fails readiness whatever this is set to.
        | This setting covers the different case of a component reachable with no
        | authentication at all (e.g. Redis with no requirepass): it is reported
        | as a warning by default, because turning a credential misconfiguration
        | into a 503 would deregister every instance at once and take the
        | platform down. Set it true once every core component in an environment
        | has credentials, and readiness will then enforce that.
        */
        'require_authentication' => (bool) env('STATUS_READY_REQUIRE_AUTH', false),

        /*
        | Configuration that must resolve for the instance to serve anything.
        | Listed here rather than in code so the "config loaded" self-check is
        | itself reviewable.
        */
        'required_config' => [
            'app.key',
            'app.env',
            'app.cipher',
            'database.default',
            'cache.default',
            'queue.default',
            'session.driver',
        ],

        /*
        | Third-party integrations are explicitly NOT part of readiness. The
        | platform serves without them; only their own flows degrade, and that
        | is handled inside those flows rather than by refusing traffic to the
        | whole instance. This list is version controlled precisely so the
        | exclusion is auditable, and it is echoed in the endpoint's response.
        */
        'excluded_integrations' => [
            // Per application: 'sportsbook' => ['provider_a', 'provider_b'], …
        ],

        'exclusion_rationale' => 'The platform serves player traffic without these; an outage in one degrades only that integration\'s own flows. Gating instance registration on a third party would let an external outage empty the target group.',

    ],

];
