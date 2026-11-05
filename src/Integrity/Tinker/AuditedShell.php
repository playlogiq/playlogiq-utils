<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Tinker;

use PlaylogiqUtils\Integrity\ExecStamp;
use Psy\Shell;

/** PsySH shell with {@see StampExecutedCode} on its loop; PsySH has no config for extra listeners. */
class AuditedShell extends Shell
{
    protected function getDefaultLoopListeners(): array
    {
        return array_merge(parent::getDefaultLoopListeners(), [new StampExecutedCode()]);
    }

    /**
     * PsySH commands never reach onExecute, and some run things: the artisan commands tinker
     * exposes (migrate, down, up, optimize...) and `timeit <code>`. Stamped as typed.
     */
    protected function runCommand(string $input)
    {
        $stamp = app(ExecStamp::class);
        if ($stamp->isConfigured()) {
            $stamp->stampCommand($input);
        }

        return parent::runCommand($input);
    }
}
