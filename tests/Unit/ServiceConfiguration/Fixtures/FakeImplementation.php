<?php

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures;

class FakeImplementation
{
    public static function configSchema(): array
    {
        return [
            ['key' => 'url', 'label' => 'URL', 'type' => 'string', 'required' => true],
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true],
        ];
    }
}
