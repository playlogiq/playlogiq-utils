<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Crypto;

use RuntimeException;

/**
 * The set of keys the application may decrypt and verify with.
 *
 * The first entry is the current key — the only one used to encrypt and sign.
 * The rest are retired keys kept readable during (and after) a rotation.
 *
 * With the *_KEY_ID and *_KEYS_OLD variables unset the ring holds exactly one
 * key, under $defaultKid ("app" for APP_KEY), and every consumer behaves as it
 * did before the ring existed.
 *
 * A decoded key (decodeBase64 = true) must be 32 bytes, the AES-256 material
 * APP_KEY holds; the check is here so a malformed APP_KEYS_OLD fails at boot
 * with the offending id named. Rings built with decodeBase64 = false hold
 * keys of arbitrary length, and RingEncrypter re-checks length against its
 * cipher for whichever ring it is given.
 */
final class AppKeyRing
{
    /** Kid used for the current APP_KEY when APP_KEY_ID is not configured. */
    public const LEGACY_KID = 'app';

    public const KID_PATTERN = '/^[a-z0-9_-]{1,16}$/';

    /** Length of a decoded key: AES-256 material, as Laravel's APP_KEY is. */
    public const KEY_BYTES = 32;

    /** @var array<string,KeyRingEntry> keyed by kid, current first */
    private array $entries = [];

    private string $currentKid;

    /** Env variable named in error messages, so a bad entry points at the right setting. */
    private string $oldKeysVar;

    /** Whether a "base64:" key is decoded to its bytes or kept exactly as written. */
    private bool $decodeBase64;

    /**
     * @param string      $currentKey   configured APP_KEY, e.g. "base64:...."
     * @param string|null $currentKid   configured APP_KEY_ID, or null
     * @param string      $oldKeys      configured APP_KEYS_OLD, "kid:key,kid:key"
     * @param string      $oldKeysVar   env variable $oldKeys came from
     * @param string      $defaultKid   kid for the current key when $currentKid is unset
     * @param bool        $decodeBase64 false keeps "base64:..." keys as written; Betmaker's
     *                                  custom_encrypt() hashes its APP_KEY with the prefix
     */
    public function __construct(
        string $currentKey,
        ?string $currentKid = null,
        string $oldKeys = '',
        string $oldKeysVar = 'APP_KEYS_OLD',
        string $defaultKid = self::LEGACY_KID,
        bool $decodeBase64 = true
    ) {
        $this->oldKeysVar = $oldKeysVar;
        $this->decodeBase64 = $decodeBase64;

        $kid = ($currentKid === null || trim($currentKid) === '')
            ? $defaultKid
            : trim($currentKid);

        $this->assertValidKid($kid);
        $this->currentKid = $kid;
        $this->entries[$kid] = $this->makeEntry($kid, $currentKey);

        foreach ($this->splitOldKeys($oldKeys) as $element) {
            $separator = strpos($element, ':');
            if ($separator === false || $separator === 0) {
                throw new RuntimeException(
                    $this->oldKeysVar . ': entry is not in "kid:key" form: ' . $this->redact($element)
                );
            }

            $oldKid = trim(substr($element, 0, $separator));
            $oldKey = trim(substr($element, $separator + 1));

            // "base64:AAAA..." with no id in front parses as the id "base64",
            // which then fails later with a message that tells the operator
            // nothing. Omitting the id is the likeliest way to get this
            // variable wrong, so name it.
            if ($oldKid === 'base64') {
                throw new RuntimeException(
                    $this->oldKeysVar . ': an entry is missing its key id. Each entry must be "id:key", '
                    . 'for example "k2:base64:....". The id is how stored values name the key '
                    . 'that encrypted them.'
                );
            }

            $this->assertValidKid($oldKid);

            // "app" is deliberately NOT reserved here. Before APP_KEY_ID is
            // configured the current id is "app", so everything written in that
            // period is stored as "c2:app:...". The first rotation has to retire
            // that key under the id it was known by, or those rows reference an
            // id that names no key and become permanently undecryptable.
            if (isset($this->entries[$oldKid])) {
                throw new RuntimeException($this->oldKeysVar . ': duplicate key id "' . $oldKid . '".');
            }

            $this->entries[$oldKid] = $this->makeEntry($oldKid, $oldKey);
        }
    }

    public function currentKid(): string
    {
        return $this->currentKid;
    }

    /**
     * Refuse to rotate under a ring whose current key is still called "app"
     * while retired keys exist.
     *
     * "app" is the id every value written before the ring existed refers to.
     * Retiring the previous key under any other id points those values at the
     * wrong key, and c1 rows look current to the rotation commands, so a
     * rotation in this state would pass its gate with the old secrets
     * stranded. Checked by the commands rather than the constructor so that a
     * misconfigured environment keeps serving requests as it did before.
     *
     * @throws RuntimeException
     */
    public function assertRotatable(): void
    {
        if (count($this->entries) > 1 && $this->currentKid === self::LEGACY_KID) {
            throw new RuntimeException(
                $this->oldKeysVar . ' requires an APP_KEY_ID other than "' . self::LEGACY_KID . '". '
                . 'Give the current key an id (for example "k2") and retire the previous one '
                . 'under the id it was known by, usually "' . self::LEGACY_KID . ':<key>".'
            );
        }
    }

    public function current(): KeyRingEntry
    {
        return $this->entries[$this->currentKid];
    }

    /**
     * @throws UnknownKeyException when the kid is not on the ring
     */
    public function keyFor(string $kid): KeyRingEntry
    {
        if (!isset($this->entries[$kid])) {
            throw new UnknownKeyException(
                'Key id "' . $kid . '" is not on the ring. Known ids: ' . implode(', ', $this->kids()) . '. '
                . 'If this key was retired, restore it to ' . $this->oldKeysVar . ' and re-run secrets:rotate.'
            );
        }

        return $this->entries[$kid];
    }

    /**
     * @return KeyRingEntry[] current key first, then old keys in configured order
     */
    public function all(): array
    {
        return array_values($this->entries);
    }

    /**
     * @return string[]
     */
    public function kids(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $kid): bool
    {
        return isset($this->entries[$kid]);
    }

    /**
     * @return string[] non-empty, trimmed elements
     */
    private function splitOldKeys(string $oldKeys): array
    {
        $elements = [];
        foreach (explode(',', $oldKeys) as $element) {
            $element = trim($element);
            if ($element !== '') {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    private function makeEntry(string $kid, string $literal): KeyRingEntry
    {
        $literal = trim($literal);

        if ($literal === '') {
            throw new RuntimeException('Key "' . $kid . '" is empty.');
        }

        $raw = $literal;
        if ($this->decodeBase64 && strncmp($raw, 'base64:', 7) === 0) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('Key "' . $kid . '" is not valid base64.');
            }
            $raw = $decoded;
        }

        if ($this->decodeBase64 && strlen($raw) !== self::KEY_BYTES) {
            throw new RuntimeException(
                'Key "' . $kid . '" has the wrong length: must be ' . self::KEY_BYTES . ' bytes, got ' . strlen($raw) . '.'
            );
        }

        return new KeyRingEntry($kid, $raw, $literal);
    }

    private function assertValidKid(string $kid): void
    {
        if (!preg_match(self::KID_PATTERN, $kid)) {
            throw new RuntimeException(
                'Invalid key id "' . $kid . '": must match ' . self::KID_PATTERN . '.'
            );
        }
    }

    /** Never echo key material back in an exception message. */
    private function redact(string $element): string
    {
        return substr($element, 0, 4) . '...';
    }
}
