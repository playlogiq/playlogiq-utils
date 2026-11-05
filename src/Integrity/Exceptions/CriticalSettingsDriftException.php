<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Exceptions;

use RuntimeException;

/**
 * GLI-33 — a skin's (or the global) live critical settings no longer match the newest snapshot, and the
 * mismatch is in a setting this save does not touch.
 *
 * Thrown before anything is written, so the save is refused whole rather than half
 * applied. A mismatch inside the save's own scope does not reach here: the operator is
 * overwriting that value anyway, and the new snapshot records what they chose.
 *
 * CriticalSettingsGuard resolves it through the container with `settings` and `global`
 * parameters, so an application can bind a subclass that words the refusal for its
 * operators and renders it the way its forms expect.
 */
class CriticalSettingsDriftException extends RuntimeException
{
    /** @var string[] */
    protected $settings;

    /**
     * @param string[] $settings registry keys whose stored value disagrees with the snapshot
     * @param bool     $global   true when they are global settings rather than a skin's
     */
    public function __construct(array $settings, bool $global = false)
    {
        $this->settings = array_values($settings);

        parent::__construct(sprintf(
            '%s: %s.',
            $global
                ? 'The certified global settings no longer match the last recorded snapshot, so nothing was saved'
                : 'This skin\'s certified settings no longer match the last recorded snapshot, so nothing was saved',
            implode('; ', $this->settings)
        ));
    }

    /** @return string[] */
    public function settings(): array
    {
        return $this->settings;
    }
}
