<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Support;

/**
 * Resolves one HELMET setting from outside the package.
 *
 * The real environment wins (getenv, then $_ENV, then $_SERVER). When the
 * environment has no value for the key, the private waynestate/playbook
 * package supplies it, if it is installed. When neither has it, null comes
 * back and the caller applies its own default.
 *
 * Playbook is optional by design: the package never requires it, it only
 * looks for the class. That is how a WSU value can arrive at an app without
 * this public repo naming a single one of them.
 */
final class Setting
{
    /**
     * The value of $key: the environment first, then Playbook, then null.
     *
     * An empty string counts as "not set" (the same as an empty .env line),
     * so a blank value in the environment falls through to Playbook.
     */
    public static function get(string $key): ?string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (class_exists(\Waynestate\Playbook\Playbook::class)) {
            return \Waynestate\Playbook\Playbook::get($key);
        }

        return null;
    }
}
