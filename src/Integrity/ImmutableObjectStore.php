<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * GLI-33 write-once store (PQPL-7134; ported from Backoffice PQPL-7089). The shared store for
 * GLI-33 integrity records: the significant-event anchor receipts now, and PQPL-5432's
 * critical-settings snapshots are the intended second user.
 *
 * Credentials come from the AWS SDK default provider chain, that is, the instance profile
 * role, which writes to the bucket directly. There is no role assumption.
 *
 * Where integrity records live: an S3 bucket with Object Lock, versioning and a default
 * retention in COMPLIANCE mode. The bucket, not this class, is what makes an object
 * immutable. Nothing here needs to request that (the bucket's default retention applies
 * to every PUT) and nothing here is able to switch it off, which is the point.
 *
 * This class only appends. Key uniqueness is the caller's job: a repeated key would
 * create a new version rather than an overwrite (old versions are retained either way),
 * but it would still muddy which document is current, so callers build keys that cannot
 * collide (an instant plus the digest of the document, for example).
 *
 * There is deliberately no `latest.json` pointer. It would be the one object rewritten
 * on every write, which makes it the one mutable thing in an immutable store. The newest
 * object is derived instead: callers start keys with a UTC timestamp, so lexicographic
 * order is chronological order.
 *
 * Reads and writes both degrade honestly. An unconfigured store reports that it is
 * unconfigured; a broken one throws. Neither ever reports success, because a record
 * silently not written is a gap nobody notices until the regulator asks.
 */
class ImmutableObjectStore
{
    /** @var bool */
    private $enabled;

    /** @var string */
    private $disk;

    /** @var string */
    private $prefix;

    /**
     * @param array<string, mixed> $config the integrity.store section
     */
    public function __construct(array $config)
    {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->disk    = trim((string) ($config['disk'] ?? ''));
        $this->prefix  = trim((string) ($config['prefix'] ?? ''), '/');
    }

    public static function fromConfig(): self
    {
        return new self((array) config('integrity.store'));
    }

    /**
     * Both halves are required. Enabled with no disk is a half-finished deployment, and
     * treating it as usable would mean discovering the missing half at write time.
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
     * Writes an object and returns the full (prefixed) key it landed on.
     *
     * @param string                $key      relative to the configured prefix
     * @param array<string, string> $metadata S3 object metadata; strings only
     */
    public function put(string $key, string $bytes, array $metadata = []): string
    {
        $fullKey = $this->fullKey($key);

        // flysystem-aws-s3-v3 1.x sends `ACL: private` unless told otherwise; a bucket with ACLs
        // disabled rejects any ACL but bucket-owner-full-control (AccessControlListNotSupported),
        // which every Object Ownership setting accepts. The local driver ignores it.
        $written = $this->filesystem()->put($fullKey, $bytes, [
            'ContentType' => 'application/json',
            'Metadata'    => array_map('strval', $metadata),
            'ACL'         => 'bucket-owner-full-control',
        ]);

        if ($written === false) {
            throw new RuntimeException('The integrity store refused the object at ' . $fullKey . '.');
        }

        return $fullKey;
    }

    /**
     * @param string $fullKey a key as returned by put() or keys()
     */
    public function get(string $fullKey): string
    {
        $disk = $this->filesystem();

        try {
            $bytes = $disk->get($fullKey);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'The integrity store object at ' . $fullKey . ' could not be read: ' . $e->getMessage()
            );
        }

        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The integrity store object at ' . $fullKey . ' is empty or unreadable.');
        }

        return $bytes;
    }

    /**
     * Full keys of the files directly under prefix/directory, ascending.
     *
     * @return string[]
     */
    public function keys(string $directory): array
    {
        $keys = [];

        foreach ($this->filesystem()->files($this->fullKey($directory)) as $path) {
            $keys[] = (string) $path;
        }

        sort($keys, SORT_STRING);

        return $keys;
    }

    /** The lexicographically last key under prefix/directory, or null when it is empty. */
    public function newestKey(string $directory): ?string
    {
        $keys = $this->keys($directory);

        return $keys === [] ? null : $keys[count($keys) - 1];
    }

    private function fullKey(string $key): string
    {
        $key = trim($key, '/');

        return $this->prefix === '' ? $key : $this->prefix . '/' . $key;
    }

    /**
     * The configured disk, with the two ways this is normally misconfigured turned into
     * sentences instead of a stack trace.
     */
    private function filesystem(): Filesystem
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'The integrity store is not configured (' . $this->describe() . '). Set '
                . 'INTEGRITY_STORE_ENABLED=true and INTEGRITY_STORE_DISK.'
            );
        }

        try {
            return Storage::disk($this->disk);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Disk [' . $this->disk . '] could not be resolved: ' . $e->getMessage()
                . '. The s3 driver needs league/flysystem-aws-s3-v3; check it is installed.'
            );
        }
    }
}
