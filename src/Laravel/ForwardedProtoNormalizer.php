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
 * a trusted proxy (the helmet.proxies list) and the header carries more
 * than one value, the header is rewritten to the last value before any
 * code reads it.
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
        /** @var list<string> $proxyList */
        $proxyList = (array) config('helmet.proxies');
        $proxies = new TrustedProxies($proxyList);

        if ($proxies->isEmpty()) {
            return $next($request);
        }

        $remoteAddr = $request->server->get('REMOTE_ADDR');
        if (!is_string($remoteAddr) || $remoteAddr === '' || !$proxies->isTrusted($remoteAddr)) {
            return $next($request);
        }

        $this->normalizeProto($request);

        return $next($request);
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
            if (!is_string($line)) {
                continue;
            }
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
