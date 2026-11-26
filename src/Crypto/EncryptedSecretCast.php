<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Crypto;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

/**
 * Eloquent cast for a column that always holds a secret (e.g. a TOTP secret), shared by Betmaker
 * and Backoffice (GLI-33 6.16.1, PQPL-7871). Same compact, key-ring aware encryption as the
 * settings casts (StoredSecret / CompactCrypt): a short secret stays well inside a VARCHAR(255),
 * and the host's secrets:rotate can re-key it. A value still stored in clear reads back unchanged.
 *
 *     protected $casts = ['google2fa_secret' => EncryptedSecretCast::class];
 *
 * Rolling deploys, where the previous release keeps serving while the next one's migrations run:
 * ship ':read-only' first (decrypts, still writes as is), then the plain cast (encrypts on write),
 * then a backfill migration. Each release only writes what the one before can read.
 *
 *     protected $casts = ['google2fa_secret' => EncryptedSecretCast::class . ':read-only'];
 */
class EncryptedSecretCast implements CastsAttributes
{
    public const MODE_ENCRYPT = 'encrypt';
    public const MODE_READ_ONLY = 'read-only';

    /** @var string */
    private $mode;

    public function __construct(string $mode = self::MODE_ENCRYPT)
    {
        if (!in_array($mode, [self::MODE_ENCRYPT, self::MODE_READ_ONLY], true)) {
            throw new InvalidArgumentException('EncryptedSecretCast: unknown mode "' . $mode . '"');
        }

        $this->mode = $mode;
    }

    public function get($model, string $key, $value, array $attributes)
    {
        return StoredSecret::decrypt($value, ['model' => get_class($model), 'attribute' => $key]);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $this->mode === self::MODE_READ_ONLY ? $value : StoredSecret::encrypt($value);
    }
}
