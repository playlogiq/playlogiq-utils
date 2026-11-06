<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ProviderAuth;

use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\Integrity\HostIdentity;
use PlaylogiqUtils\ProviderAuth\ProviderAuthLogger;
use Psr\Log\AbstractLogger;

/**
 * Plain PHPUnit with a collecting PSR-3 logger and a host lookup that never touches
 * the metadata service, so every classification branch runs without an application.
 */
class ProviderAuthLoggerTest extends TestCase
{
    private const URL = 'https://api.acme.test/v1/auth/token?client_secret=hidden';
    private const APP = 'TestApp';
    private const DURATION_MS = 12;

    /** @var AbstractLogger */
    private $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new class extends AbstractLogger {
            /** @var array<int, array> */
            public $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
    }

    private function logger(?AbstractLogger $log = null): ProviderAuthLogger
    {
        return new ProviderAuthLogger($log ?? $this->log, self::APP, new class extends HostIdentity {
            protected function request(string $url, string $method, array $headers): ?string
            {
                return null;
            }
        });
    }

    private function onlyLine(): array
    {
        $this->assertCount(1, $this->log->records);
        [$level, $message, $line] = $this->log->records[0];
        $this->assertSame('info', $level);
        $this->assertSame(ProviderAuthLogger::MESSAGE, $message);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $line['timestamp']);
        $this->assertSame(gethostname(), $line['source_host']);

        return $line;
    }

    private function event(bool $isLogin, ?int $status, ?string $error, array $context = [], ?callable $gate = null): void
    {
        $this->logger()->recordIfAuthEvent('acme', 'post', self::URL, $isLogin, $status, $error, self::DURATION_MS, $context, $gate);
    }

    public function test_login_success_has_the_exact_fields_in_order(): void
    {
        $this->event(true, 200, null, ['skin_id' => 7]);

        $line = $this->onlyLine();
        $this->assertSame(
            ['timestamp', 'app', 'source_host', 'skin_id', 'provider', 'method', 'provider_host', 'endpoint', 'status', 'outcome', 'duration_ms'],
            array_keys($line)
        );
        $this->assertSame(self::APP, $line['app']);
        $this->assertSame(7, $line['skin_id']);
        $this->assertSame('acme', $line['provider']);
        $this->assertSame('POST', $line['method']);
        $this->assertSame('api.acme.test', $line['provider_host']);
        $this->assertSame('/v1/auth/token', $line['endpoint']);
        $this->assertSame(200, $line['status']);
        $this->assertSame('success', $line['outcome']);
        $this->assertSame(self::DURATION_MS, $line['duration_ms']);
    }

    public function test_query_string_never_reaches_the_log(): void
    {
        $this->event(true, 200, null);

        $this->assertStringNotContainsString('client_secret', serialize($this->log->records));
    }

    public function test_context_is_merged_after_source_host(): void
    {
        $this->event(false, 401, null, ['skin_id' => null, 'tenant' => 'x']);

        $this->assertSame(
            ['timestamp', 'app', 'source_host', 'skin_id', 'tenant', 'provider', 'method', 'provider_host', 'endpoint', 'status', 'outcome', 'duration_ms', 'error'],
            array_keys($this->onlyLine())
        );
    }

    /**
     * @dataProvider events
     */
    public function test_classification(bool $isLogin, ?int $status, ?string $error, string $outcome, ?string $logged): void
    {
        $this->event($isLogin, $status, $error);

        $line = $this->onlyLine();
        $this->assertSame($outcome, $line['outcome']);
        $this->assertSame($status, $line['status']);
        if ($logged === null) {
            $this->assertArrayNotHasKey('error', $line);
        } else {
            $this->assertSame($logged, $line['error']);
        }
    }

    public function events(): array
    {
        return [
            'login success'           => [true, 200, null, 'success', null],
            'login failure with code' => [true, 200, 'BAD_KEY', 'failure', 'BAD_KEY'],
            'login failure HTTP'      => [true, 500, null, 'failure', 'HTTP 500'],
            'login no response'       => [true, null, 'timeout', 'failure', 'timeout'],
            'login no response bare'  => [true, null, null, 'failure', 'no_response'],
            'call 401'                => [false, 401, null, 'failure', 'HTTP 401'],
            'call 403'                => [false, 403, null, 'failure', 'HTTP 403'],
            'call credential code'    => [false, 200, 'BAD_KEY', 'failure', 'BAD_KEY'],
        ];
    }

    /**
     * @dataProvider nonEvents
     */
    public function test_non_events_write_nothing(?int $status, ?string $error): void
    {
        $gateCalls = 0;
        $this->event(false, $status, $error, [], function () use (&$gateCalls) {
            $gateCalls++;

            return true;
        });

        $this->assertSame([], $this->log->records);
        $this->assertSame(0, $gateCalls);
    }

    public function nonEvents(): array
    {
        return [
            'call 200'         => [200, null],
            'call 500'         => [500, null],
            'call no response' => [null, 'timeout'],
        ];
    }

    public function test_error_is_cut_to_the_max_length(): void
    {
        $this->event(false, 401, str_repeat('x', ProviderAuthLogger::ERROR_MAX_LENGTH + 100));

        $this->assertSame(ProviderAuthLogger::ERROR_MAX_LENGTH, strlen($this->onlyLine()['error']));
    }

    public function test_gate_false_writes_nothing(): void
    {
        $this->event(true, 200, null, [], function () {
            return false;
        });

        $this->assertSame([], $this->log->records);
    }

    public function test_gate_true_writes_the_line(): void
    {
        $this->event(true, 200, null, [], function () {
            return true;
        });

        $this->onlyLine();
    }

    public function test_a_throwing_logger_never_reaches_the_caller(): void
    {
        $logger = $this->logger(new class extends AbstractLogger {
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('disk full');
            }
        });

        $logger->record('acme', 'post', self::URL, 401, false, 'HTTP 401', self::DURATION_MS, [], function () {
            return true;
        });

        $this->addToAssertionCount(1);
    }

    public function test_a_throwing_gate_never_reaches_the_caller(): void
    {
        $this->event(true, 200, null, [], function () {
            throw new \RuntimeException('no database');
        });

        $this->assertSame([], $this->log->records);
    }

    public function test_curl_errno_maps_to_error_type(): void
    {
        $this->assertSame('dns', ProviderAuthLogger::curlErrorType(ProviderAuthLogger::CURLE_COULDNT_RESOLVE_HOST));
        $this->assertSame('connect', ProviderAuthLogger::curlErrorType(ProviderAuthLogger::CURLE_COULDNT_CONNECT));
        $this->assertSame('timeout', ProviderAuthLogger::curlErrorType(ProviderAuthLogger::CURLE_OPERATION_TIMEDOUT));
        foreach ([ProviderAuthLogger::CURLE_SSL_CONNECT_ERROR, ProviderAuthLogger::CURLE_PEER_FAILED_VERIFICATION, ProviderAuthLogger::CURLE_SSL_CERTPROBLEM, ProviderAuthLogger::CURLE_SSL_CACERT] as $errno) {
            $this->assertSame('tls', ProviderAuthLogger::curlErrorType($errno));
        }
        $this->assertSame('curl_errno_52', ProviderAuthLogger::curlErrorType(52));
    }

    public function test_success_status_range(): void
    {
        $this->assertTrue(ProviderAuthLogger::isSuccessStatus(ProviderAuthLogger::HTTP_OK_MIN));
        $this->assertTrue(ProviderAuthLogger::isSuccessStatus(ProviderAuthLogger::HTTP_OK_MAX));
        $this->assertFalse(ProviderAuthLogger::isSuccessStatus(ProviderAuthLogger::HTTP_OK_MAX + 1));
    }
}
