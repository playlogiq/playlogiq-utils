<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration;

use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\ServiceConfiguration\ProfileKeyWriter;

/**
 * The decision half of the writer: what gets written and which stored keys survive.
 * The DB half only applies this plan, so no database is needed here (PQPL-7443).
 */
class ProfileKeyWriterTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private $schema = [
        ['key' => 'url', 'type' => 'string'],
        ['key' => 'enabled', 'type' => 'boolean'],
        ['key' => 'api_key', 'type' => 'password'],
        ['key' => 'token', 'type' => 'x-secret'],
    ];

    public function test_plain_keys_are_written_and_unknown_keys_ignored(): void
    {
        $plan = ProfileKeyWriter::plan($this->schema, ['url' => 'https://x', 'evil' => '1', 'enabled' => '0'], []);

        $this->assertSame(['url' => 'https://x', 'enabled' => '0'], $plan['upsert']);
        $this->assertSame(['url', 'enabled', 'api_key', 'token'], $plan['keep']);
    }

    public function test_a_new_secret_is_written(): void
    {
        $plan = ProfileKeyWriter::plan($this->schema, ['api_key' => 'sk_live_new'], ['api_key' => 'sk_live_old']);

        $this->assertSame(['api_key' => 'sk_live_new'], $plan['upsert']);
    }

    public function test_empty_secret_keeps_the_stored_value(): void
    {
        $plan = ProfileKeyWriter::plan($this->schema, ['api_key' => ''], ['api_key' => 'sk_live_old']);

        $this->assertSame([], $plan['upsert']);
        $this->assertContains('api_key', $plan['keep']);
    }

    public function test_any_mask_shaped_secret_keeps_the_stored_value(): void
    {
        // A stale mask from another value still is not an edit.
        $plan = ProfileKeyWriter::plan($this->schema, ['api_key' => 'zz*****yy'], ['api_key' => 'sk_live_old']);

        $this->assertSame([], $plan['upsert']);
    }

    public function test_the_current_short_mask_keeps_the_stored_value(): void
    {
        // 'abcd' masks to itself: no star, so only the exact-mask check catches it.
        $plan = ProfileKeyWriter::plan($this->schema, ['token' => 'abcd', 'api_key' => 'abab'], ['token' => 'abcd', 'api_key' => 'ab']);

        $this->assertSame([], $plan['upsert']);
    }

    public function test_non_secret_masked_looking_value_is_written(): void
    {
        $plan = ProfileKeyWriter::plan($this->schema, ['url' => 'ab*de', 'enabled' => ''], []);

        $this->assertSame(['url' => 'ab*de', 'enabled' => ''], $plan['upsert']);
    }

    public function test_unsubmitted_plain_keys_are_not_kept_but_secrets_are(): void
    {
        $plan = ProfileKeyWriter::plan($this->schema, [], ['url' => 'https://x', 'api_key' => 'sk']);

        $this->assertSame([], $plan['upsert']);
        $this->assertSame(['api_key', 'token'], $plan['keep']);
    }

    public function test_nothing_kept_when_nothing_submitted_and_no_secrets(): void
    {
        $plan = ProfileKeyWriter::plan([['key' => 'url', 'type' => 'string']], ['evil' => 'x'], ['url' => 'https://x']);

        $this->assertSame(['upsert' => [], 'keep' => []], $plan);
    }
}
