<?php

namespace PlaylogiqUtils\Tests\Unit\Integrity;

use PlaylogiqUtils\Integrity\VerificationReport;
use PHPUnit\Framework\TestCase;

/**
 * PQPL-5432 (GLI-33 2.3): the failure record kept on an app_integrity_check_instances row.
 */
class VerificationReportTest extends TestCase
{
    public function test_the_failure_record_lists_only_failed_checks_with_their_files_by_kind(): void
    {
        $report = new VerificationReport();
        $report->pass('signature', 'valid');
        $report->fail('files', '2 of 40 covered files do not match the manifest', [
            'modified' => ['app/Helpers/utils.php'],
            'missing' => ['app/Models/Setting.php'],
            'extra' => [],
        ]);

        $record = $report->failureRecord();

        $this->assertSame('signature=passed files=failed', $record['summary']);
        $this->assertSame(['files'], array_keys($record['checks']));
        $this->assertSame('2 of 40 covered files do not match the manifest', $record['checks']['files']['detail']);
        $this->assertSame(
            [
                'modified' => ['count' => 1, 'paths' => ['app/Helpers/utils.php']],
                'missing' => ['count' => 1, 'paths' => ['app/Models/Setting.php']],
            ],
            $record['checks']['files']['items']
        );
    }

    public function test_the_failure_record_caps_paths_but_keeps_the_full_count(): void
    {
        $paths = [];
        for ($i = 0; $i < 5; $i++) {
            $paths[] = 'app/File' . $i . '.php';
        }

        $report = new VerificationReport();
        $report->fail('files', 'every file differs', ['modified' => $paths]);

        $item = $report->failureRecord(2)['checks']['files']['items']['modified'];

        $this->assertSame(5, $item['count']);
        $this->assertSame(['app/File0.php', 'app/File1.php'], $item['paths']);
    }
}
