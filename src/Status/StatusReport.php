<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Status;

/**
 * The aggregate of every component probe plus the HTTP verdict derived from it.
 */
class StatusReport
{
    public const OK = 'ok';
    public const DEGRADED = 'degraded';
    public const UNHEALTHY = 'unhealthy';

    /** @var array<string, ComponentStatus> keyed by component name */
    private array $components;
    private array $app;
    private float $durationMs;

    /**
     * @param array<string, ComponentStatus> $components
     */
    public function __construct(array $components, array $app, float $durationMs)
    {
        $this->components = $components;
        $this->app = $app;
        $this->durationMs = $durationMs;
    }

    /**
     * ok        — every component answered and no threshold was breached.
     * degraded  — something is wrong, but nothing the app cannot serve without.
     * unhealthy — a component marked critical in config/status.php is down.
     */
    public function overallStatus(): string
    {
        foreach ($this->components as $component) {
            if ($component->isCritical() && $component->hasFailed()) {
                return self::UNHEALTHY;
            }
        }

        foreach ($this->components as $component) {
            if (! $component->isHealthy()) {
                return self::DEGRADED;
            }
        }

        return self::OK;
    }

    public function httpStatus(): int
    {
        return $this->overallStatus() === self::UNHEALTHY ? 503 : 200;
    }

    /**
     * @return string[] names of the components that failed outright
     */
    public function failedComponents(): array
    {
        $failed = [];

        foreach ($this->components as $name => $component) {
            if ($component->hasFailed()) {
                $failed[] = $name;
            }
        }

        return $failed;
    }

    /**
     * @return string[] names of the components that answered but breached a
     *                  threshold or policy — reported, never fatal
     */
    public function degradedComponents(): array
    {
        $degraded = [];

        foreach ($this->components as $name => $component) {
            if ($component->status() === ComponentStatus::DEGRADED) {
                $degraded[] = $name;
            }
        }

        return $degraded;
    }

    public function toArray(bool $withDetails): array
    {
        $components = [];

        foreach ($this->components as $name => $component) {
            $components[$name] = $component->toArray($withDetails);
        }

        return [
            'status' => $this->overallStatus(),
            'app' => $this->app,
            'duration_ms' => round($this->durationMs, 2),
            'cached' => false,
            'cache_age_seconds' => 0,
            'failed' => $this->failedComponents(),
            'warnings' => $this->degradedComponents(),
            'components' => $components,
        ];
    }
}
