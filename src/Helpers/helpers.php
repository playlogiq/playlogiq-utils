<?php

/**
 * Global helper functions.
 *
 * Loaded on every request via composer's "files" autoload.
 * Each function is guarded with function_exists() to avoid
 * "cannot redeclare" fatals if a consuming app defines its own.
 */

use Illuminate\Support\Facades\Cache;

if (! function_exists('cache_put_compressed')) {
    function cache_put_compressed(string $key, $value, $seconds = null): void
    {
        $serialized = serialize($value);
        $compressed = gzencode($serialized, 6); // 0–9, higher = more CPU, smaller size

        Cache::put($key, $compressed, $seconds);
    }
}

if (! function_exists('cache_get_compressed')) {
    function cache_get_compressed(string $key, $default = null)
    {
        $compressed = Cache::get($key);

        if ($compressed === null) {
            return $default;
        }

        $serialized = @gzdecode($compressed);
        if ($serialized === false) {
            // data not compressed / corrupted
            return $default;
        }

        return unserialize($serialized);
    }
}

if (! function_exists('redact_secret_keys')) {
    /** @return string[] lower-case key names whose values are never logged (SecretRedactor::KEYS). */
    function redact_secret_keys(): array
    {
        return \PlaylogiqUtils\Support\SecretRedactor::KEYS;
    }
}

if (! function_exists('redact_secrets')) {
    /**
     * Replaces the value of every sensitive key with '[REDACTED]', recursively (SecretRedactor).
     *
     * @param mixed $data
     * @return mixed
     */
    function redact_secrets($data, array $extraKeys = [])
    {
        return \PlaylogiqUtils\Support\SecretRedactor::redact($data, $extraKeys);
    }
}

if (! function_exists('redact_url_query')) {
    /** Replaces the value of every sensitive query parameter with '[REDACTED]' (SecretRedactor). */
    function redact_url_query($url, array $extraKeys = []): string
    {
        return \PlaylogiqUtils\Support\SecretRedactor::redactUrlQuery($url, $extraKeys);
    }
}
