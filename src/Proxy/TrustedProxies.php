<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Proxy;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Matches a client or peer IP address against a list of trusted proxies.
 *
 * The list may contain exact IPv4 or IPv6 addresses and IPv4 or IPv6 CIDR
 * ranges. Every entry is validated at construction time: an entry that is not
 * a valid address or CIDR throws, a /0 prefix (which would trust every
 * address of its family) is refused, and the wildcard "*" is refused. A
 * silently skipped entry would look like working config while trusting
 * nothing, or the wrong thing.
 *
 * The matching itself is delegated to Symfony's IpUtils (symfony/http-
 * foundation), which is maintained security code; this class only validates
 * the config so that bad config fails loudly at boot.
 */
final class TrustedProxies
{
    /**
     * The validated proxy entries, exactly as configured.
     *
     * @var list<string>
     */
    private array $proxies;

    /**
     * @param list<string> $proxies Exact IPv4/IPv6 addresses and/or IPv4/IPv6
     *                               CIDR ranges. Every entry must be a
     *                               non-empty string; anything else throws.
     *
     * @throws \InvalidArgumentException If an entry is not a valid address or
     *                                   CIDR, is a /0 range, or is "*".
     */
    public function __construct(array $proxies = [])
    {
        // The docblock promises list<string>, but a caller can still pass a
        // non-string at runtime; validate each entry before trusting it.
        /** @var list<mixed> $entries */
        $entries = $proxies;
        $this->proxies = [];
        foreach ($entries as $proxy) {
            if (!is_string($proxy) || $proxy === '') {
                throw new \InvalidArgumentException(
                    'Trusted proxy entries must be non-empty strings; got '
                    . get_debug_type($proxy) . '.',
                );
            }

            if ($proxy === '*') {
                throw new \InvalidArgumentException(
                    'The "*" entry trusts every peer and is not allowed.',
                );
            }

            if (str_contains($proxy, '/')) {
                $this->validateCidr($proxy);
            } elseif (filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException(
                    "Not a valid IPv4 or IPv6 address: \"$proxy\".",
                );
            }

            $this->proxies[] = $proxy;
        }
    }

    /**
     * Whether the given IP address is one of the trusted proxies.
     *
     * An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is unmapped to a.b.c.d
     * before matching, so a dual-stack peer presenting its IPv4 address in
     * mapped form still matches an IPv4 entry in the list.
     */
    public function isTrusted(string $ip): bool
    {
        $ip = self::unmapIpv4MappedIpv6($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return IpUtils::checkIp($ip, $this->proxies);
    }

    /**
     * Turn an IPv4-mapped IPv6 address into its IPv4 form.
     *
     * Only the exact mapped form "::ffff:a.b.c.d" is unmapped; every other
     * string is returned unchanged. Shared with ClientIp so that peers and
     * forwarded entries are compared against the list in the same form.
     */
    public static function unmapIpv4MappedIpv6(string $ip): string
    {
        if (str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return substr($ip, 7);
        }

        return $ip;
    }

    /**
     * Whether no proxy is trusted. An empty list trusts nothing.
     */
    public function isEmpty(): bool
    {
        return $this->proxies === [];
    }

    /**
     * Validate a CIDR entry: the subnet must be a valid address and the
     * prefix an integer from 1 to 32 (IPv4) or 1 to 128 (IPv6).
     *
     * @throws \InvalidArgumentException If the subnet is not a valid address
     *                                   or the prefix length is out of range
     *                                   for the address family. A /0 prefix
     *                                   is refused: it trusts every address.
     */
    private function validateCidr(string $cidr): void
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        if (filter_var($subnet, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException("Invalid subnet address in \"$cidr\".");
        }

        $prefixLen = filter_var($bits, FILTER_VALIDATE_INT);
        if ($prefixLen === false) {
            throw new \InvalidArgumentException("Invalid CIDR prefix length in \"$cidr\".");
        }

        // A /0 range covers every address of the family.
        if ($prefixLen === 0) {
            throw new \InvalidArgumentException(
                "A /0 prefix trusts every address and is not allowed in \"$cidr\".",
            );
        }

        $maxPrefix = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
        if ($prefixLen < 1 || $prefixLen > $maxPrefix) {
            throw new \InvalidArgumentException("Invalid CIDR prefix length in \"$cidr\".");
        }
    }
}
