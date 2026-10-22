<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

use Aws\Credentials\CredentialProvider;
use Aws\Sts\StsClient;

/**
 * Credentials for the snapshot bucket when it sits behind a role of its own.
 *
 * The default arrangement needs nothing from this class: the instance profile grants the
 * bucket directly, and the SDK's own provider chain finds it. That is still the preferred
 * shape, and leaving INTEGRITY_SNAPSHOTS_ROLE_ARN unset keeps it.
 *
 * It stops being enough the moment the bucket is granted to a role the instance is not.
 * The chain has no step for "assume something first" — it reaches the instance profile,
 * succeeds, and every PutObject is then made as the wrong principal. The failure is an
 * AccessDenied that reads like a missing permission on the intended role, while that role
 * was never involved.
 *
 * So the assumption is made explicit here and the resulting provider is handed to the S3
 * client for that one disk. Three details are deliberate:
 *
 * 1. THE STS CLIENT CARRIES NO CREDENTIALS. It resolves them through the default chain,
 *    which means the instance profile. The whole scheme rests on that: the only long-lived
 *    identity involved is the one AWS hands the machine, and nothing here holds a key.
 *
 * 2. REGIONAL STS ENDPOINT, NOT GLOBAL. Credentials minted at sts.amazonaws.com are
 *    rejected by opt-in regions unless the account has moved to version 2 session tokens,
 *    and eu-south-1 is opt-in. Asking for the regional endpoint sidesteps a failure that
 *    otherwise appears only in that region and reads as a permissions problem.
 *
 * 3. MEMOIZED. AssumeRole returns credentials with an expiry; without memoize() every call
 *    to S3 would mint a fresh set, which is a round trip per object and a CloudTrail entry
 *    per round trip. memoize() reuses them until they are close to expiring.
 *
 * The session name is the instance's hostname, so CloudTrail attributes every write to the
 * machine that made it — the same reason VerifyControlProgram keys its lock on the host.
 */
class SnapshotCredentials
{
    /** Prefix for the STS session name, so these sessions are identifiable in CloudTrail. */
    const SESSION_PREFIX = 'gli-snapshots-';

    /** STS caps RoleSessionName at 64 characters. */
    const MAX_SESSION_NAME = 64;

    /** @var string */
    private $roleArn;

    /** @var string */
    private $region;

    /** @var HostIdentity */
    private $host;

    public function __construct(string $roleArn, string $region, HostIdentity $host = null)
    {
        $this->roleArn = trim($roleArn);
        $this->region  = trim($region);
        $this->host    = $host ?: new HostIdentity();
    }

    /**
     * Null means "no role configured", which is not a failure: the caller then leaves the
     * disk alone and the SDK uses the instance profile, as it does today.
     *
     * @return callable|null
     */
    public function provider(): ?callable
    {
        if ($this->roleArn === '') {
            return null;
        }

        return CredentialProvider::memoize(
            CredentialProvider::assumeRole([
                'client'             => $this->stsClient(),
                'assume_role_params' => [
                    'RoleArn'         => $this->roleArn,
                    'RoleSessionName' => $this->sessionName(),
                ],
            ])
        );
    }

    /**
     * STS session names allow [\w+=,.@-] only, and a hostname can carry characters outside
     * that set. Substituting rather than dropping keeps two hosts from colliding on one name.
     */
    public function sessionName(): string
    {
        $host = (string) preg_replace('/[^\w+=,.@-]/', '-', $this->host->hostname());
        $name = self::SESSION_PREFIX . $host;

        return strlen($name) > self::MAX_SESSION_NAME
            ? substr($name, 0, self::MAX_SESSION_NAME)
            : $name;
    }

    private function stsClient(): StsClient
    {
        $config = [
            'version'                => 'latest',
            // Not global — see the class docblock.
            'sts_regional_endpoints' => 'regional',
        ];

        // Left to the SDK when unset rather than defaulted to something plausible: a wrong
        // region fails against another account's endpoint, which is a confusing way to
        // learn a variable is missing.
        if ($this->region !== '') {
            $config['region'] = $this->region;
        }

        return new StsClient($config);
    }
}
