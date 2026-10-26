<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Support;

/**
 * Identity of the integrity manifest the running artifact ships with, for display.
 *
 * The counterpart of BuildInfo: where that reads build-info.txt, this reads
 * integrity-manifest.json in the consuming application's root and reports the
 * versions it declares:
 *
 *   release_version   — the control-program ("app core") version, as quoted to
 *                       the regulator for a submitted build;
 *   inventory_version — the critical-components scope the manifest was built
 *                       against, i.e. *what was checked*.
 *
 * It never verifies anything — the application's own verifier does that — so a
 * missing or malformed manifest is simply 'none', which is the honest answer for
 * a checkout or for an application that has no manifest yet. Nothing here
 * touches the container, so it is safe to call from config files (see
 * config/app-build.php) and `config:cache` bakes the values in.
 *
 * Resolved once per process; useBasePath() and flush() drop the memoised values.
 */
final class ManifestInfo
{
    public const NONE = 'none';

    /** Name of the manifest in the application root. */
    private const FILE = 'integrity-manifest.json';

    /** @var array<string,string>|null */
    private static $cache = null;

    /** @var string|null Explicit application root; see useBasePath(). */
    private static $basePath = null;

    /**
     * Control-program version the manifest declares (`release_version`), or 'none'.
     */
    public static function release(): string
    {
        return self::resolve()['release'];
    }

    /**
     * Critical-components scope version (`inventory_version`), or 'none'.
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
     * Human-facing manifest string, e.g. "1.22.0 (2026-10-16)", or 'none'.
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
     * True when a readable manifest declaring an inventory version is present.
     */
    public static function isKnown(): bool
    {
        return self::inventory() !== self::NONE;
    }

    /**
     * @return array<string,string>
     */
    public static function all(): array
    {
        return self::resolve() + ['version' => self::version()];
    }

    /**
     * Point the lookup at an explicit application root (tests, or an
     * application whose root is not the Composer root). Null restores
     * autodetection. Either way the memoised values are dropped.
     */
    public static function useBasePath(?string $path): void
    {
        self::$basePath = $path === null ? null : rtrim($path, '/\\');
        self::$cache = null;
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

        $info = ['release' => self::NONE, 'inventory' => self::NONE, 'date' => self::NONE];
        $path = self::basePath() . DIRECTORY_SEPARATOR . self::FILE;

        if (! is_file($path) || ! is_readable($path)) {
            return self::$cache = $info;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        if (! is_array($decoded)) {
            return self::$cache = $info;
        }

        foreach (['release' => 'release_version', 'inventory' => 'inventory_version'] as $key => $field) {
            $value = $decoded[$field] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $info[$key] = trim((string) $value);
            }
        }

        $mtime = @filemtime($path);

        if ($mtime !== false) {
            $info['date'] = date('Y-m-d', $mtime);
        }

        return self::$cache = $info;
    }

    private static function basePath(): string
    {
        if (self::$basePath === null) {
            self::$basePath = ApplicationRoot::detect();
        }

        return self::$basePath;
    }
}
