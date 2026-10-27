<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration;

use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\ServiceConfiguration\SecretMask;

/**
 * mask() must reproduce betmaker's hide_sensitive_data() byte for byte, short values
 * included, because looksMasked() and the "equals the current mask" check compare
 * against masks the admin UI already holds (PQPL-7443).
 */
class SecretMaskTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public function maskCases(): array
    {
        return [
            'empty'      => ['', ''],
            'one char'   => ['a', 'aa'],
            'two chars'  => ['ab', 'abab'],
            'three'      => ['abc', 'abbc'],
            'four'       => ['abcd', 'abcd'],
            'five'       => ['abcde', 'ab*de'],
            'long'       => ['sk_live_123456', 'sk**********56'],
            // Str::substr is multibyte, the star count is strlen(): bytes.
            'multibyte'  => ['héllo', 'hé**lo'],
        ];
    }

    /** @dataProvider maskCases */
    public function test_mask_matches_hide_sensitive_data(string $value, string $expected): void
    {
        $this->assertSame($expected, SecretMask::mask($value));
    }

    public function test_mask_casts_non_strings(): void
    {
        $this->assertSame('12**56', SecretMask::mask(123456));
        $this->assertSame('', SecretMask::mask(null));
    }

    public function test_mask_if_not_empty_passes_null_and_empty_through(): void
    {
        $this->assertNull(SecretMask::maskIfNotEmpty(null));
        $this->assertSame('', SecretMask::maskIfNotEmpty(''));
        $this->assertSame('ab*de', SecretMask::maskIfNotEmpty('abcde'));
    }

    public function test_looks_masked(): void
    {
        $this->assertTrue(SecretMask::looksMasked('ab*de'));
        $this->assertTrue(SecretMask::looksMasked('sk**********56'));
        $this->assertFalse(SecretMask::looksMasked('abcd'), 'a 4-char mask has no star');
        $this->assertFalse(SecretMask::looksMasked('ab**'), 'needs two trailing chars');
        $this->assertTrue(SecretMask::looksMasked('a***de'), "'.' also matches '*': same as betmaker's original, kept as-is");
        $this->assertFalse(SecretMask::looksMasked('ab*c*de'));
        $this->assertFalse(SecretMask::looksMasked('plain-secret'));
    }

    public function test_secret_keys_of_keeps_password_and_x_secret_only(): void
    {
        $schema = [
            ['key' => 'url', 'type' => 'string'],
            ['key' => 'api_key', 'type' => 'password'],
            ['key' => 'token', 'type' => 'x-secret'],
            ['key' => 'no_type'],
        ];

        $this->assertSame(['api_key', 'token'], array_keys(SecretMask::secretKeysOf($schema)));
        $this->assertSame([], SecretMask::secretKeysOf([]));
    }
}
