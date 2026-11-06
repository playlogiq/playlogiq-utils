<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration;

use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\ServiceConfiguration\ConfigKeys;
use PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures\FlatConfigKeysImplementation;
use PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures\NoSchemaImplementation;
use PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures\OverlappingConfigKeysImplementation;
use PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures\StructuredConfigKeysImplementation;

class ConfigKeysTest extends TestCase
{
    public function test_structured_without_ignored(): void
    {
        $this->assertSame(
            ['secrets' => ['client_password'], 'non_secrets' => ['base_url', 'api_key'], 'ignored' => []],
            ConfigKeys::of(StructuredConfigKeysImplementation::class)
        );
    }

    public function test_duplicates_overlap_and_ignored_are_normalised(): void
    {
        $this->assertSame(
            ['secrets' => ['token', 'pin'], 'non_secrets' => ['base_url', 'lang'], 'ignored' => ['legacy_flag']],
            ConfigKeys::of(OverlappingConfigKeysImplementation::class)
        );
    }

    public function test_anything_else_is_null(): void
    {
        $this->assertNull(ConfigKeys::of(FlatConfigKeysImplementation::class), 'flat list');
        $this->assertNull(ConfigKeys::of(NoSchemaImplementation::class), 'no property');
        $this->assertNull(ConfigKeys::of('PlaylogiqUtils\\Tests\\Unit\\ServiceConfiguration\\Fixtures\\MissingImplementation'), 'missing class');
    }
}
