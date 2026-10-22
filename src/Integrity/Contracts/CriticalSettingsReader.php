<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Contracts;

/**
 * Reads the live values of the critical settings registry, from wherever the application
 * keeps them.
 *
 * The one part of the settings check that cannot be shared: which tables hold a skin's
 * settings, and which keys are secret, belong to the application. Bind an implementation
 * in the container; CriticalSettingsGuard and the commands resolve it with an `inventory`
 * parameter (a CriticalComponentsInventory), so the constructor should accept one.
 *
 * Whatever the storage, an implementation must keep the rules the snapshot relies on:
 * read the persisted row and never a cache, read from the primary and never a replica,
 * record an unset key as null rather than dropping it, and refuse to read a secret.
 */
interface CriticalSettingsReader
{
    /**
     * The skin-level flag that puts a skin inside GLI scope. Also a registry key, so a
     * change to it is itself critical — which is what makes the transitions legible.
     */
    const REGULATED_KEY = 'is_gli_regulated';

    /**
     * Every skin under GLI rules, by the same test isRegulated() applies to one.
     *
     * @return int[]
     */
    public function regulatedSkinIds(): array;

    /** Is this skin operated under GLI rules? Unset counts as off. */
    public function isRegulated(int $skinId): bool;

    /**
     * Every registry key with this skin's live value, or the global value when $skinId is
     * null, sorted, null where unset.
     *
     * @return array<string, string|null>
     */
    public function values(?int $skinId): array;
}
