<?php declare(strict_types=1);

namespace Waynestate\Helmet\Policy;

use DateTimeInterface;

/**
 * Produces a human-readable warning when a component version is not fully
 * supported.
 *
 * A warning is produced when the version is in its security-only phase or is
 * end of life. No warning is produced when the version is active or unknown.
 *
 * The date is injected so the result is a pure function of the policy, the
 * component, the version, and the date. That makes the boundaries testable
 * without waiting for real support windows to close.
 */
final class VersionWarning
{
    public function __construct(
        private readonly PolicySource $source,
        private readonly DateTimeInterface $now
    ) {
    }

    /**
     * A warning message for the given component version, or null if no warning
     * is needed.
     */
    public function warning(string $component, string $version): ?string
    {
        $status = (new VersionStatus($this->now, $this->source->components()))
            ->status($component, $version);

        return match ($status) {
            VersionStatus::SECURITY => sprintf(
                '%s %s is in its security-only phase and will not receive new features. Upgrade to a supported version.',
                ucfirst($component),
                $version
            ),
            VersionStatus::EOL => sprintf(
                '%s %s is end of life and no longer receives security updates. Upgrade to a supported version.',
                ucfirst($component),
                $version
            ),
            default => null,
        };
    }
}
