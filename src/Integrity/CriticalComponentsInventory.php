<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Expands config/critical_components.php into a concrete, deterministic file
 * list.
 *
 * Shared by integrity:baseline (which hashes the list) and integrity:verify
 * (which re-hashes it and looks for unexpected extras inside the covered
 * directories). Both must agree exactly, so expansion lives here and nowhere
 * else.
 *
 * Determinism rules, because the manifest signature depends on them:
 *   - every path is relative to the application base path, forward slashes;
 *   - the returned list is byte-order sorted and duplicate-free;
 *   - symlinks are never followed (a symlink swap must not silently redirect a
 *     hash to another file).
 */
class CriticalComponentsInventory
{
    /** Path of the inventory file itself, always part of the covered set. */
    const INVENTORY_FILE = 'config/critical_components.php';

    /** The two critical-settings registries under `settings`: per skin, and global. */
    const SKIN_REGISTRY   = 'registry';
    const GLOBAL_REGISTRY = 'global_registry';

    /** @var array */
    private $config;

    /** @var string */
    private $basePath;

    public function __construct(array $config, string $basePath)
    {
        $this->config   = $config;
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
    }

    /**
     * The control-program version reported as the manifest's release_version.
     *
     * Sourced from the inventory rather than a command-line option so a manifest
     * cannot be re-stamped with a different version without editing (and therefore
     * re-hashing) the inventory file.
     */
    public function version(): string
    {
        $version = $this->config['version'] ?? null;

        if (!is_string($version) || trim($version) === '') {
            throw new InvalidArgumentException('critical_components: version must be a non-empty string.');
        }

        return trim($version);
    }

    public function inventoryVersion(): string
    {
        $version = $this->config['inventory_version'] ?? null;

        if (!is_string($version) || $version === '') {
            throw new InvalidArgumentException('critical_components: inventory_version must be a non-empty string.');
        }

        return $version;
    }

    /**
     * The covered file list: relative paths, sorted, unique.
     *
     * @return string[]
     */
    public function expand(): array
    {
        $paths = [];

        foreach ($this->components() as $index => $component) {
            $label = $this->componentLabel($component, $index);

            foreach ($this->arrayValue($component, 'files', $label) as $file) {
                $paths[] = $this->requireFile($this->normalize((string) $file, $label), $label);
            }

            foreach ($this->arrayValue($component, 'directories', $label) as $rule) {
                foreach ($this->expandDirectory($rule, $label) as $found) {
                    $paths[] = $found;
                }
            }
        }

        // The inventory defines the scope, so it must be inside the scope.
        $paths[] = $this->requireFile(self::INVENTORY_FILE, 'inventory');

        $paths = array_values(array_unique($paths));
        $paths = $this->applyExclusions($paths);

        sort($paths, SORT_STRING);

        if ($paths === []) {
            throw new InvalidArgumentException('critical_components: expanded to an empty file list.');
        }

        return $paths;
    }

    /**
     * Directory rules, normalised, for unexpected-extra detection at verify
     * time. Each entry: ['path' => string, 'extensions' => string[],
     * 'recursive' => bool, 'exclude' => string[]].
     *
     * @return array[]
     */
    public function coveredDirectories(): array
    {
        $rules = [];

        foreach ($this->components() as $index => $component) {
            $label = $this->componentLabel($component, $index);

            foreach ($this->arrayValue($component, 'directories', $label) as $rule) {
                $rules[] = $this->normalizeDirectoryRule($rule, $label);
            }
        }

        return $rules;
    }

    /**
     * Registry keys, flattened and sorted.
     *
     * @return string[]
     */
    public function settingsKeys(): array
    {
        return $this->registryKeys(self::SKIN_REGISTRY);
    }

    /**
     * Global registry keys, flattened and sorted.
     *
     * @return string[]
     */
    public function globalSettingsKeys(): array
    {
        return $this->registryKeys(self::GLOBAL_REGISTRY);
    }

    /**
     * The registry for one settings scope: a skin's, or the global one when $skinId is null.
     *
     * @return string[]
     */
    public function settingsKeysFor(?int $skinId): array
    {
        return $skinId === null ? $this->globalSettingsKeys() : $this->settingsKeys();
    }

    /**
     * @return string[]
     */
    private function registryKeys(string $name): array
    {
        $registry = $this->config['settings'][$name] ?? [];

        if (!is_array($registry)) {
            throw new InvalidArgumentException('critical_components: settings.' . $name . ' must be an array.');
        }

        $keys = [];

        foreach ($registry as $group => $groupKeys) {
            if (!is_array($groupKeys)) {
                throw new InvalidArgumentException(sprintf(
                    'critical_components: settings.%s.%s must be an array of keys.',
                    $name,
                    (string) $group
                ));
            }

            foreach ($groupKeys as $key) {
                if (!is_string($key) || $key === '') {
                    throw new InvalidArgumentException(sprintf(
                        'critical_components: settings.%s.%s contains a non-string key.',
                        $name,
                        (string) $group
                    ));
                }
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function absolute(string $relative): string
    {
        return $this->basePath . '/' . $relative;
    }

    /**
     * @return array[]
     */
    private function components(): array
    {
        $components = $this->config['components'] ?? null;

        if (!is_array($components) || $components === []) {
            throw new InvalidArgumentException('critical_components: components must be a non-empty array.');
        }

        return $components;
    }

    private function componentLabel(array $component, $index): string
    {
        if (isset($component['section'])) {
            return 'section ' . (string) $component['section'];
        }

        return 'component #' . (string) $index;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayValue(array $component, string $key, string $label): array
    {
        $value = $component[$key] ?? [];

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'critical_components: %s: %s must be an array.',
                $label,
                $key
            ));
        }

        return $value;
    }

    /**
     * @return string[]
     */
    private function expandDirectory($rule, string $label): array
    {
        $rule    = $this->normalizeDirectoryRule($rule, $label);
        $absolute = $this->absolute($rule['path']);

        if (!is_dir($absolute)) {
            throw new InvalidArgumentException(sprintf(
                'critical_components: %s: declared directory does not exist: %s',
                $label,
                $rule['path']
            ));
        }

        $found = [];

        foreach ($this->iterate($absolute, $rule['recursive']) as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = $this->relative($file->getPathname());

            if ($rule['extensions'] !== []
                && !in_array(strtolower($file->getExtension()), $rule['extensions'], true)) {
                continue;
            }

            if ($this->matchesAny($relative, $rule['exclude'])) {
                continue;
            }

            $found[] = $relative;
        }

        if ($found === []) {
            throw new InvalidArgumentException(sprintf(
                'critical_components: %s: directory rule matched no files: %s',
                $label,
                $rule['path']
            ));
        }

        return $found;
    }

    /**
     * @return array{path: string, extensions: string[], recursive: bool, exclude: string[]}
     */
    private function normalizeDirectoryRule($rule, string $label): array
    {
        if (is_string($rule)) {
            $rule = ['path' => $rule];
        }

        if (!is_array($rule) || !isset($rule['path'])) {
            throw new InvalidArgumentException(sprintf(
                'critical_components: %s: each directory needs a path.',
                $label
            ));
        }

        $extensions = [];
        foreach (($rule['extensions'] ?? []) as $extension) {
            $extensions[] = strtolower(ltrim((string) $extension, '.'));
        }

        $exclude = [];
        foreach (($rule['exclude'] ?? []) as $pattern) {
            $exclude[] = $this->normalize((string) $pattern, $label);
        }

        return [
            'path'       => $this->normalize((string) $rule['path'], $label),
            'extensions' => $extensions,
            'recursive'  => (bool) ($rule['recursive'] ?? true),
            'exclude'    => $exclude,
        ];
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function iterate(string $absolute, bool $recursive): iterable
    {
        if (!$recursive) {
            return new \FilesystemIterator($absolute, \FilesystemIterator::SKIP_DOTS);
        }

        // Deliberately not FOLLOW_SYMLINKS: see the class docblock.
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    }

    /**
     * Removes excluded paths, and fails on an exclusion that matches nothing —
     * a stale exclusion is a scope claim nobody is checking any more.
     *
     * @param  string[] $paths
     * @return string[]
     */
    private function applyExclusions(array $paths): array
    {
        $exclusions = $this->config['exclusions'] ?? [];

        if (!is_array($exclusions)) {
            throw new InvalidArgumentException('critical_components: exclusions must be a path => rationale map.');
        }

        foreach ($exclusions as $path => $rationale) {
            if (!is_string($path) || !is_string($rationale) || trim($rationale) === '') {
                throw new InvalidArgumentException(sprintf(
                    'critical_components: exclusion "%s" needs a non-empty rationale.',
                    (string) $path
                ));
            }

            $pattern = $this->normalize($path, 'exclusions');

            if ($pattern === self::INVENTORY_FILE) {
                throw new InvalidArgumentException('critical_components: the inventory file cannot exclude itself.');
            }

            $remaining = [];
            $matched   = false;

            foreach ($paths as $candidate) {
                if ($this->matches($candidate, $pattern)) {
                    $matched = true;
                    continue;
                }
                $remaining[] = $candidate;
            }

            if (!$matched) {
                throw new InvalidArgumentException(sprintf(
                    'critical_components: exclusion matches nothing, remove it: %s',
                    $pattern
                ));
            }

            $paths = $remaining;
        }

        return $paths;
    }

    /**
     * @param string[] $patterns
     */
    private function matchesAny(string $relative, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($relative, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact path, directory prefix, or glob.
     */
    private function matches(string $relative, string $pattern): bool
    {
        if ($relative === $pattern) {
            return true;
        }

        if (strpos($relative, rtrim($pattern, '/') . '/') === 0) {
            return true;
        }

        return fnmatch($pattern, $relative);
    }

    private function requireFile(string $relative, string $label): string
    {
        $absolute = $this->absolute($relative);

        if (!is_file($absolute)) {
            throw new InvalidArgumentException(sprintf(
                'critical_components: %s: declared file does not exist: %s',
                $label,
                $relative
            ));
        }

        if (is_link($absolute)) {
            throw new InvalidArgumentException(sprintf(
                'critical_components: %s: declared file is a symlink: %s',
                $label,
                $relative
            ));
        }

        return $relative;
    }

    private function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);

        if (strpos($absolute, $this->basePath . '/') === 0) {
            return substr($absolute, strlen($this->basePath) + 1);
        }

        return ltrim($absolute, '/');
    }

    private function normalize(string $path, string $label): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^\./#', '', $path);
        $path = ltrim((string) $path, '/');
        $path = rtrim($path, '/');

        if ($path === '') {
            throw new InvalidArgumentException(sprintf(
                'critical_components: %s: empty path.',
                $label
            ));
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException(sprintf(
                    'critical_components: %s: path escapes the base path: %s',
                    $label,
                    $path
                ));
            }
        }

        return $path;
    }
}
