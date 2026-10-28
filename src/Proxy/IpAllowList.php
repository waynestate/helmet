<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Proxy;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Matches a client IP address against an allow list.
 *
 * The list may contain exact IPv4 or IPv6 addresses and IPv4 or IPv6 CIDR
 * ranges. Every entry is validated at construction time, exactly like
 * TrustedProxies: an entry that is not a valid address or CIDR throws, a /0
 * prefix (which would allow every address of its family) is refused, and the
 * wildcard "*" is refused. A silently skipped entry would look like working
 * config while allowing nothing, or the wrong thing.
 *
 * The matching itself is delegated to Symfony's IpUtils (symfony/http-
 * foundation), which is maintained security code; this class only validates
 * the config so that bad config fails loudly.
 */
final class IpAllowList
{
    /**
     * The validated allow-list entries, exactly as configured.
     *
     * @var list<string>
     */
    private array $entries;

    /**
     * @param list<string> $entries Exact IPv4/IPv6 addresses and/or IPv4/IPv6
     *                               CIDR ranges. Every entry must be a
     *                               non-empty string; anything else throws.
     *
     * @throws \InvalidArgumentException If an entry is not a valid address or
     *                                   CIDR, is a /0 range, or is "*".
     */
    public function __construct(array $entries = [])
    {
        // The docblock promises list<string>, but a caller can still pass a
        // non-string at runtime; validate each entry before trusting it.
        /** @var list<mixed> $raw */
        $raw = $entries;
        $this->entries = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \InvalidArgumentException(
                    'Metrics allow-list entries must be non-empty strings; got '
                    . get_debug_type($entry) . '.',
                );
            }

            if ($entry === '*') {
                throw new \InvalidArgumentException(
                    'The "*" entry allows every client and is not allowed.',
                );
            }

            if (str_contains($entry, '/')) {
                $this->validateCidr($entry);
            } elseif (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException(
                    "Not a valid IPv4 or IPv6 address: \"$entry\".",
                );
            }

            $this->entries[] = $entry;
        }
    }

    /**
     * Whether the given IP address is allowed.
     */
    public function allows(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return IpUtils::checkIp($ip, $this->entries);
    }

    /**
     * Whether no address is allowed. An empty list allows nothing.
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Validate a CIDR entry: the subnet must be a valid address and the
     * prefix an integer from 1 to 32 (IPv4) or 1 to 128 (IPv6).
     *
     * @throws \InvalidArgumentException If the subnet is not a valid address
     *                                   or the prefix length is out of range
     *                                   for the address family. A /0 prefix
     *                                   is refused: it allows every address.
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
                "A /0 prefix allows every address and is not allowed in \"$cidr\".",
            );
        }

        $maxPrefix = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;
        if ($prefixLen < 1 || $prefixLen > $maxPrefix) {
            throw new \InvalidArgumentException("Invalid CIDR prefix length in \"$cidr\".");
        }
    }
}
