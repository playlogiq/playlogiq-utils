<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Support;

use Composer\Autoload\ClassLoader;
use ReflectionClass;

/**
 * Build identity (git commit) of the running code.
 *
 * The single source is build-info.txt in the consuming application's root,
 * whose $Format:...$ placeholders git expands while building the artifact — see
 * the export-subst attribute in that application's .gitattributes. Only
 * `git archive` expands them: in a plain checkout the literals survive
 * untouched, are ignored here, and everything reports 'unknown'. That is the
 * correct answer for a working copy, which has no build to identify.
 *
 * The checkout is never inspected — no .git reads, no git subprocess. Besides
 * being the wrong place to ask, a subprocess does not survive php-fpm: the web
 * user does not own the checkout and git refuses it with "dubious ownership".
 *
 * Everything is resolved once per process and is safe to call from config,
 * where `config:cache` will simply bake the value into the cached file. For the
 * same reason the application root is not taken from base_path(): this may run
 * while config/app.php is being loaded, and base_path() is a defined function
 * that still fatals when the container instance is not bound yet. It is derived
 * from Composer's autoloader instead, which always lives in the host
 * application's vendor/ — correct both for a vendored install and for a path
 * repository symlinked into it.
 */
final class BuildInfo
{
    public const UNKNOWN = 'unknown';

    /** Name of the file git expands at archive time. */
    private const FILE = 'build-info.txt';

    /** @var array<string,string>|null */
    private static $cache = null;

    /** @var string|null Explicit application root; see useBasePath(). */
    private static $basePath = null;

    /**
     * Full commit SHA, or 'unknown'.
     */
    public static function commit(): string
    {
        return self::resolve()['commit'];
    }

    /**
     * Abbreviated commit SHA, or 'unknown'.
     */
    public static function short(): string
    {
        return self::resolve()['short'];
    }

    /**
     * Commit date, ISO-8601, or 'unknown'.
     */
    public static function date(): string
    {
        return self::resolve()['date'];
    }

    /**
     * Ref the commit was built from (branch/tag), or 'unknown'.
     *
     * A pipeline checkout is detached, and `git archive` leaves %D empty for a
     * detached HEAD, so this is usually 'unknown' even in a real artifact.
     */
    public static function ref(): string
    {
        return self::resolve()['ref'];
    }

    /**
     * Human-facing version string, e.g. "1a008ece8b (2026-09-28)".
     */
    public static function version(): string
    {
        $short = self::short();

        if ($short === self::UNKNOWN) {
            return self::UNKNOWN;
        }

        $date = self::date();

        return $date === self::UNKNOWN
            ? $short
            : $short . ' (' . substr($date, 0, 10) . ')';
    }

    /**
     * True when we actually know what we are running.
     */
    public static function isKnown(): bool
    {
        return self::commit() !== self::UNKNOWN;
    }

    /**
     * @return array<string,string>
     */
    public static function all(): array
    {
        return self::resolve();
    }

    /**
     * Point the lookup at an explicit application root (tests, or an
     * application whose root is not the Composer root).
     *
     * Passing null restores autodetection. Either way the memoised values are
     * dropped so the next call reads again.
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

        $info = self::fromFile();

        if ($info['short'] === self::UNKNOWN && $info['commit'] !== self::UNKNOWN) {
            $info['short'] = substr($info['commit'], 0, 10);
        }

        return self::$cache = $info;
    }

    /**
     * @return array<string,string>
     */
    private static function blank(): array
    {
        return [
            'commit' => self::UNKNOWN,
            'short'  => self::UNKNOWN,
            'date'   => self::UNKNOWN,
            'ref'    => self::UNKNOWN,
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function fromFile(): array
    {
        $info = self::blank();
        $path = self::basePath(self::FILE);

        if (! is_file($path) || ! is_readable($path)) {
            return $info;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return $info;
        }

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $parts = explode('=', trim($line), 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;
            $key = strtolower(trim($key));
            $value = trim($value);

            // Plain checkout: git never expanded the placeholder.
            if ($value === '' || strpos($value, '$Format:') === 0) {
                continue;
            }

            if (array_key_exists($key, $info)) {
                $info[$key] = $value;
            }
        }

        // A commit that is not a SHA means the file was tampered with or
        // half-expanded; trust none of it rather than report a plausible lie.
        if ($info['commit'] !== self::UNKNOWN && ! self::isSha($info['commit'])) {
            return self::blank();
        }

        return $info;
    }

    private static function isSha(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{7,40}$/i', $value);
    }

    /**
     * Root of the consuming application.
     *
     * Composer's autoloader lives at <root>/vendor/composer/ClassLoader.php, so
     * its own file locates the root whether this package is installed into
     * vendor/ or symlinked there from a path repository. When Composer is not
     * in play at all (a bare include of this file), fall back to the package
     * root, which is the right answer when this package *is* the checkout.
     */
    private static function basePath(string $path = ''): string
    {
        if (self::$basePath === null) {
            self::$basePath = self::detectBasePath();
        }

        return $path === ''
            ? self::$basePath
            : self::$basePath . DIRECTORY_SEPARATOR . $path;
    }

    private static function detectBasePath(): string
    {
        if (class_exists(ClassLoader::class, false)) {
            $file = (new ReflectionClass(ClassLoader::class))->getFileName();

            if (is_string($file) && $file !== '') {
                // …/vendor/composer/ClassLoader.php → …/vendor → the root.
                return dirname($file, 3);
            }
        }

        return dirname(__DIR__, 2);
    }
}
