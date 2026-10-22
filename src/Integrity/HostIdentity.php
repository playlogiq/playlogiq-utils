<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

/**
 * Names the machine a verification result belongs to.
 *
 * A fleet-wide check is only actionable if every result says which instance produced
 * it. "CONTROL PROGRAM MISMATCH" with no host is an alert nobody can act on when the
 * auto-scaling group holds a dozen instances and one of them is wrong.
 *
 * The EC2 instance id is preferred because it is the identifier the console, the ASG and
 * every runbook use. It is fetched from the instance metadata service using IMDSv2 (the
 * token-then-read handshake; IMDSv1's bare GET is disabled on hardened instances and
 * would silently return nothing).
 *
 * Everything about that lookup is best-effort by design. Metadata is unreachable in a
 * container on a laptop, in CI, and in a test, and none of those are failures — the
 * hostname alone is a perfectly good answer there. So a missed lookup degrades to
 * gethostname() rather than throwing: an integrity check must never fail because it
 * could not decide what to call itself.
 *
 * The result is memoised per instance of this class, so a caller that logs the host
 * several times in one run pays for at most one metadata round trip.
 */
class HostIdentity
{
    /** IMDS lives on a link-local address that is not routed anywhere. */
    const IMDS_TOKEN_URL       = 'http://169.254.169.254/latest/api/token';
    const IMDS_INSTANCE_ID_URL = 'http://169.254.169.254/latest/meta-data/instance-id';

    /**
     * Deliberately short. Off EC2 the address is simply unreachable and the connection
     * fails immediately, but a dropped-packet firewall makes it hang instead, and this
     * runs inside a scheduled check that should not sit waiting on a nicety.
     */
    const TIMEOUT_SECONDS = 1;

    /** Token lifetime; only one request is made with it. */
    const TOKEN_TTL_SECONDS = 60;

    /** @var string|null */
    private $hostname;

    /** @var string|null */
    private $instanceId;

    /** @var bool */
    private $instanceIdResolved = false;

    /**
     * How this host should be named in a log line.
     *
     * Both identifiers when both are known: the instance id is what you act on, the
     * hostname is what appears in every other log the box writes, and correlating the
     * two by hand at 3am is exactly the work a log line should have already done.
     */
    public function describe(): string
    {
        $instanceId = $this->instanceId();
        $hostname   = $this->hostname();

        return $instanceId === null ? $hostname : $instanceId . ' (' . $hostname . ')';
    }

    public function hostname(): string
    {
        if ($this->hostname === null) {
            $hostname = gethostname();
            $this->hostname = ($hostname === false || $hostname === '') ? 'unknown-host' : $hostname;
        }

        return $this->hostname;
    }

    /**
     * The EC2 instance id, or null anywhere that is not an EC2 instance.
     */
    public function instanceId(): ?string
    {
        if ($this->instanceIdResolved) {
            return $this->instanceId;
        }

        $this->instanceIdResolved = true;
        $this->instanceId         = null;

        $token = $this->request(self::IMDS_TOKEN_URL, 'PUT', [
            'X-aws-ec2-metadata-token-ttl-seconds: ' . self::TOKEN_TTL_SECONDS,
        ]);

        if ($token === null) {
            return null;
        }

        $id = $this->request(self::IMDS_INSTANCE_ID_URL, 'GET', [
            'X-aws-ec2-metadata-token: ' . $token,
        ]);

        if ($id === null) {
            return null;
        }

        // Guard against a captive portal or proxy answering on that address with
        // something that is not an instance id at all.
        if (preg_match('/^i-[0-9a-f]{8,}$/', $id) !== 1) {
            return null;
        }

        $this->instanceId = $id;

        return $this->instanceId;
    }

    /**
     * One metadata request. Returns null on anything other than a plain success.
     *
     * Overridable so tests can exercise describe() without a network, and so a future
     * caller can stub the metadata service rather than reaching for a real one.
     *
     * @param string[] $headers
     */
    protected function request(string $url, string $method, array $headers): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'timeout'       => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                // No redirect should ever come back from IMDS; following one would mean
                // reading whatever answered instead of it.
                'max_redirects' => 0,
            ],
            'socket' => [
                'bindto' => '0:0',
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return null;
        }

        // ignore_errors above means a 401 or 404 body arrives as content, so the status
        // line has to be checked rather than assumed.
        if (!$this->succeeded($http_response_header ?? [])) {
            return null;
        }

        $body = trim($body);

        return $body === '' ? null : $body;
    }

    /**
     * @param string[] $responseHeaders
     */
    private function succeeded(array $responseHeaders): bool
    {
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'HTTP/') === 0) {
                return strpos($header, ' 200') !== false;
            }
        }

        return false;
    }
}
