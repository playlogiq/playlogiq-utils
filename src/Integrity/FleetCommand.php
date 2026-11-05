<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Aws\Ssm\SsmClient;
use RuntimeException;

/**
 * GLI-33 §2.3 — sends a control-program check to every instance of the fleet.
 *
 * One SSM Run Command of the configured document, targeted by auto-scaling group tag, so
 * the instances it reaches are the ones the group holds at that moment — including any
 * that scaled in since the last check, and none that have already gone. Each instance
 * runs `integrity:verify --trigger=<trigger> --check=<id>` and records its own row under
 * the check; nothing here waits for them.
 *
 * Its own class so the job can be tested without AWS: the container hands the job
 * whatever is bound here.
 */
class FleetCommand
{
    /** @var array<string, mixed> */
    private $config;

    /**
     * @param array<string, mixed>|null $config critical_components.fleet when null
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('critical_components.fleet');
    }

    /**
     * Send the check to the fleet and return the SSM CommandId.
     *
     * @throws RuntimeException when there is no fleet configured to send it to
     * @throws \Aws\Exception\AwsException when SSM refuses it
     */
    public function send(string $trigger, int $checkId): string
    {
        $groups = array_values(array_filter((array) ($this->config['autoscaling_groups'] ?? [])));

        if ($groups === []) {
            throw new RuntimeException('No auto-scaling groups configured (INTEGRITY_FLEET_ASGS).');
        }

        $client = new SsmClient([
            'version' => 'latest',
            'region' => (string) $this->config['region'],
        ]);

        $result = $client->sendCommand([
            'DocumentName' => (string) $this->config['document'],
            'Parameters' => [
                'trigger' => [$trigger],
                // SSM parameter names are alphanumeric only; the document maps it to --check.
                'checkId' => [(string) $checkId],
            ],
            'Targets' => [
                [
                    'Key' => 'tag:aws:autoscaling:groupName',
                    'Values' => $groups,
                ],
            ],
            'Comment' => 'GLI-33 2.3 app integrity check #' . $checkId,
        ]);

        return (string) $result['Command']['CommandId'];
    }
}
