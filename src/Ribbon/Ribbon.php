<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Ribbon;

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
 */
final class Ribbon
{
    public function __construct(
        private readonly string $environment,
        private readonly bool $enabled = true,
    ) {}

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
     * The environment name is escaped to prevent XSS.
     */
    public function html(): string
    {
        if (!$this->shouldShow()) {
            return '';
        }

        $label = htmlspecialchars($this->environment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="helmet-ribbon" data-environment="' . $label . '">' . $label . '</div>';
    }
}
