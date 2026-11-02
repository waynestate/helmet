<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * Normalizes X-Forwarded-Proto to the value the closest proxy appended.
 *
 * Citadel's HA-Proxy adds X-Forwarded-Proto (reqadd) instead of replacing
 * it, so a client can send its own line and arrive at the app with
 * "https, http" or "http, https". Symfony's Request::isSecure() reads the
 * first value of the list, which is the client's; HELMET's own detector
 * reads the last, which is the one the trusted proxy appended. This
 * middleware makes Laravel agree with HELMET: when the request comes from
 * a trusted proxy (the effective list: trustedproxy.proxies when set,
 * helmet.proxies otherwise, the same list Laravel trusts) and the header
 * carries more than one value, the header is rewritten to the last value
 * before any code reads it.
 *
 * The rewrite happens in the raw header and server bags. Symfony applies
 * the trusted-proxy rewrites lazily, when isSecure(), getPort() and the
 * like are called, so a single normalized value is what Laravel sees.
 * Requests from untrusted peers are left alone: their forwarded headers
 * are ignored by Symfony anyway.
 */
final class ForwardedProtoNormalizer
{
    public function handle(Request $request, Closure $next): Response
    {
        $remoteAddr = $request->server->get('REMOTE_ADDR');
        if (!is_string($remoteAddr) || $remoteAddr === '') {
            return $next($request);
        }

        if (!$this->peerIsTrusted($remoteAddr)) {
            return $next($request);
        }

        $this->normalizeProto($request);

        return $next($request);
    }

    /**
     * Whether the peer is in the trusted-proxy list Laravel will actually
     * trust.
     *
     * The provider copies helmet.proxies into trustedproxy.proxies only when
     * the app has not set the latter, so an app can make the two lists differ.
     * Laravel then trusts trustedproxy.proxies for isSecure() and friends, so
     * the normalizer must decide with the same effective list:
     * trustedproxy.proxies when it is set (not null), helmet.proxies
     * otherwise.
     *
     * The value may be an array of addresses or CIDR ranges, a comma
     * separated string of them, or "*" / "**" (Symfony: trust every peer).
     */
    private function peerIsTrusted(string $remoteAddr): bool
    {
        $configured = config('trustedproxy.proxies') ?? config('helmet.proxies');

        if (is_string($configured)) {
            $configured = trim($configured) === '' ? [] : explode(',', $configured);
        }
        if (!is_array($configured)) {
            return false;
        }

        $list = [];
        foreach ($configured as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === '*' || $entry === '**') {
                // Symfony trusts every peer for these values, so the normalizer
                // rewrites whatever header arrives.
                return true;
            }

            $list[] = $entry;
        }

        if ($list === []) {
            return false;
        }

        return (new TrustedProxies($list))->isTrusted($remoteAddr);
    }

    /**
     * Rewrite X-Forwarded-Proto to its last value when the header carries
     * more than one (a comma-separated list, several header lines, or a mix
     * of both). A single value is left untouched.
     */
    private function normalizeProto(Request $request): void
    {
        /** @var list<string> $lines */
        $lines = $request->headers->all()['x-forwarded-proto'] ?? [];

        $values = [];
        foreach ($lines as $line) {
            foreach (explode(',', $line) as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        if (count($values) < 2) {
            return;
        }

        $last = $values[count($values) - 1];
        $request->headers->set('X-Forwarded-Proto', $last);
        $request->server->set('HTTP_X_FORWARDED_PROTO', $last);
    }
}
