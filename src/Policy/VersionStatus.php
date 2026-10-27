<?php declare(strict_types=1);

namespace Waynestate\Helmet\Policy;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Determines the support status of a component version on a given date.
 *
 * The date is injected so the result is a pure function of the policy, the
 * component, the version, and the date. That makes the boundaries testable
 * without waiting for real support windows to close.
 *
 * A version is:
 *  - "active"   on or before its active_until date,
 *  - "security" after active_until and on or before its security_until date,
 *  - "eol"      after security_until,
 *  - "unknown"  when the component or version is not in the policy.
 */
final class VersionStatus
{
    public const ACTIVE = 'active';
    public const SECURITY = 'security';
    public const EOL = 'eol';
    public const UNKNOWN = 'unknown';

    /**
     * @param array<string, array<string, array{active_until: string, security_until: string}>> $components
     */
    public function __construct(
        private readonly DateTimeInterface $now,
        private readonly array $components
    ) {
    }

    /**
     * The status of the given component version on the injected date.
     */
    public function status(string $component, string $version): string
    {
        if (!isset($this->components[$component][$version])) {
            return self::UNKNOWN;
        }

        $dates = $this->components[$component][$version];
        $activeUntil = $this->parseDate($dates['active_until']);
        $securityUntil = $this->parseDate($dates['security_until']);

        if ($this->now <= $activeUntil) {
            return self::ACTIVE;
        }

        if ($this->now <= $securityUntil) {
            return self::SECURITY;
        }

        return self::EOL;
    }

    private function parseDate(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }
}
