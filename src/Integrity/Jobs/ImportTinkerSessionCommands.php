<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PlaylogiqUtils\Integrity\ExecStamp;
use PlaylogiqUtils\Integrity\Models\OperationsAuditLog;
use PlaylogiqUtils\Integrity\SessionManagerLogs;
use PlaylogiqUtils\Jobs\BaseJob;

/**
 * GLI-33 (PQPL-7324) — the commands run in production tinker, out of the Session Manager log.
 *
 * Tinker prints each command it runs as a `[tinker-cmd] app=<app> ref=<16 hex> <base64>` line
 * ({@see \PlaylogiqUtils\Integrity\Tinker\StampExecutedCode}); Session Manager ships it to
 * CloudWatch in an event carrying the operator's IAM identity, the session and the instance.
 *
 * PQPL-7456: every application's sessions share the log group, so each one imports only the
 * lines stamped with its own integrity.session_audit.app. Lines from before the tag existed
 * carry none, and only the application with integrity.session_audit.accept_untagged takes them.
 *
 * Each one becomes an operations_audit_log row; output never reaches here, only the stamped code.
 * Applications extend this job, to keep their own class name and INTERNAL_JOB_ID.
 */
class ImportTinkerSessionCommands extends BaseJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const LOOKBACK_MINUTES = 120;

    /** A whole sessionData line, so a stamp quoted inside some output does not count. */
    private const LINE = '/^\[tinker-cmd\](?: app=([A-Za-z0-9_-]+))? ref=([0-9a-f]{16}) ([A-Za-z0-9+\/=]+)$/';

    public $tries = 1;

    // One run at a time: firstOrCreate is not atomic, so two overlapping runs could race on a ref.
    public $uniqueFor = 10 * 60;

    public function handle(SessionManagerLogs $logs): void
    {
        $config = (array) config('integrity.session_audit');
        $app = trim((string) ($config['app'] ?? ''));
        $acceptUntagged = (bool) ($config['accept_untagged'] ?? false);

        $sinceMs = CarbonImmutable::now()->subMinutes(self::LOOKBACK_MINUTES)->getTimestamp() * 1000;

        foreach ($logs->filterAllEvents('"' . ExecStamp::COMMAND_PREFIX . '"', $sinceMs) as $event) {
            $message = json_decode((string) ($event['message'] ?? ''), true);
            $arn = (string) ($message['userIdentity']['arn'] ?? '');

            foreach ((array) ($message['sessionData'] ?? []) as $line) {
                $command = self::commandIn((string) $line, $app, $acceptUntagged);
                if ($command === null) {
                    continue;
                }

                // The ref is unique per command run: a rerun over the same window finds it and adds nothing.
                OperationsAuditLog::firstOrCreate([
                    'event_type' => OperationsAuditLog::EVENT_TINKER_COMMAND,
                    'reference'  => $command['ref'],
                ], [
                    'actor'       => $arn !== '' ? ExecStamp::operatorFromArn($arn) : null,
                    'occurred_at' => CarbonImmutable::createFromTimestampMs((int) $event['timestamp'], 'UTC'),
                    'details'     => [
                        'command'        => $command['code'],
                        'iam_arn'        => $arn !== '' ? $arn : null,
                        'ssm_session_id' => $message['sessionId'] ?? null,
                        'instance_id'    => $message['target']['id'] ?? null,
                        'log_group'      => $logs->logGroup(),
                        'log_stream'     => $event['logStreamName'] ?? null,
                    ],
                    // Set here, not left to the column default, so the integrity hash covers it.
                    'created_at'  => CarbonImmutable::now('UTC'),
                ]);
            }
        }
    }

    /**
     * The command a sessionData line stamps for $app, or null: not a stamp, another
     * application's, or untagged while $acceptUntagged is off.
     *
     * @return array{ref: string, code: string|false}|null
     */
    public static function commandIn(string $line, string $app, bool $acceptUntagged): ?array
    {
        if (!preg_match(self::LINE, $line, $m)) {
            return null;
        }

        $tagged = $m[1] !== '';
        if ($tagged ? $m[1] !== $app : !$acceptUntagged) {
            return null;
        }

        return ['ref' => $m[2], 'code' => base64_decode($m[3], true)];
    }
}
