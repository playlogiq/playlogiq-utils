<?php

namespace PlaylogiqUtils\ServiceConfiguration;

use Illuminate\Support\Str;

/**
 * How a Service Configuration Profile shows its secrets: secrets never leave the server in
 * cleartext, and a mask sent back by the admin UI is never stored as the new value (PQPL-7002).
 * Moved from betmaker's controller and hide_sensitive_data() helper (PQPL-7443).
 */
class SecretMask
{
    /**
     * Two chars, one '*' per byte beyond four, two chars. Identical to betmaker's
     * hide_sensitive_data(), short values included ('abc' => 'abbc'), because masks already
     * held by the UI are compared against it.
     *
     * @param mixed $value
     */
    public static function mask($value): string
    {
        $string = (string) $value;

        return Str::substr($string, 0, 2) . str_repeat('*', max(0, strlen($string) - 4)) . Str::substr($string, -2, 2);
    }

    public static function maskIfNotEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? $value : static::mask($value);
    }

    /**
     * Shape of any mask(): 2 chars + only '*' + 2 chars, len >= 5.
     * Independent of whose mask it is, so a stale mask is caught too (PQPL-7002).
     */
    public static function looksMasked(string $value): bool
    {
        return strlen($value) >= 5 && preg_match('/^.{2}\*+.{2}$/', $value) === 1;
    }

    /**
     * Schema keys of type `password`/`x-secret`, keyed by key name for O(1) lookup.
     */
    public static function secretKeysOf(array $schema): array
    {
        return array_flip(array_map(
            function ($f) {
                return $f['key'];
            },
            array_filter($schema, function ($f) {
                return in_array($f['type'] ?? '', ['password', 'x-secret'], true);
            })
        ));
    }
}
