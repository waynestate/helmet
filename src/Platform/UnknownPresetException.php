<?php declare(strict_types=1);

namespace Waynestate\Helmet\Platform;

/**
 * Thrown when an app names a HELMET preset the package does not know.
 *
 * Failing at boot, with the known names in the message, beats failing
 * silently by trusting nothing (or the wrong thing) once traffic arrives.
 */
final class UnknownPresetException extends \InvalidArgumentException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf(
            'Unknown HELMET preset "%s". Known presets: %s.',
            $name,
            implode(', ', Presets::names())
        ));
    }
}
