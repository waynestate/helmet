<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Policy;

/**
 * Thrown when a policy file cannot be read or understood.
 *
 * This is a RuntimeException so that code which must not throw at request
 * time (the version warning) can catch it specifically, without swallowing
 * unrelated errors.
 */
final class PolicyReadException extends \RuntimeException {}
