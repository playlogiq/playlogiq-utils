<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Where snapshots live: an S3 bucket with Object Lock, versioning and a default
 * retention in COMPLIANCE mode.
 *
 * The bucket, not this class, is what makes a snapshot immutable. Nothing here needs to
 * request that — the bucket's default retention applies to every PUT — and nothing here
 * is able to switch it off, which is the point. What this class must not do is give the
 * bucket a reason to accept a second write to the same key, so keys are unique by
 * construction: the instant plus the digest of the document. A repeated key would create
 * a new version rather than an overwrite (old versions are retained either way), but a
 * collision would still muddy which document is current, and there is no reason to allow
 * one.
 *
 * There is deliberately no `latest.json` pointer. It would be the one object that has to
 * be rewritten on every change, which makes it the one mutable thing in an immutable
 * store — and therefore the only thing worth attacking. The newest snapshot is derived
 * instead: keys begin with a UTC timestamp, so lexicographic order is chronological
 * order.
 *
 * Reads and writes both degrade honestly. An unconfigured store reports that it is
 * unconfigured; a broken one throws. Neither ever reports success, because a snapshot
 * silently not written is a gap in a regulatory record that nobody would notice until
 * the regulator asked.
 */
class SettingsSnapshotStore
{
    const EXTENSION = '.json';

    /**
     * The global scope's directory, and its `skin-id` metadata value: a word, so no reader
     * can mistake it for a skin id.
     */
    const GLOBAL_SCOPE = 'global';

    /** @var bool */
    private $enabled;

    /** @var string */
    private $disk;

    /** @var string */
    private $prefix;

    /** @var string */
    private $roleArn;

    /**
     * @param array<string, mixed> $config the critical_components.snapshots section
     */
    public function __construct(array $config)
    {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->disk    = trim((string) ($config['disk'] ?? ''));
        $this->prefix  = trim((string) ($config['prefix'] ?? ''), '/');
        $this->roleArn = trim((string) ($config['role_arn'] ?? ''));
    }

    public static function fromConfig(): self
    {
        return new self((array) config('critical_components.snapshots'));
    }

    /**
     * Both halves are required. Enabled with no disk is a half-finished deployment, and
     * treating it as usable would mean discovering the missing half at the moment a
     * regulated setting changes.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && $this->disk !== '';
    }

    public function describe(): string
    {
        return sprintf(
            'disk=%s prefix=%s enabled=%s',
            $this->disk === '' ? '<unset>' : $this->disk,
            $this->prefix === '' ? '<none>' : $this->prefix,
            $this->enabled ? 'yes' : 'no'
        );
    }

    /**
     * The newest snapshot for a skin, or for the global scope when $skinId is null, or null
     * when it has none yet.
     *
     * @return array{key: string, sha256: string, payload: array<string, mixed>}|null
     */
    public function latest(?int $skinId): ?array
    {
        $directory = $this->directoryFor($skinId);
        $disk      = $this->filesystem();

        $keys = [];

        foreach ($disk->files($directory) as $path) {
            if (substr($path, -strlen(self::EXTENSION)) === self::EXTENSION) {
                $keys[] = $path;
            }
        }

        if ($keys === []) {
            return null;
        }

        rsort($keys, SORT_STRING);
        $key = $this->newestOf($keys, $disk);

        $bytes = $disk->get($key);

        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The newest snapshot at ' . $key . ' is empty or unreadable.');
        }

        $payload = json_decode($bytes, true);

        if (!is_array($payload)) {
            // Not tolerated as "no previous snapshot": that reading would let one corrupt
            // object silently break the chain and restart it from nothing.
            throw new RuntimeException('The newest snapshot at ' . $key . ' is not valid JSON.');
        }

        return [
            'key'     => $key,
            'sha256'  => SettingsSnapshot::digest($bytes),
            'payload' => $payload,
        ];
    }

    /**
     * The newest of an already descending-sorted key list.
     *
     * Keys carry a timestamp to the second, then a digest. Sorting alone is therefore
     * only decisive between different seconds: inside one second the digest orders the
     * documents, and a digest is content, not time. Two changes a moment apart — turning
     * a flag off and on again, a save that trips two events — could hand back the older
     * document, which then becomes the one a new snapshot chains to and the one the scope
     * check reads. Both wrong, silently.
     *
     * So ties are resolved by the chain, which does know the order: among documents
     * sharing the newest second, the newest is the one no other names as its
     * previous_snapshot. Costs nothing in the ordinary case, where the newest second
     * holds a single document and none of this runs.
     *
     * @param string[]                                       $keys sorted descending
     * @param \Illuminate\Contracts\Filesystem\Filesystem     $disk
     */
    private function newestOf(array $keys, $disk): string
    {
        $newest = $keys[0];
        $stamp  = $this->stampOf($newest);
        $tied   = [];

        foreach ($keys as $candidate) {
            if ($this->stampOf($candidate) !== $stamp) {
                // Sorted, so the run of same-second keys ends at the first mismatch.
                break;
            }

            $tied[] = $candidate;
        }

        if (count($tied) < 2) {
            return $newest;
        }

        $referenced = [];

        foreach ($tied as $candidate) {
            $payload = json_decode((string) $disk->get($candidate), true);
            $previous = is_array($payload) && isset($payload['previous_snapshot']['key'])
                ? $payload['previous_snapshot']['key']
                : null;

            if (is_string($previous)) {
                $referenced[$previous] = true;
            }
        }

        $unreferenced = [];

        foreach ($tied as $candidate) {
            if (!isset($referenced[$candidate])) {
                $unreferenced[] = $candidate;
            }
        }

        // Exactly one tail means one chain, which is the only shape this store produces.
        // Anything else — two documents written concurrently, a document restored by
        // hand — is not something to guess at: fall back to the sort and let the
        // divergence show up as a broken chain, which is visible, rather than as a
        // silently chosen winner, which is not.
        return count($unreferenced) === 1 ? $unreferenced[0] : $newest;
    }

    /** The timestamp portion of a key, i.e. everything before the digest. */
    private function stampOf(string $key): string
    {
        $base = basename($key);
        $dash = strpos($base, '-');

        return $dash === false ? $base : substr($base, 0, $dash);
    }

    /**
     * Writes a snapshot and returns the key it landed on.
     *
     * @param array<string, string> $metadata S3 object metadata; strings only
     */
    public function put(?int $skinId, string $bytes, array $metadata = []): string
    {
        $key = $this->keyFor($skinId, $bytes);

        $written = $this->filesystem()->put($key, $bytes, [
            'ContentType' => 'application/json',
            'Metadata'    => array_map('strval', $metadata),
        ]);

        if ($written === false) {
            throw new RuntimeException('The store refused the snapshot at ' . $key . '.');
        }

        return $key;
    }

    /**
     * skin_12/20260908T113848Z-a91c4f0e2b77.json
     *
     * Timestamp first so the listing sorts chronologically; digest second so two changes
     * inside the same second cannot collide, and so the key states which document it
     * holds. Compact ISO-8601 without separators: colons are legal in an S3 key and a
     * nuisance in every tool that later has to handle one.
     */
    public function keyFor(?int $skinId, string $bytes): string
    {
        return sprintf(
            '%s/%s-%s%s',
            $this->directoryFor($skinId),
            gmdate('Ymd\THis\Z'),
            substr(SettingsSnapshot::digest($bytes), 0, 12),
            self::EXTENSION
        );
    }

    /**
     * One directory per skin. Flat inside it: critical settings change a handful of
     * times a year, so a listing stays small for the length of any retention period
     * worth having. If that ever stops being true, shard by year here — the newest-key
     * derivation above is the only thing that has to agree with it.
     *
     * The global scope has a directory of its own, so its chain never mixes with a skin's.
     */
    public function directoryFor(?int $skinId): string
    {
        $directory = self::scopeName($skinId);

        return $this->prefix === '' ? $directory : $this->prefix . '/' . $directory;
    }

    /** skin_12, or `global`: the scope as the store and the logs name it. */
    public static function scopeName(?int $skinId): string
    {
        return $skinId === null ? self::GLOBAL_SCOPE : 'skin_' . $skinId;
    }

    /** The `skin-id` object metadata value for a scope. */
    public static function metadataSkinId(?int $skinId): string
    {
        return $skinId === null ? self::GLOBAL_SCOPE : (string) $skinId;
    }

    /**
     * The configured disk, with the two ways this is normally misconfigured turned into
     * sentences instead of a stack trace.
     */
    private function filesystem(): Filesystem
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'The settings snapshot store is not configured (' . $this->describe() . '). Set '
                . 'INTEGRITY_SETTINGS_SNAPSHOTS=true and INTEGRITY_SNAPSHOTS_DISK.'
            );
        }

        $this->installAssumedRoleCredentials();

        try {
            return Storage::disk($this->disk);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Disk [' . $this->disk . '] could not be resolved: ' . $e->getMessage()
                . '. The s3 driver needs league/flysystem-aws-s3-v3, which is not installed yet.'
            );
        }
    }

    /**
     * Hand the disk's S3 client credentials for the role that owns the bucket.
     *
     * Laravel's s3 driver only overwrites `credentials` when the disk declares a key AND a
     * secret; this one declares neither, by design, so a provider placed here survives into
     * `new S3Client()` untouched. That is why no custom driver is needed.
     *
     * Set through the runtime config rather than written into config/filesystems.php,
     * because `php artisan config:cache` serialises configuration with var_export() and
     * would refuse the callable outright ("Your configuration files are not serializable").
     *
     * Done once per process: the disk is memoised by the manager, and rebuilding it on
     * every call would discard the memoised credentials with it and mint a fresh STS
     * session per object written.
     */
    private function installAssumedRoleCredentials(): void
    {
        $key = 'filesystems.disks.' . $this->disk . '.credentials';

        if ($this->roleArn === '' || config($key) !== null) {
            return;
        }

        // Only the s3 driver has any use for credentials. A developer running against the
        // local disk keeps INTEGRITY_SNAPSHOTS_ROLE_ARN in their .env without thinking
        // about it, and building an STS client for a directory on disk would be a
        // confusing thing to find in a stack trace.
        if (config('filesystems.disks.' . $this->disk . '.driver') !== 's3') {
            return;
        }

        $provider = (new SnapshotCredentials(
            $this->roleArn,
            (string) config('filesystems.disks.' . $this->disk . '.region', '')
        ))->provider();

        if ($provider === null) {
            return;
        }

        config([$key => $provider]);

        // Anything that resolved this disk earlier in the process holds a client built
        // without these credentials. Dropping it is cheap and avoids a confusing
        // first-call-wins ordering dependency.
        Storage::forgetDisk($this->disk);
    }
}
