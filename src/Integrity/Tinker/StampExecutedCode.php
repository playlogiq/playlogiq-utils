<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Tinker;

use PlaylogiqUtils\Integrity\ExecStamp;
use Psy\ExecutionClosure;
use Psy\ExecutionLoop\AbstractListener;
use Psy\Shell;

/**
 * GLI-33 (PQPL-7324) — every block of code tinker runs is printed to the terminal, right
 * before it runs, as one `[tinker-cmd]` line Session Manager records with the operator's
 * IAM identity. ImportTinkerSessionCommands reads those lines back from CloudWatch.
 *
 * Fires on what PHP executes, not on what was typed: readline edits are already applied,
 * multiline input arrives whole, and the output never goes through here. The code is
 * PsySH's cleaned version (an expression comes back as `return <expr>;`).
 */
class StampExecutedCode extends AbstractListener
{
    public static function isSupported(): bool
    {
        return true;
    }

    public function onExecute(Shell $shell, string $code)
    {
        $stamp = app(ExecStamp::class);
        if ($code !== ExecutionClosure::NOOP_INPUT && $stamp->isConfigured()) {
            $stamp->stampCommand($code);
        }

        return null;
    }
}
