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
    |   mysql           primary MySQL — connect (which authenticates) + query
    |   mysql_ro        the read replica core models read through
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
        'checks' => $statusList('STATUS_READY_CHECKS', 'mysql,redis,config,app_key,storage'),

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
