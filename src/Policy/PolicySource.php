<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Policy;

/**
 * A source of version policy data.
 *
 * The policy describes, for each component (such as "php" or "laravel"), the
 * support dates of each version. HELMET uses it to warn when the runtime the
 * app is running on is past active support.
 */
interface PolicySource
{
    /**
     * The components in the policy.
     *
     * @return array<string, array<string, array{active_until: string, security_until: string}>>
     *   Component name to version to support dates. Dates are ISO-8601
     *   calendar dates (YYYY-MM-DD).
     */
    public function components(): array;
}
