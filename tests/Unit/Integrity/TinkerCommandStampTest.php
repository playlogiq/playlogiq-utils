<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\Integrity\ExecStamp;
use PlaylogiqUtils\Integrity\Jobs\ImportTinkerSessionCommands;

/**
 * PQPL-7456 — every application's tinker sessions share one Session Manager log group, so a
 * stamped command names its application and each import takes only its own.
 */
class TinkerCommandStampTest extends TestCase
{
    private const REF = 'c142b9265562561b';

    public function testAnApplicationImportsTheCommandsItStamped(): void
    {
        $line = ExecStamp::commandLine('backoffice', self::REF, 'return User::count();');

        $this->assertSame('[tinker-cmd] app=backoffice ref=' . self::REF . ' ' . base64_encode('return User::count();'), $line);
        $this->assertSame(
            ['ref' => self::REF, 'code' => 'return User::count();'],
            ImportTinkerSessionCommands::commandIn($line, 'backoffice', false)
        );
    }

    public function testAnotherApplicationsCommandsAreIgnored(): void
    {
        $line = ExecStamp::commandLine('betmaker', self::REF, 'return 1;');

        $this->assertNull(ImportTinkerSessionCommands::commandIn($line, 'backoffice', false));
        $this->assertNull(ImportTinkerSessionCommands::commandIn($line, 'backoffice', true));
    }

    public function testUntaggedCommandsOnlyWhereAccepted(): void
    {
        // A stamp from before PQPL-7456, or from an application with no app configured.
        $line = ExecStamp::commandLine('', self::REF, 'return 1;');

        $this->assertSame('[tinker-cmd] ref=' . self::REF . ' ' . base64_encode('return 1;'), $line);
        $this->assertSame(['ref' => self::REF, 'code' => 'return 1;'], ImportTinkerSessionCommands::commandIn($line, 'betmaker', true));
        $this->assertNull(ImportTinkerSessionCommands::commandIn($line, 'backoffice', false));
    }

    public function testAStampQuotedInsideOutputIsNotACommand(): void
    {
        $line = '    "' . ExecStamp::commandLine('backoffice', self::REF, 'return 1;') . '",';

        $this->assertNull(ImportTinkerSessionCommands::commandIn($line, 'backoffice', true));
    }
}
