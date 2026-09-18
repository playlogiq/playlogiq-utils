<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Status;

/**
 * Result of a single component probe (MySQL, Redis, the queue, …).
 *
 * FAILED means the component did not answer at all; DEGRADED means it answered
 * but a threshold was breached (queue lag, low disk) — worth alerting on, never
 * worth taking the instance out of the load balancer for.
 */
class ComponentStatus
{
    public const OK = 'ok';
    public const DEGRADED = 'degraded';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    private string $name;
    private string $status;
    private bool $critical;
    private ?float $latencyMs;
    private array $details;
    private ?string $error;

    public function __construct(
        string $name,
        string $status,
        bool $critical = false,
        ?float $latencyMs = null,
        array $details = [],
        ?string $error = null
    ) {
        $this->name = $name;
        $this->status = $status;
        $this->critical = $critical;
        $this->latencyMs = $latencyMs;
        $this->details = $details;
        $this->error = $error;
    }

    public static function ok(string $name, ?float $latencyMs = null, array $details = []): self
    {
        return new self($name, self::OK, false, $latencyMs, $details);
    }

    public static function degraded(string $name, string $error, ?float $latencyMs = null, array $details = []): self
    {
        return new self($name, self::DEGRADED, false, $latencyMs, $details, $error);
    }

    public static function failed(string $name, string $error, ?float $latencyMs = null, array $details = []): self
    {
        return new self($name, self::FAILED, false, $latencyMs, $details, $error);
    }

    public static function skipped(string $name, string $reason, array $details = []): self
    {
        return new self($name, self::SKIPPED, false, null, $details, $reason);
    }

    public function withCritical(bool $critical): self
    {
        return new self($this->name, $this->status, $critical, $this->latencyMs, $this->details, $this->error);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isCritical(): bool
    {
        return $this->critical;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function isHealthy(): bool
    {
        return $this->status === self::OK || $this->status === self::SKIPPED;
    }

    public function toArray(bool $withDetails): array
    {
        $payload = [
            'status' => $this->status,
            'critical' => $this->critical,
            'latency_ms' => $this->latencyMs,
        ];

        if (! $withDetails) {
            return $payload;
        }

        $payload['details'] = $this->details;
        $payload['error'] = $this->error;

        return $payload;
    }
}
