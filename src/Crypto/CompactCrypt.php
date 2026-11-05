<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Crypto;

use Illuminate\Container\Container;
use RuntimeException;

/**
 * Compact authenticated encryption for short secrets.
 *
 * Output is much shorter than Laravel's Crypt (which wraps everything in a
 * JSON object then base64s it). For a 32-byte plaintext this produces ~85
 * chars, so it fits comfortably inside a VARCHAR(255) column.
 *
 * Two formats exist:
 *
 *   c1:<b64url(iv|tag|ct)>          legacy, key = sha256(APP_KEY), read-only
 *   c2:<kid>:<b64url(iv|tag|ct)>    current, key = sha256(ring key for kid)
 *
 * The kid in a c2 payload is bound in as GCM additional authenticated data, so
 * a stored value cannot be relabelled to point at a different key without
 * failing the tag check. It is a lookup hint, not a requirement: a c2 payload
 * whose kid is not on the ring is tried against every ring key (the tag
 * still only opens under the key that wrote it), so clearing APP_KEY_ID or
 * APP_KEYS_OLD never makes a row unreadable as long as its key is still
 * configured somewhere. c1 payloads carry no id, so they are always tried
 * against every key on the ring; kidOf() reports them as the reserved id
 * "app", which is what the ring calls APP_KEY when no APP_KEY_ID is set.
 */
class CompactCrypt
{
    public const PREFIX = 'c1:';
    public const PREFIX_V2 = 'c2:';

    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    public static function isCompactPayload(string $value): bool
    {
        return strncmp($value, self::PREFIX, 3) === 0
            || strncmp($value, self::PREFIX_V2, 3) === 0;
    }

    /**
     * The key id a payload was encrypted under, or null if it is not a
     * compact payload at all. Used by the rotation commands to count rows by
     * key without decrypting them.
     */
    public static function kidOf(string $payload): ?string
    {
        if (strncmp($payload, self::PREFIX, 3) === 0) {
            return AppKeyRing::LEGACY_KID;
        }

        if (strncmp($payload, self::PREFIX_V2, 3) !== 0) {
            return null;
        }

        $rest = substr($payload, 3);
        $separator = strpos($rest, ':');
        if ($separator === false || $separator === 0) {
            return null;
        }

        return substr($rest, 0, $separator);
    }

    /**
     * Whether a stored value is a c2 payload under exactly this key id.
     *
     * A c1 payload names no key, so it is never "on" one even though kidOf()
     * reports it as "app": the rotation commands must still rewrite it.
     */
    public static function isOnKey(string $payload, string $kid): bool
    {
        return strncmp($payload, self::PREFIX_V2, 3) === 0 && self::kidOf($payload) === $kid;
    }

    /**
     * Whether a stored value looks like a Laravel Crypt payload (base64 of a
     * JSON object starting with "iv"), as opposed to plaintext. Does not try
     * to decrypt it.
     */
    public static function isLaravelCryptPayload(string $value): bool
    {
        $decoded = base64_decode($value, true);

        return $decoded !== false && strncmp($decoded, '{"iv"', 5) === 0;
    }

    public static function encrypt(string $plaintext): string
    {
        $entry = self::ring()->current();
        $kid = $entry->kid();

        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::deriveKey($entry->raw()),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $kid,
            self::TAG_LEN
        );
        if ($cipher === false) {
            throw new RuntimeException('CompactCrypt: encryption failed');
        }

        return self::PREFIX_V2 . $kid . ':' . self::b64UrlEncode($iv . $tag . $cipher);
    }

    /**
     * @throws UnknownKeyException when the payload names a key not on the ring
     * @throws RuntimeException    when the payload is malformed or fails authentication
     */
    public static function decrypt(string $payload): string
    {
        if (strncmp($payload, self::PREFIX_V2, 3) === 0) {
            $kid = self::kidOf($payload);
            if ($kid === null) {
                throw new RuntimeException('CompactCrypt: malformed c2 payload');
            }

            $ring = self::ring();
            $body = substr($payload, 3 + strlen($kid) + 1);

            // The kid is authenticated, not just carried: a relabelled row
            // fails the tag check rather than decrypting under the wrong key.
            if ($ring->has($kid)) {
                return self::open($body, self::deriveKey($ring->keyFor($kid)->raw()), $kid);
            }

            // The kid names no key on the ring. That happens when APP_KEY_ID
            // was cleared (or a key re-listed under a different id) while the
            // key itself is still configured — the label changed, the material
            // did not. Because the kid is GCM additional data, only the key
            // that wrote the row can open it, so trying the whole ring is as
            // safe as the direct lookup above. Only when none of them opens it
            // is the key truly missing, and that is reported as an unknown key
            // so secrets:rotate can tell the operator which id to restore.
            foreach ($ring->all() as $entry) {
                try {
                    return self::open($body, self::deriveKey($entry->raw()), $kid);
                } catch (RuntimeException $e) {
                    // try the next key
                }
            }

            $ring->keyFor($kid); // throws UnknownKeyException
        }

        if (strncmp($payload, self::PREFIX, 3) === 0) {
            // c1 payloads carry no key id, so there is nothing to look up: try
            // every key on the ring. This is safe and unambiguous because GCM
            // authenticates — a wrong key fails the tag rather than returning
            // garbage. It is also load-bearing: every value written before the
            // ring existed is a c1 payload, and it must stay readable after
            // APP_KEY changes.
            $body = substr($payload, 3);
            $failure = null;

            foreach (self::ring()->all() as $entry) {
                try {
                    return self::open($body, self::deriveKey($entry->raw()), '');
                } catch (RuntimeException $e) {
                    $failure = $e;
                }
            }

            throw $failure ?: new RuntimeException('CompactCrypt: authentication failed');
        }

        throw new RuntimeException('CompactCrypt: not a compact payload');
    }

    private static function open(string $body, string $key, string $aad): string
    {
        $raw = self::b64UrlDecode($body);
        // No "+ 1": an empty secret encrypts to iv|tag alone, and the tag still
        // authenticates it. Requiring a ciphertext byte made those rows unreadable.
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('CompactCrypt: payload too short');
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) {
            throw new RuntimeException('CompactCrypt: authentication failed');
        }

        return $plain;
    }

    private static function ring(): AppKeyRing
    {
        // The container the host application booted. Equivalent to app() in a
        // Laravel app, without depending on illuminate/foundation's helpers.
        return Container::getInstance()->make(AppKeyRing::class);
    }

    private static function deriveKey(string $rawKey): string
    {
        if ($rawKey === '') {
            throw new RuntimeException('CompactCrypt: APP_KEY is not configured');
        }

        return hash('sha256', $rawKey, true);
    }

    private static function b64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $s)
    {
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }

        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
