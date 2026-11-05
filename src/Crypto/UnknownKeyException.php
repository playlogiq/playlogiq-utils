<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Crypto;

use RuntimeException;

/**
 * Thrown when a payload names a key id that is not on the ring.
 *
 * Deliberately distinct from a decryption/authentication failure: this one
 * means a key was retired while data still referenced it, and the fix is to
 * restore that key to the *_KEYS_OLD variable and re-run the migration. An
 * authentication failure means the data itself is corrupt.
 */
class UnknownKeyException extends RuntimeException
{
}
