<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use RuntimeException;

/**
 * Decides *what* a verification run checks and *which key* it trusts.
 *
 * Small on purpose, and separate on purpose. Both callers — the artisan command and the
 * scheduled job — have to answer the same two questions, and the second one ("is this
 * run allowed to skip the signature?") is the only place in the subsystem where a
 * verification can be legitimately weakened. Answering it once, here, means the rule is
 * reviewable in one place instead of being reimplemented either side of the subsystem
 * with a chance of drifting apart.
 *
 * Takes the configuration as an array rather than calling config() itself, so it stays
 * unit-testable without booting a framework — the same reason the rest of these classes
 * take a base path instead of calling base_path().
 */
class VerificationTarget
{
    const DEFAULT_MANIFEST = 'integrity-manifest.json';

    /** @var string */
    private $manifestPath;

    /** @var string|null */
    private $publicKeyPath;

    private function __construct(string $manifestPath, ?string $publicKeyPath)
    {
        $this->manifestPath  = $manifestPath;
        $this->publicKeyPath = $publicKeyPath;
    }

    /**
     * @param array       $config          the `verification` section of critical_components
     * @param string      $basePath        application root; relative paths resolve against it
     * @param string|null $manifest        override for the manifest path
     * @param string|null $publicKey       override for the verification key
     * @param bool        $allowUnsigned   drop the signature requirement — see below
     *
     * @throws RuntimeException when a signature is required and no key is configured
     */
    public static function fromConfig(
        array $config,
        string $basePath,
        ?string $manifest = null,
        ?string $publicKey = null,
        bool $allowUnsigned = false
    ): self {
        $basePath     = rtrim(str_replace('\\', '/', $basePath), '/');
        $manifestPath = self::absolute($basePath, self::clean($manifest) ?? self::DEFAULT_MANIFEST);

        $override = self::clean($publicKey);

        if ($allowUnsigned) {
            // Contradictory: one says which key to trust, the other says trust none.
            // Silently preferring either would make the run's meaning depend on an
            // argument-precedence rule nobody remembers.
            if ($override !== null) {
                throw new RuntimeException('Pass either --public-key or --allow-unsigned, not both.');
            }

            // Wins over the configured key on purpose. An environment that has one — a
            // developer .env copied from .env.example, CI after `cp .env.example .env` —
            // would otherwise be forced into a signature check that cannot pass, because
            // the signature is the pipeline's output and does not exist yet at the point
            // where checking hashes alone is the useful thing to do.
            return new self($manifestPath, null);
        }

        $key = $override ?? self::clean(isset($config['public_key']) ? (string) $config['public_key'] : null);

        // An unsigned check still proves the tree matches the manifest, but proves
        // nothing about where the manifest came from — and a manifest an instance could
        // have written itself is not evidence. So the caller has to ask for that
        // explicitly; it is never what happens when configuration is missing.
        if ($key === null) {
            throw new RuntimeException(
                'No verification key configured. Set INTEGRITY_PUBLIC_KEY to this environment\'s committed '
                . 'key (e.g. integrity-signing-public-staging.pem), or pass --allow-unsigned to check the '
                . 'file hashes only — which proves the tree matches the manifest, not that the manifest is '
                . 'the pipeline\'s.'
            );
        }

        return new self($manifestPath, self::absolute($basePath, $key));
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * Null means the signature will not be checked.
     */
    public function publicKeyPath(): ?string
    {
        return $this->publicKeyPath;
    }

    public function requiresSignature(): bool
    {
        return $this->publicKeyPath !== null;
    }

    /**
     * Relative paths resolve against the application root, never the working directory,
     * so a cron entry, a supervisor program and a shell all mean the same file.
     */
    private static function absolute(string $basePath, string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return substr($path, 0, 1) === '/' ? $path : $basePath . '/' . $path;
    }

    /**
     * Treats empty and whitespace-only as absent: an unset environment variable arrives
     * as '' rather than null, and '' must not resolve to the application root.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
