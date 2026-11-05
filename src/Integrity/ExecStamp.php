<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GLI-33 (PQPL-6489, first user: master resets) — who is behind `ssm-user`.
 *
 * Under AWS Session Manager every operator shell runs as the shared `ssm-user`, so the
 * instance cannot tell people apart. AWS can: Session Manager streams the terminal to a
 * CloudWatch log group, one event per ~second of output, each carrying the IAM identity
 * that opened the session. Any audited action can use the two halves:
 *
 *   $ref = $execStamp->stamp('master_reset');          // prints "[exec-stamp] master_reset ref=…"
 *   ...the action runs...
 *   $who = $execStamp->insideSessionManager() ? $execStamp->resolve($ref, $stampedAtMs) : null;
 *
 * resolve() finds the log event holding the reference and returns that identity. The AWS
 * calls go through {@see SessionManagerLogs}; this lookup only needs logs:FilterLogEvents.
 *
 * Never throws. No terminal, a disabled or unconfigured lookup, an AWS error, a timeout, or
 * a reference that has not reached CloudWatch yet all return null, and the caller keeps
 * `ssm-user`.
 */
class ExecStamp
{
    public const PREFIX = '[exec-stamp]';

    public const COMMAND_PREFIX = '[tinker-cmd]';

    /** The Session Manager worker owning the shell, as the kernel truncates its name (15 chars). */
    private const SSM_WORKER_NAME = 'ssm-session-wor';

    /** @var bool */
    private $enabled;

    /** The application the stamped commands belong to, so each one imports only its own. */
    private $app;

    /** @var int */
    private $attempts;

    /** @var int */
    private $delayMs;

    /** @var SessionManagerLogs */
    private $logs;

    /**
     * Events the last filterLogEvents call returned, kept for the miss log.
     *
     * @var array<int, array<string, mixed>>
     */
    private $lastEvents = [];

    /**
     * @param array<string, mixed> $config the integrity.session_audit section
     */
    public function __construct(array $config, ?CloudWatchLogsClient $client = null)
    {
        $this->enabled  = (bool) ($config['enabled'] ?? false);
        $this->app      = trim((string) ($config['app'] ?? ''));
        $this->attempts = max(1, (int) ($config['attempts'] ?? 1));
        $this->delayMs  = max(0, (int) ($config['delay_ms'] ?? 0));
        // Short: this runs at the end of an operator's command, which waits on it.
        $this->logs     = new SessionManagerLogs($config, $client, ['connect_timeout' => 2, 'timeout' => 3]);
    }

    public static function fromConfig(): self
    {
        return new self((array) config('integrity.session_audit'));
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->logs->isConfigured();
    }

    /**
     * Prints `[exec-stamp] <action> ref=<16 hex>` to the operator's terminal and returns the ref,
     * or null without a terminal (cron, supervisor, php-fpm). Written to /dev/tty in one call
     * so it is never split across log chunks, and so it shows even from tinker, where
     * Artisan::call buffers a command's own output.
     */
    public function stamp(string $action): ?string
    {
        $ref = bin2hex(random_bytes(8));

        return self::writeToTerminal(self::PREFIX . ' ' . $action . ' ref=' . $ref) ? $ref : null;
    }

    /**
     * PQPL-7324: prints `[tinker-cmd] app=<app> ref=<16 hex> <base64 code>` for a tinker command
     * about to run, so the Session Manager log holds the exact code on one line however it was
     * typed. Returns the ref, or null without a terminal.
     */
    public function stampCommand(string $code): ?string
    {
        // shortcut: SSM splits lines at ~32 KB, so code over ~24 KB is stamped truncated; cap it and stamp len+sha256 if that ever matters.
        $ref = bin2hex(random_bytes(8));

        return self::writeToTerminal(self::commandLine($this->app, $ref, $code)) ? $ref : null;
    }

    /**
     * PQPL-7456: the log group is shared by every application, so the line names its own. With
     * no app configured the command is still stamped, untagged: losing the audit is worse.
     */
    public static function commandLine(string $app, string $ref, string $code): string
    {
        return self::COMMAND_PREFIX . ($app !== '' ? ' app=' . $app : '') . ' ref=' . $ref . ' ' . base64_encode($code);
    }

    private static function writeToTerminal(string $line): bool
    {
        $tty = @fopen('/dev/tty', 'w');
        if ($tty === false) {
            return false;
        }

        $written = @fwrite($tty, $line . PHP_EOL);
        @fclose($tty);

        return (bool) $written;
    }

    /** Whether a parent process is the Session Manager worker that owns this shell. */
    public function insideSessionManager(): bool
    {
        $pid = function_exists('posix_getppid') ? posix_getppid() : 0;
        for ($depth = 0; $pid > 1 && $depth < 15; $depth++) {
            $status = @file_get_contents("/proc/{$pid}/status");
            if ($status === false) {
                return false;
            }
            if (preg_match('/^Name:\s+(\S+)/m', $status, $m) && strpos($m[1], self::SSM_WORKER_NAME) === 0) {
                return true;
            }
            $pid = preg_match('/^PPid:\s+(\d+)/m', $status, $m) ? (int) $m[1] : 0;
        }

        return false;
    }

    /**
     * The Session Manager session whose terminal printed $ref, or null.
     *
     * The terminal is shipped about once a second and CloudWatch indexes it a few seconds
     * later, so a miss is retried. The reference is written when the command starts, which
     * usually leaves the command's own runtime for that delay to pass.
     *
     * @param int $sinceMs epoch milliseconds the reference was written at
     * @return array{operator: string, iam_arn: string, ssm_session_id: ?string, instance_id: ?string}|null
     */
    public function resolve(string $ref, int $sinceMs): ?array
    {
        if (!$this->isConfigured() || $ref === '') {
            return null;
        }

        $context = [
            'ref'        => $ref,
            'log_group'  => $this->logs->logGroup(),
            'region'     => $this->logs->region(),
            'start_time' => $sinceMs - 60000,
            'attempts'   => $this->attempts,
        ];

        try {
            for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
                $found = $this->find($ref, $sinceMs);
                if ($found !== null) {
                    return $found;
                }
                if ($attempt < $this->attempts && $this->delayMs > 0) {
                    usleep($this->delayMs * 1000);
                }
            }
        } catch (Throwable $e) {
            Log::warning('exec_stamp.lookup_failed', $context + [
                'attempt'    => $attempt ?? 0,
                // AwsException carries the AWS error code (AccessDeniedException, ...).
                'aws_code'   => method_exists($e, 'getAwsErrorCode') ? $e->getAwsErrorCode() : null,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }

        // Every attempt came back without an event holding the reference and an identity.
        Log::warning('exec_stamp.lookup_not_found', $context + [
            'events_returned' => count($this->lastEvents),
            'events'          => self::summarize($this->lastEvents),
        ]);

        return null;
    }

    private function find(string $ref, int $sinceMs): ?array
    {
        // Quoted: an exact term match. The ref is 16 hex chars, unique per command run.
        // A minute of slack on startTime for clock skew between the instance and CloudWatch.
        $this->lastEvents = $this->logs->filterEvents('"' . $ref . '"', $sinceMs - 60000, 10);

        foreach ($this->lastEvents as $event) {
            $message = json_decode((string) ($event['message'] ?? ''), true);
            $arn = $message['userIdentity']['arn'] ?? null;
            if (!is_string($arn) || $arn === '') {
                continue;
            }

            return [
                'operator'       => self::operatorFromArn($arn),
                'iam_arn'        => $arn,
                'ssm_session_id' => $message['sessionId'] ?? null,
                'instance_id'    => $message['target']['id'] ?? null,
            ];
        }

        return null;
    }

    /**
     * First events of a response, trimmed for the log: the message can hold whatever was
     * typed in the terminal.
     */
    private static function summarize(array $events): array
    {
        return array_map(static function (array $event): array {
            return [
                'log_stream' => $event['logStreamName'] ?? null,
                'timestamp'  => $event['timestamp'] ?? null,
                'message'    => mb_substr((string) ($event['message'] ?? ''), 0, 500),
            ];
        }, array_slice($events, 0, 5));
    }

        /**
     * The person in an IAM principal ARN: the session name of an assumed role
     * (`.../assumed-role/playlogiq/f.bavaro@playlogiq.com`) or the IAM user name.
     */
    public static function operatorFromArn(string $arn): string
    {
        $slash = strrpos($arn, '/');

        return $slash === false ? $arn : substr($arn, $slash + 1);
    }
}
