<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Status;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Probes every infrastructure component the application depends on.
 *
 * Every probe is wrapped: it either returns its details or throws, and the
 * wrapper turns a throw into a failed component. A broken dependency therefore
 * never breaks the report itself — which is the whole point of the endpoint.
 */
class StatusCheckService
{
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

    /**
     * Configuration that must resolve for the instance to serve anything at
     * all. Overridable via `status.readiness.required_config`.
     */
    public const REQUIRED_CONFIG_KEYS = [
        'app.key',
        'app.env',
        'app.cipher',
        'database.default',
        'cache.default',
        'queue.default',
        'session.driver',
    ];

    /**
     * Directories that have to be writable for the app to log, cache and keep
     * file sessions.
     */
    private const STORAGE_PATHS = [
        'app',
        'framework/cache',
        'framework/sessions',
        'framework/views',
        'logs',
    ];

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

        foreach ($this->unknownReadinessComponents() as $name) {
            $components[$name] = ComponentStatus::failed(
                $name,
                "unknown readiness component [{$name}]"
            )->withCritical(true);
        }

        // Every name in the readiness set can be disabled or unknown at once,
        // in which case the loops above leave nothing behind and an empty
        // report reads as healthy. An instance that verified nothing must not
        // tell the load balancer it is ready.
        if ($components === []) {
            $components['readiness'] = ComponentStatus::failed(
                'readiness',
                'the readiness set resolved to no components — every name in status.readiness.checks is disabled or unknown'
            )->withCritical(true);
        }

        return new StatusReport($components, $this->appMeta(), (microtime(true) - $startedAt) * 1000);
    }

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

    /**
     * Runs a probe, times it, and normalises both its result and any throw.
     */
    private function measure(string $name, string $probe, array $args = []): ComponentStatus
    {
        $startedAt = microtime(true);

        try {
            $result = $this->{$probe}(...$args);
            $latency = $this->elapsedMs($startedAt);
            $status = $result['status'] ?? ComponentStatus::OK;
            $details = $result['details'] ?? [];

            if ($status === ComponentStatus::SKIPPED) {
                return ComponentStatus::skipped($name, (string) ($result['error'] ?? 'not configured'), $details);
            }

            if ($status === ComponentStatus::DEGRADED) {
                return ComponentStatus::degraded($name, (string) ($result['error'] ?? 'threshold breached'), $latency, $details);
            }

            // A probe returns FAILED (rather than throwing) when it has details
            // worth keeping alongside the failure.
            if ($status === ComponentStatus::FAILED) {
                return ComponentStatus::failed($name, (string) ($result['error'] ?? 'check failed'), $latency, $details);
            }

            return ComponentStatus::ok($name, $latency, $details);
        } catch (Throwable $e) {
            return ComponentStatus::failed($name, $this->describe($e), $this->elapsedMs($startedAt));
        }
    }

    // -----------------------------------------------------------------
    // MySQL
    // -----------------------------------------------------------------

    /**
     * @param bool $useReadPdo probe the read host of a read/write split connection
     */
    private function probeMysql(string $connection, bool $useReadPdo): array
    {
        $config = (array) config('database.connections.' . $connection);

        if ($config === []) {
            return ['status' => ComponentStatus::SKIPPED, 'error' => "connection [{$connection}] is not configured"];
        }

        [$host, $port] = $this->mysqlEndpoint($config, $useReadPdo);
        $this->assertReachable($host, $port);

        $db = DB::connection($connection);
        $row = $db->select('select 1 as probe', [], $useReadPdo);

        if ((int) ($row[0]->probe ?? 0) !== 1) {
            throw new RuntimeException('probe query did not return the expected row');
        }

        $pdo = $useReadPdo ? $db->getReadPdo() : $db->getPdo();

        return [
            'details' => [
                'connection' => $connection,
                'driver' => $db->getDriverName(),
                'host' => $host,
                'port' => $port,
                'database' => $db->getDatabaseName(),
                'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                'pdo' => $useReadPdo ? 'read' : 'write',
            ],
        ];
    }

    /**
     * @return array{0: ?string, 1: ?int} host/port, or [null, null] when the
     *         connection uses a unix socket and there is nothing to TCP-probe
     */
    private function mysqlEndpoint(array $config, bool $read): array
    {
        if (! empty($config['unix_socket'])) {
            return [null, null];
        }

        $key = $read ? 'read' : 'write';
        $host = $config['host'] ?? null;

        if (isset($config[$key]['host'])) {
            $candidates = (array) $config[$key]['host'];
            $host = $candidates[0] ?? $host;
        }

        $port = isset($config['port']) ? (int) $config['port'] : null;

        return [is_string($host) ? $host : null, $port];
    }

    // -----------------------------------------------------------------
    // MongoDB
    // -----------------------------------------------------------------

    private function probeMongodb(string $connection): array
    {
        $config = (array) config('database.connections.' . $connection);

        if ($config === []) {
            return ['status' => ComponentStatus::SKIPPED, 'error' => "connection [{$connection}] is not configured"];
        }

        if (! extension_loaded('mongodb')) {
            return ['status' => ComponentStatus::SKIPPED, 'error' => 'the mongodb PHP extension is not loaded'];
        }

        $dsn = (string) ($config['dsn'] ?? '');
        $database = (string) ($config['database'] ?? '');
        [$host, $port] = $this->mongoEndpoint($dsn);
        $this->assertReachable($host, $port);

        /** @var \Jenssegers\Mongodb\Connection $mongo */
        $mongo = DB::connection($connection);

        $result = $mongo->getMongoClient()
            ->selectDatabase($database)
            ->command(['ping' => 1])
            ->toArray();

        $ok = isset($result[0]['ok']) ? (float) $result[0]['ok'] : 0.0;

        if ($ok !== 1.0) {
            throw new RuntimeException('ping command did not return ok=1');
        }

        return [
            'details' => [
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'extension_version' => phpversion('mongodb') ?: null,
            ],
        ];
    }

    /**
     * Pulls the first host:port out of a Mongo DSN. Returns [null, null] for
     * mongodb+srv:// and multi-host DSNs, where a single TCP probe would say
     * nothing useful — the driver's own selection handles those.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function mongoEndpoint(string $dsn): array
    {
        if ($dsn === '' || Str::startsWith($dsn, 'mongodb+srv://')) {
            return [null, null];
        }

        $withoutScheme = (string) preg_replace('#^mongodb://#', '', $dsn);
        $authority = Str::before($withoutScheme, '/');
        $authority = Str::contains($authority, '@') ? Str::after($authority, '@') : $authority;

        if ($authority === '' || Str::contains($authority, ',')) {
            return [null, null];
        }

        $host = Str::before($authority, ':');
        $port = Str::contains($authority, ':') ? (int) Str::after($authority, ':') : 27017;

        return [$host !== '' ? $host : null, $port];
    }

    // -----------------------------------------------------------------
    // Redis
    // -----------------------------------------------------------------

    /**
     * A keyed SETEX/GET/DEL round-trip rather than PING: it works the same in
     * standalone and cluster mode, and it proves the node actually accepts
     * writes instead of merely holding the socket open.
     */
    private function probeRedis(string $connection): array
    {
        $seeds = $this->redisSeeds($connection);

        if ($seeds === []) {
            return ['status' => ComponentStatus::SKIPPED, 'error' => "redis connection [{$connection}] is not configured"];
        }

        $first = $seeds[0];
        $this->assertReachable($first['host'] ?? null, isset($first['port']) ? (int) $first['port'] : null);

        $key = 'status:probe:' . Str::random(16);
        $value = (string) microtime(true);

        $redis = Redis::connection($connection);
        $redis->setex($key, 10, $value);
        $readBack = $redis->get($key);
        $redis->del($key);

        if ((string) $readBack !== $value) {
            throw new RuntimeException('write/read round-trip returned a different value');
        }

        return [
            'details' => [
                'connection' => $connection,
                'client' => config('database.redis.client'),
                'cluster' => config('database.redis.options.cluster'),
                'nodes' => array_map(static function (array $seed): string {
                    return ($seed['host'] ?? '?') . ':' . ($seed['port'] ?? '?');
                }, $seeds),
                'round_trip' => 'ok',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function redisSeeds(string $connection): array
    {
        $cluster = config('database.redis.clusters.' . $connection);

        if (is_array($cluster) && $cluster !== []) {
            return array_values($cluster);
        }

        $single = config('database.redis.' . $connection);

        return is_array($single) && $single !== [] ? [$single] : [];
    }

    // -----------------------------------------------------------------
    // Cache
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // Queue
    // -----------------------------------------------------------------

    /**
     * Reports the queue backend plus depth, and degrades when the oldest
     * unreserved job has been waiting past the configured threshold — the usual
     * symptom of workers being dead rather than the backend being down.
     */
    private function probeQueue(): array
    {
        $connection = (string) config('queue.default');
        $config = (array) config('queue.connections.' . $connection);
        $driver = (string) ($config['driver'] ?? 'unknown');

        $details = [
            'connection' => $connection,
            'driver' => $driver,
        ];

        if ($driver !== 'database') {
            // Depth for the other drivers lives behind driver-specific key
            // layouts; the backend itself is already covered by its own check.
            $details['depth'] = null;
            $details['note'] = "depth is only collected for the database driver, not [{$driver}]";

            return ['details' => $details];
        }

        $db = DB::connection($config['connection'] ?? config('database.default'));
        $table = (string) ($config['table'] ?? 'jobs');

        $pending = (int) $db->table($table)->whereNull('reserved_at')->count();
        $reserved = (int) $db->table($table)->whereNotNull('reserved_at')->count();
        $oldestAvailableAt = $db->table($table)->whereNull('reserved_at')->min('available_at');
        $lag = $oldestAvailableAt !== null ? max(0, time() - (int) $oldestAvailableAt) : 0;

        $details['table'] = $table;
        $details['pending'] = $pending;
        $details['reserved'] = $reserved;
        $details['oldest_pending_age_seconds'] = $lag;
        $details['failed'] = $this->failedJobsCount();

        $threshold = (int) config('status.queue_lag_warning_seconds', 300);

        if ($threshold > 0 && $lag > $threshold) {
            return [
                'status' => ComponentStatus::DEGRADED,
                'error' => "oldest pending job has been waiting {$lag}s (threshold {$threshold}s) — workers may be stopped",
                'details' => $details,
            ];
        }

        return ['details' => $details];
    }

    /**
     * @return int|null null when counting is disabled or the table is absent
     */
    private function failedJobsCount(): ?int
    {
        if (! config('status.queue_count_failed', true)) {
            return null;
        }

        try {
            $table = (string) config('queue.failed.table', 'failed_jobs');

            return (int) DB::connection(config('queue.failed.database'))->table($table)->count();
        } catch (Throwable $e) {
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Storage volume
    // -----------------------------------------------------------------

    private function probeStorage(): array
    {
        $unwritable = [];
        $paths = [];

        foreach (self::STORAGE_PATHS as $relative) {
            $absolute = storage_path($relative);
            $writable = is_dir($absolute) && is_writable($absolute);
            $paths[$relative] = $writable ? 'writable' : (is_dir($absolute) ? 'not writable' : 'missing');

            if (! $writable) {
                $unwritable[] = $relative;
            }
        }

        $details = ['paths' => $paths] + $this->diskUsage();

        if ($unwritable !== []) {
            return [
                'status' => ComponentStatus::FAILED,
                'error' => 'not writable: ' . implode(', ', $unwritable),
                'details' => $details,
            ];
        }

        // Prove it rather than trusting the permission bits (a full or
        // read-only-remounted volume still looks writable to is_writable).
        $probeFile = storage_path('framework/cache/.status-probe');

        if (@file_put_contents($probeFile, (string) microtime(true)) === false) {
            return [
                'status' => ComponentStatus::FAILED,
                'error' => 'write probe to storage/framework/cache failed',
                'details' => $details,
            ];
        }

        @unlink($probeFile);

        $threshold = (float) config('status.disk_free_warning_percent', 10.0);
        $freePercent = $details['disk_free_percent'] ?? null;

        if ($threshold > 0 && $freePercent !== null && $freePercent < $threshold) {
            return [
                'status' => ComponentStatus::DEGRADED,
                'error' => sprintf('only %.1f%% free on the storage volume (threshold %.1f%%)', $freePercent, $threshold),
                'details' => $details,
            ];
        }

        return ['details' => $details];
    }

    private function diskUsage(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free === false || $total === false || ! $total) {
            return ['disk_free_percent' => null];
        }

        return [
            'disk_free_bytes' => (int) $free,
            'disk_total_bytes' => (int) $total,
            'disk_free_percent' => round(($free / $total) * 100, 2),
        ];
    }

    // -----------------------------------------------------------------
    // Passport signing keys
    // -----------------------------------------------------------------

    /**
     * Without a usable key pair every OAuth token issue/validate fails, which
     * looks like a total Public API outage with a perfectly healthy database.
     */
    private function probePassportKeys(): array
    {
        if (config('passport.private_key') && config('passport.public_key')) {
            return ['details' => ['source' => 'env']];
        }

        $privatePath = storage_path('oauth-private.key');
        $publicPath = storage_path('oauth-public.key');
        $details = ['source' => 'storage'];

        foreach (['private' => $privatePath, 'public' => $publicPath] as $label => $path) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("the {$label} key is missing or unreadable at " . basename($path));
            }

            $details[$label . '_key_permissions'] = substr(sprintf('%o', fileperms($path)), -4);
        }

        $contents = (string) @file_get_contents($privatePath);

        if (! extension_loaded('openssl')) {
            $details['parsed'] = 'skipped (openssl extension not loaded)';
        } elseif (openssl_pkey_get_private($contents) === false) {
            throw new RuntimeException('the private key exists but could not be parsed');
        } else {
            $details['parsed'] = 'ok';
        }

        // Group/world readable signing material is a routine audit finding.
        if ((fileperms($privatePath) & 0044) !== 0) {
            return [
                'status' => ComponentStatus::DEGRADED,
                'error' => 'the private key is readable beyond its owner (expected 0600)',
                'details' => $details,
            ];
        }

        return ['details' => $details];
    }

    // -----------------------------------------------------------------
    // Readiness probes
    //
    // These differ from the /status probes above in one respect: they assert
    // that the component is *authenticated*, not merely reachable, and they
    // report how authentication was proven so the answer is auditable.
    // -----------------------------------------------------------------

    /**
     * MySQL authenticates during connect: a PDO handed back at all means the
     * credentials were accepted. The query then proves the session is usable
     * and that it landed on the expected schema.
     */
    private function probeAuthenticatedDatabase(string $connection, bool $useReadPdo, string $role): array
    {
        $config = (array) config('database.connections.' . $connection);

        if ($config === []) {
            throw new RuntimeException("connection [{$connection}] is not configured");
        }

        [$host, $port] = $this->mysqlEndpoint($config, $useReadPdo);
        $this->assertReachable($host, $port);

        $db = DB::connection($connection);
        $row = $db->select('select 1 as probe', [], $useReadPdo);

        if ((int) ($row[0]->probe ?? 0) !== 1) {
            throw new RuntimeException('probe query did not return the expected row');
        }

        $expectedDatabase = (string) ($config['database'] ?? '');
        $actualDatabase = (string) $db->getDatabaseName();

        if ($expectedDatabase !== '' && $actualDatabase !== $expectedDatabase) {
            throw new RuntimeException("connected to schema [{$actualDatabase}] instead of [{$expectedDatabase}]");
        }

        $hasPassword = ! blank($config['password'] ?? null);

        return $this->withAuthenticationVerdict([
            'role' => $role,
            'connection' => $connection,
            'host' => $host,
            'port' => $port,
            'database' => $actualDatabase,
            'pdo' => $useReadPdo ? 'read' : 'write',
        ], $hasPassword, $hasPassword ? 'password (accepted at connect)' : 'none', $connection);
    }

    /**
     * With a password configured, every command would be refused with NOAUTH
     * unless AUTH succeeded — so a completed write/read round-trip is itself
     * the proof of authentication.
     */
    private function probeReadinessRedis(string $connection): array
    {
        $seeds = $this->redisSeeds($connection);

        if ($seeds === []) {
            throw new RuntimeException("redis connection [{$connection}] is not configured");
        }

        $first = $seeds[0];
        $this->assertReachable($first['host'] ?? null, isset($first['port']) ? (int) $first['port'] : null);

        $key = 'status:ready:' . Str::random(16);
        $value = (string) microtime(true);

        $redis = Redis::connection($connection);
        $redis->setex($key, 10, $value);
        $readBack = $redis->get($key);
        $redis->del($key);

        if ((string) $readBack !== $value) {
            throw new RuntimeException('write/read round-trip returned a different value');
        }

        $hasPassword = ! blank($first['password'] ?? null);

        return $this->withAuthenticationVerdict([
            'connection' => $connection,
            'client' => config('database.redis.client'),
            'nodes' => array_map(static function (array $seed): string {
                return ($seed['host'] ?? '?') . ':' . ($seed['port'] ?? '?');
            }, $seeds),
            'round_trip' => 'ok',
        ], $hasPassword, $hasPassword ? 'password (AUTH accepted)' : 'none', 'redis');
    }

    /**
     * `ping` is on Mongo's pre-authentication allow-list, so it proves nothing
     * about credentials. connectionStatus reports who the connection is
     * authenticated as, and listCollections then requires real authorisation on
     * the target database.
     */
    private function probeReadinessMongodb(string $connection): array
    {
        $config = (array) config('database.connections.' . $connection);

        if ($config === []) {
            throw new RuntimeException("connection [{$connection}] is not configured");
        }

        if (! extension_loaded('mongodb')) {
            throw new RuntimeException('the mongodb PHP extension is not loaded');
        }

        $dsn = (string) ($config['dsn'] ?? '');
        $database = (string) ($config['database'] ?? '');
        [$host, $port] = $this->mongoEndpoint($dsn);
        $this->assertReachable($host, $port);

        /** @var \Jenssegers\Mongodb\Connection $mongo */
        $mongo = DB::connection($connection);
        $selected = $mongo->getMongoClient()->selectDatabase($database);

        $status = $selected->command(['connectionStatus' => 1])->toArray();
        $authInfo = $status[0]['authInfo'] ?? null;
        $users = $authInfo !== null ? ($authInfo['authenticatedUsers'] ?? []) : [];
        $authenticatedUsers = is_countable($users) ? count($users) : 0;

        // Requires the listCollections privilege on this database: an
        // unauthorised connection throws here rather than returning an
        // empty list.
        $collections = 0;

        foreach ($selected->listCollections(['limit' => 1]) as $_collection) {
            $collections++;
        }

        return $this->withAuthenticationVerdict([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            // Whether the DSN carries credentials at all. The credentials
            // themselves are never read into the payload.
            'credentials_in_dsn' => Str::contains(Str::before($dsn, '/?'), '@'),
            'authenticated_users' => $authenticatedUsers,
            'authorised_command' => 'listCollections',
            'collections_visible' => $collections,
        ], $authenticatedUsers > 0, $authenticatedUsers > 0 ? 'scram (connectionStatus reports an authenticated user)' : 'none', 'mongodb');
    }

    /**
     * Applies the authentication policy: credentials that were configured and
     * accepted pass; a component reachable with no authentication at all is a
     * warning by default and a failure when
     * `status.readiness.require_authentication` is on.
     */
    private function withAuthenticationVerdict(array $details, bool $authenticated, string $authMode, string $component): array
    {
        $details['authenticated'] = $authenticated;
        $details['auth_mode'] = $authMode;

        if ($authenticated) {
            return ['details' => $details];
        }

        if ((bool) config('status.readiness.require_authentication', false)) {
            return [
                'status' => ComponentStatus::FAILED,
                'error' => "{$component} is reachable but no authentication is configured, and status.readiness.require_authentication is enabled",
                'details' => $details,
            ];
        }

        return [
            'status' => ComponentStatus::DEGRADED,
            'error' => "{$component} is reachable but no authentication is configured (credentials absent, not rejected)",
            'details' => $details,
        ];
    }

    // -----------------------------------------------------------------
    // Self-checks
    // -----------------------------------------------------------------

    /**
     * Configuration actually resolved — the failure this catches is an instance
     * that booted with a missing or unreadable .env, or a stale config cache.
     */
    private function probeConfig(): array
    {
        $required = (array) config('status.readiness.required_config', self::REQUIRED_CONFIG_KEYS);
        $missing = [];

        foreach ($required as $key) {
            if (blank(config($key))) {
                $missing[] = $key;
            }
        }

        $environment = (string) config('app.env');

        $details = [
            'environment' => $environment,
            'debug' => (bool) config('app.debug'),
            'timezone' => config('app.timezone'),
            'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
            'routes_cached' => file_exists(base_path('bootstrap/cache/routes-v7.php')),
            'required_keys_present' => count($required) - count($missing),
            'required_keys_total' => count($required),
        ];

        if ($missing !== []) {
            return [
                'status' => ComponentStatus::FAILED,
                'error' => 'configuration did not resolve: ' . implode(', ', $missing),
                'details' => $details,
            ];
        }

        // Debug output in a production environment leaks stack traces and
        // configuration to players; worth flagging, not worth refusing traffic.
        // Read from config rather than app()->environment(), which is fixed at
        // bootstrap: this check reports on what configuration actually says.
        if ($details['debug'] && ! in_array($environment, ['local', 'testing'], true)) {
            return [
                'status' => ComponentStatus::DEGRADED,
                'error' => 'APP_DEBUG is enabled outside local/testing',
                'details' => $details,
            ];
        }

        return ['details' => $details];
    }

    /**
     * The application key, proven by use rather than by presence: a key that is
     * set but the wrong length for the cipher fails every encrypt/decrypt at
     * runtime, which looks like a session and cookie outage.
     */
    private function probeAppKey(): array
    {
        $key = (string) config('app.key');
        $cipher = (string) config('app.cipher');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set');
        }

        $raw = Str::startsWith($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($raw === false) {
            throw new RuntimeException('APP_KEY is not valid base64');
        }

        if (! Encrypter::supported($raw, $cipher)) {
            throw new RuntimeException(sprintf(
                'APP_KEY is %d bits, which is not supported for cipher [%s]',
                strlen($raw) * 8,
                $cipher
            ));
        }

        $probe = Str::random(24);

        if (Crypt::decryptString(Crypt::encryptString($probe)) !== $probe) {
            throw new RuntimeException('encrypt/decrypt round-trip did not return the original value');
        }

        return [
            'details' => [
                'cipher' => $cipher,
                'key_length_bits' => strlen($raw) * 8,
                'key_encoding' => Str::startsWith($key, 'base64:') ? 'base64' : 'raw',
                'round_trip' => 'ok',
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Short TCP pre-flight so an unreachable host fails in seconds instead of
     * hanging on the driver's own connect timeout.
     */
    private function assertReachable(?string $host, ?int $port): void
    {
        if ($host === null || $host === '' || $port === null || $port <= 0) {
            return;
        }

        if (! config('status.tcp_probe', true)) {
            return;
        }

        $timeout = (float) config('status.tcp_timeout', 2.0);
        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'TCP connect to %s:%d failed after %.1fs (%s)',
                $host,
                $port,
                $timeout,
                $errstr !== '' ? $errstr : 'errno ' . $errno
            ));
        }

        fclose($socket);
    }

    private function appMeta(): array
    {
        return [
            'name' => config('app.name'),
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'hostname' => gethostname() ?: null,
            'maintenance_mode' => app()->isDownForMaintenance(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }

    private function describe(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return class_basename($e) . ': ' . Str::limit($message !== '' ? $message : 'no message', 400);
    }
}
