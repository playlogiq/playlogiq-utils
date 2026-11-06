<?php

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures;

class StructuredConfigKeysImplementation
{
    public $config_keys = [
        'secrets' => ['client_password'],
        'non_secrets' => ['base_url', 'api_key'],
    ];

    public static function configSchema(): array
    {
        return [
            ['key' => 'client_password', 'label' => 'Client password', 'type' => 'password', 'required' => true],
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'string', 'required' => true],
        ];
    }
}
