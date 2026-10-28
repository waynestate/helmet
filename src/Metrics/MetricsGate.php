<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Metrics;

use Waynestate\Helmet\Proxy\IpAllowList;

/**
 * Decides whether a request may read the metrics endpoint.
 *
 * The decision is fail-closed:
 *  - disabled            -> 404 (the route is not advertised),
 *  - enabled but neither a token nor an allow list is configured -> 404 plus
 *    a warning (a misconfiguration must not serve metrics),
 *  - a correct bearer token, or an allowed client IP -> 200,
 *  - anything else -> 403.
 *
 * The token is compared with hash_equals (constant time). The client IP is
 * the one resolved by ClientIp, which already honors the trusted-proxy list,
 * so a spoofed X-Forwarded-For from an untrusted peer cannot grant access.
 */
final class MetricsGate
{
    private readonly ?string $token;

    /**
     * @param bool $enabled  Whether the metrics endpoint is on.
     * @param string|null $token  The bearer token, or null/empty for none.
     * @param IpAllowList $allow  The client IPs/CIDRs allowed to read metrics.
     */
    public function __construct(
        private readonly bool $enabled,
        ?string $token,
        private readonly IpAllowList $allow,
    ) {
        // An empty token is the same as no token.
        $this->token = ($token === null || $token === '') ? null : $token;
    }

    /**
     * Decide the outcome for a request.
     *
     * @param string|null $requestToken  The bearer token the request
     *        presented, or null.
     * @param string $clientIp  The resolved client IP, or an empty string.
     */
    public function decide(?string $requestToken, string $clientIp): MetricsDecision
    {
        if (!$this->enabled) {
            return MetricsDecision::disabled();
        }

        $hasToken = $this->token !== null;
        if (!$hasToken && $this->allow->isEmpty()) {
            return MetricsDecision::misconfigured();
        }

        $tokenOk = $this->token !== null && $requestToken !== null && hash_equals($this->token, $requestToken);
        $ipOk = $this->allow->allows($clientIp);

        return ($tokenOk || $ipOk) ? MetricsDecision::allowed() : MetricsDecision::denied();
    }
}
