<?php

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures;

use PlaylogiqUtils\Crypto\EncryptsSpecificKeys;

/** Overrides only secretKeysFor(), like backoffice's EncryptsScpSecretKeys. */
class OverridingSecretKeysCast extends EncryptsSpecificKeys
{
    public static function secretKeysFor($model = null): array
    {
        return ['api_key'];
    }
}
