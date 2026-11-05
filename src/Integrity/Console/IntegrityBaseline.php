<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Console;

use PlaylogiqUtils\Integrity\CriticalComponentsInventory;
use PlaylogiqUtils\Integrity\ManifestBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds integrity-manifest.json from config/critical_components.php.
 *
 * This command produces the manifest and nothing else. It cannot sign, by design:
 * signing happens only in the pipeline, against a key held in AWS KMS that never
 * leaves it. Because the command has no signing path at all, a developer-made
 * signature is not merely discouraged, it is impossible — which is a stronger
 * guarantee than a flag anyone could pass.
 *
 * A developer regenerates the manifest after changing a critical component and
 * commits it. CI rebuilds it, requires a byte-for-byte match against the committed
 * copy, and only then signs those exact reviewed bytes.
 *
 * The command is intentionally side-effect free apart from writing the output
 * file: no DB, no cache, no queue. It must work in a bare build container.
 */
class IntegrityBaseline extends Command
{
    protected $signature = 'integrity:baseline
        {--output= : Where to write the manifest (default: integrity-manifest.json in the app root).}
        {--stdout : Print the manifest instead of writing it.}
        {--force : Bypass the INTEGRITY_ALLOW_BASELINE guard (overwriting a shipped baseline is normally a mistake).}';

    protected $description = 'Hash the critical-components inventory into the integrity manifest.';

    public function handle(): int
    {
        try {
            return $this->baseline();
        } catch (Throwable $e) {
            // A malformed inventory must fail the build loudly, not emit a
            // manifest covering less than it claims to.
            $this->error('Baseline failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function baseline(): int
    {
        if (!$this->guardEnvironment()) {
            return self::FAILURE;
        }

        $inventory = new CriticalComponentsInventory(
            (array) config('critical_components'),
            base_path()
        );

        $manifest = (new ManifestBuilder($inventory))->build();
        $bytes    = ManifestBuilder::canonicalJson($manifest);

        if ($this->option('stdout')) {
            $this->getOutput()->write($bytes);

            return self::SUCCESS;
        }

        $target = $this->resolveOutputPath();

        if (@file_put_contents($target, $bytes) === false) {
            $this->error('Could not write the manifest to ' . $target);

            return self::FAILURE;
        }

        // Any signature sitting next to a manifest this command just rewrote now
        // covers the previous bytes, so it is worse than absent: it looks like proof
        // and is not. Say so rather than leaving it to be noticed later.
        $this->report($manifest, $bytes, $target);

        return self::SUCCESS;
    }

    /**
     * A baseline must be an act of the pipeline, never of a running instance.
     *
     * The hard control is key custody: the signing key lives only in CI, so an
     * instance can at best produce an unsigned manifest, which verification
     * rejects. This is the cheap second lock — the pipeline opts in explicitly by
     * setting INTEGRITY_ALLOW_BASELINE, and anywhere the flag is absent refuses.
     *
     * This replaced an `APP_ENV !== production` check that misfired both ways: a
     * release build legitimately runs with APP_ENV=production, so every real build
     * needed --force and everyone learned to pass it reflexively; meanwhile any box
     * with APP_ENV unset defaults to production and was refused for no reason.
     *
     * Read through env() rather than config() deliberately. The command has to work
     * in a bare build container where config is not cached, and the flag must not be
     * something a committed file can switch on.
     */
    private function guardEnvironment(): bool
    {
        if ($this->option('force') || (bool) env('INTEGRITY_ALLOW_BASELINE', false)) {
            return true;
        }

        $this->error(
            'Refusing to baseline: a manifest must come from the pipeline, not from a running instance. '
            . 'Set INTEGRITY_ALLOW_BASELINE=true where baselining is intended, or pass --force if you '
            . 'genuinely mean to overwrite the shipped baseline on this machine.'
        );

        return false;
    }

    private function resolveOutputPath(): string
    {
        $output = (string) $this->option('output');

        if (trim($output) === '') {
            return base_path('integrity-manifest.json');
        }

        // Relative paths resolve against the app root, so CI does not have to
        // care what directory the step happens to run in.
        return substr($output, 0, 1) === '/' ? $output : base_path($output);
    }

    private function report(array $manifest, string $bytes, string $target): void
    {
        $this->info('Wrote ' . $target);
        $this->warn(
            'This manifest is unsigned. Commit it; the release pipeline signs the committed '
            . 'bytes with the KMS key. Any existing .sig file is now stale.'
        );

        $rows = [
            ['release_version', $manifest['release_version']],
            ['inventory_version', $manifest['inventory_version']],
            ['covered files', (string) count($manifest['files'])],
            ['settings keys', (string) count($manifest['settings_keys'])],
            ['global settings keys', (string) count($manifest['global_settings_keys'])],
        ];

        // The digest the pipeline will sign — log it so a build record ties to a manifest.
        $rows[] = ['manifest sha256', hash('sha256', $bytes)];

        $this->table(['field', 'value'], $rows);
    }
}
