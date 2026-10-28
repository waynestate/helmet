<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Ribbon;

use DateTimeImmutable;
use DateTimeInterface;
use Waynestate\Helmet\Policy\JsonFilePolicySource;
use Waynestate\Helmet\Policy\PolicyReadException;
use Waynestate\Helmet\Policy\VersionWarning;

/**
 * An environment ribbon: a small visual indicator that shows which
 * environment an app is running in (for example "STAGING" or "DEVELOPMENT").
 *
 * The ribbon is meant to be injected near the top of the page so that a
 * developer or operator can see at a glance that they are not looking at
 * production. It is disabled by default in production and enabled in every
 * other environment.
 *
 * The environment name is escaped to prevent XSS.
 *
 * When a policy file is configured, the ribbon also shows a warning line for
 * each component version that is in its security-only phase or end of life,
 * from CREED's policy.json. The warning is produced by VersionWarning; the
 * date is injected so the result is testable. A missing, unreadable or
 * invalid policy file produces no warning and never breaks the page.
 */
final class Ribbon
{
    private readonly ?VersionWarning $versionWarning;

    /**
     * @param string $environment The environment name (for example "STAGING").
     * @param bool $enabled Whether the ribbon should be shown.
     * @param string|null $policyPath The path to a CREED policy.json, or null
     *        for no version warnings. When null or empty, no file is read.
     * @param list<array{0: string, 1: string}> $components The [component,
     *        version] pairs to check against the policy (for example
     *        ['php', '8.3']). Ignored when no policy path is set.
     * @param DateTimeInterface|null $now The date to evaluate the policy
     *        against. Defaults to the current date; inject a fixed date in
     *        tests.
     */
    public function __construct(
        private readonly string $environment,
        private readonly bool $enabled = true,
        ?string $policyPath = null,
        private readonly array $components = [],
        ?DateTimeInterface $now = null,
    ) {
        $this->versionWarning = $this->buildVersionWarning($policyPath, $now);
    }

    /**
     * Whether the ribbon should be shown.
     */
    public function shouldShow(): bool
    {
        return $this->enabled;
    }

    /**
     * The environment name.
     */
    public function environment(): string
    {
        return $this->environment;
    }

    /**
     * The HTML for the ribbon, or an empty string if it should not be shown.
     *
     * The environment name and any warning messages are escaped to prevent
     * XSS.
     */
    public function html(): string
    {
        if (!$this->shouldShow()) {
            return '';
        }

        $label = htmlspecialchars($this->environment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div class="helmet-ribbon" data-environment="' . $label . '">' . $label;

        foreach ($this->warnings() as $warning) {
            $escaped = htmlspecialchars($warning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<div class="helmet-ribbon-warning">' . $escaped . '</div>';
        }

        return $html . '</div>';
    }

    /**
     * The warning messages for the configured components, or an empty list.
     *
     * @return list<string>
     */
    private function warnings(): array
    {
        if ($this->versionWarning === null) {
            return [];
        }

        $warnings = [];
        foreach ($this->components as [$component, $version]) {
            $warning = $this->versionWarning->warning($component, $version);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * Build a VersionWarning from the policy path, or null when no policy is
     * configured or the policy file cannot be read.
     *
     * A missing, unreadable or invalid policy file must never break the page,
     * so PolicyReadException is caught and treated as "no warning". When no
     * policy path is set, no file is read at all.
     */
    private function buildVersionWarning(?string $policyPath, ?DateTimeInterface $now): ?VersionWarning
    {
        if ($policyPath === null || $policyPath === '') {
            return null;
        }

        try {
            $source = new JsonFilePolicySource($policyPath);
        } catch (PolicyReadException) {
            return null;
        }

        return new VersionWarning($source, $now ?? new DateTimeImmutable('now'));
    }
}
