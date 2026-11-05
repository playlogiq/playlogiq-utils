<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

/**
 * Identity of the integrity manifest the running artifact ships with, for display.
 *
 * The counterpart of PlaylogiqUtils\Support\BuildInfo: where that reads
 * build-info.txt, this reads integrity-manifest.json in the app root and reports
 * the inventory version it declares and the date the file was last written. It
 * never verifies anything — ManifestVerifier does that — so a missing or
 * malformed manifest is simply 'none', which is the honest answer for a checkout.
 *
 * Resolved once per process; flush() drops the memoised values for tests.
 */
final class ManifestInfo
{
    public const NONE = 'none';

    /** Name of the manifest in the app root. */
    private const FILE = 'integrity-manifest.json';

    /** @var array<string,string>|null */
    private static $cache = null;

    /**
     * Inventory version the manifest declares, or 'none'.
     */
    public static function inventory(): string
    {
        return self::resolve()['inventory'];
    }

    /**
     * Last-modified date of the manifest file (Y-m-d), or 'none'.
     */
    public static function date(): string
    {
        return self::resolve()['date'];
    }

    /**
     * Human-facing version string, e.g. "1.21.0 (2026-10-16)".
     */
    public static function version(): string
    {
        $inventory = self::inventory();

        if ($inventory === self::NONE) {
            return self::NONE;
        }

        $date = self::date();

        return $date === self::NONE ? $inventory : $inventory . ' (' . $date . ')';
    }

    /**
     * Drop the memoised values (tests).
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * @return array<string,string>
     */
    private static function resolve(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $info = ['inventory' => self::NONE, 'date' => self::NONE];
        $path = base_path(self::FILE);

        if (!is_file($path) || !is_readable($path)) {
            return self::$cache = $info;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        $inventory = is_array($decoded) ? trim((string) ($decoded['inventory_version'] ?? '')) : '';

        if ($inventory !== '') {
            $info['inventory'] = $inventory;
        }

        $mtime = @filemtime($path);

        if ($mtime !== false) {
            $info['date'] = date('Y-m-d', $mtime);
        }

        return self::$cache = $info;
    }
}
