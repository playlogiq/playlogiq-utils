<?php

declare(strict_types=1);

namespace PlaylogiqUtils\ProviderAuth;

use PlaylogiqUtils\Integrity\HostIdentity;
use Psr\Log\LoggerInterface;

/**
 * Writes one log line per authentication event with a third-party provider: every login
 * (token or session request) and every call the provider rejects for bad credentials.
 * Only the URL host and path are kept; headers, bodies and query strings never reach the log.
 */
class ProviderAuthLogger
{
    const MESSAGE = 'provider_auth';

    const OUTCOME_SUCCESS = 'success';
    const OUTCOME_FAILURE = 'failure';

    const ERROR_NO_RESPONSE = 'no_response';
    const ERROR_HTTP_PREFIX = 'HTTP ';
    const ERROR_MAX_LENGTH = 200;
    const MS_PER_SECOND = 1000;

    const HTTP_OK_MIN = 200;
    const HTTP_OK_MAX = 299;
    const HTTP_UNAUTHORIZED = 401;
    const HTTP_FORBIDDEN = 403;

    const CURLE_COULDNT_RESOLVE_HOST = 6;
    const CURLE_COULDNT_CONNECT = 7;
    const CURLE_OPERATION_TIMEDOUT = 28;
    const CURLE_SSL_CONNECT_ERROR = 35;
    const CURLE_PEER_FAILED_VERIFICATION = 51;
    const CURLE_SSL_CERTPROBLEM = 58;
    const CURLE_SSL_CACERT = 60;

    const CURL_ERROR_TYPES = [
        self::CURLE_COULDNT_RESOLVE_HOST     => 'dns',
        self::CURLE_COULDNT_CONNECT          => 'connect',
        self::CURLE_OPERATION_TIMEDOUT       => 'timeout',
        self::CURLE_SSL_CONNECT_ERROR        => 'tls',
        self::CURLE_PEER_FAILED_VERIFICATION => 'tls',
        self::CURLE_SSL_CERTPROBLEM          => 'tls',
        self::CURLE_SSL_CACERT               => 'tls',
    ];

    private HostIdentity $host;

    private ?string $sourceHost = null;

    public function __construct(private LoggerInterface $log, private string $app, ?HostIdentity $host = null)
    {
        $this->host = $host ?? new HostIdentity();
    }

    /**
     * Writes a line only for an auth event: every login, and any other call rejected with HTTP 401/403
     * or a credential code. $error is the provider credential code when the provider answered,
     * or the network error type when it did not.
     */
    public function recordIfAuthEvent(string $provider, string $method, string $url, bool $isLogin, ?int $status, ?string $error, int $durationMs, array $context = [], ?callable $gate = null): void
    {
        if ($error === null && ($status === self::HTTP_UNAUTHORIZED || $status === self::HTTP_FORBIDDEN)) {
            $error = self::ERROR_HTTP_PREFIX . $status;
        }

        if ($isLogin) {
            $success = $error === null && $status !== null && self::isSuccessStatus($status);
            if (!$success && $error === null) {
                $error = $status === null ? self::ERROR_NO_RESPONSE : self::ERROR_HTTP_PREFIX . $status;
            }
            $this->record($provider, $method, $url, $status, $success, $success ? null : $error, $durationMs, $context, $gate);
        } elseif ($status !== null && $error !== null) {
            $this->record($provider, $method, $url, $status, false, $error, $durationMs, $context, $gate);
        }
    }

    /**
     * $context fields go right after source_host. $gate, when given, is asked once per
     * event and the line is dropped when it returns false.
     */
    public function record(string $provider, string $method, string $url, ?int $status, bool $success, ?string $error, int $durationMs, array $context = [], ?callable $gate = null): void
    {
        try {
            if ($gate !== null && !$gate()) {
                return;
            }

            $fields = array_merge(
                [
                    'timestamp'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                    'app'         => $this->app,
                    'source_host' => $this->sourceHost(),
                ],
                $context,
                [
                    'provider'      => $provider,
                    'method'        => strtoupper($method),
                    'provider_host' => (string) parse_url($url, PHP_URL_HOST),
                    'endpoint'      => (string) parse_url($url, PHP_URL_PATH),
                    'status'        => $status,
                    'outcome'       => $success ? self::OUTCOME_SUCCESS : self::OUTCOME_FAILURE,
                    'duration_ms'   => $durationMs,
                ]
            );

            if (!$success) {
                $fields['error'] = substr((string) $error, 0, self::ERROR_MAX_LENGTH);
            }

            $this->log->info(self::MESSAGE, $fields);
        } catch (\Throwable $e) {
            self::reportQuietly($e);
        }
    }

    public static function isSuccessStatus(int $status): bool
    {
        return $status >= self::HTTP_OK_MIN && $status <= self::HTTP_OK_MAX;
    }

    public static function curlErrorType(int $errno): string
    {
        return self::CURL_ERROR_TYPES[$errno] ?? 'curl_errno_' . $errno;
    }

    public static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * self::MS_PER_SECOND);
    }

    /**
     * An audit write must never break the provider call it describes, so a failure is
     * reported through the host app's report() when it has one and never rethrown.
     */
    public static function reportQuietly(\Throwable $e): void
    {
        try {
            if (function_exists('report')) {
                report($e);
            }
        } catch (\Throwable $ignored) {
        }
    }

    private function sourceHost(): string
    {
        if ($this->sourceHost === null) {
            $this->sourceHost = $this->host->instanceId() ?? $this->host->hostname();
        }

        return $this->sourceHost;
    }
}
