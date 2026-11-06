<?php

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures;

/** Duplicates, a key in both lists, string keys and `ignored`, to exercise ConfigKeys::of(). */
class OverlappingConfigKeysImplementation
{
    public $config_keys = [
        'secrets' => ['a' => 'token', 'pin', 'token'],
        'non_secrets' => ['base_url', 'pin', 'base_url', 'lang'],
        'ignored' => ['x' => 'legacy_flag'],
    ];
}
