<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Aws\CloudWatchLogs\CloudWatchLogsClient;

/**
 * GLI-33 — read access to the CloudWatch log group Session Manager streams operator
 * terminals to. One log stream per session; each event is a JSON document carrying the
 * IAM identity (`userIdentity.arn`), the `sessionId`, the instance (`target.id`) and about
 * a second of terminal output (`sessionData`).
 *
 * Credentials come from the AWS SDK default provider chain, that is, the instance profile
 * role. It needs logs:FilterLogEvents on the log group.
 *
 * Throws whatever the SDK throws; callers decide whether a failure is fatal.
 */
class SessionManagerLogs
{
    /** @var string */
    private $logGroup;

    /** @var string */
    private $region;

    /** @var array<string, int> */
    private $http;

    /** @var CloudWatchLogsClient|null */
    private $client;

    /**
     * @param array<string, mixed> $config the integrity.session_audit section
     * @param array<string, int>   $http   Guzzle timeouts: callers an operator waits on keep them short
     */
    public function __construct(
        array $config,
        ?CloudWatchLogsClient $client = null,
        array $http = ['connect_timeout' => 5, 'timeout' => 30]
    ) {
        $this->logGroup = trim((string) ($config['log_group'] ?? ''));
        $this->region   = trim((string) ($config['region'] ?? ''));
        $this->http     = $http;
        $this->client   = $client;
    }

    public static function fromConfig(array $http = ['connect_timeout' => 5, 'timeout' => 30]): self
    {
        return new self((array) config('integrity.session_audit'), null, $http);
    }

    public function isConfigured(): bool
    {
        return $this->logGroup !== '' && $this->region !== '';
    }

    public function logGroup(): string
    {
        return $this->logGroup;
    }

    public function region(): string
    {
        return $this->region;
    }

    /**
     * One page of events across every stream of the group matching $pattern.
     *
     * @return array<int, array<string, mixed>>
     */
    public function filterEvents(string $pattern, int $sinceMs, int $limit): array
    {
        $result = $this->client()->filterLogEvents([
            'logGroupName'  => $this->logGroup,
            'filterPattern' => $pattern,
            'startTime'     => $sinceMs,
            'limit'         => $limit,
        ]);

        return (array) ($result['events'] ?? []);
    }

    /**
     * Every event since $sinceMs, across every stream, matching $pattern.
     *
     * @return array<int, array<string, mixed>>
     */
    public function filterAllEvents(string $pattern, int $sinceMs): array
    {
        $events = [];
        $params = ['logGroupName' => $this->logGroup, 'filterPattern' => $pattern, 'startTime' => $sinceMs];

        do {
            $result = $this->client()->filterLogEvents($params);
            array_push($events, ...(array) ($result['events'] ?? []));
            $params['nextToken'] = $result['nextToken'] ?? null;
        } while ($params['nextToken'] !== null);

        return $events;
    }

    private function client(): CloudWatchLogsClient
    {
        if ($this->client === null) {
            $this->client = new CloudWatchLogsClient([
                'region'  => $this->region,
                'version' => 'latest',
                'http'    => $this->http,
            ]);
        }

        return $this->client;
    }
}
