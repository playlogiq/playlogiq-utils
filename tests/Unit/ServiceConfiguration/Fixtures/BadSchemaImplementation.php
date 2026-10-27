<?php

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures;

class BadSchemaImplementation
{
    public static function configSchema()
    {
        return 'not an array';
    }
}
