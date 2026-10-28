<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Headers;

use InvalidArgumentException;

/**
 * The configured CSP report URI is not a valid report destination.
 *
 * A report URI must be either an absolute https:// URL with a host, or a
 * path relative to the page's origin that starts with a single slash.
 * Anything else is a configuration error: a report endpoint the browser
 * cannot reach is worse than none, and a malformed value can break the
 * Reporting-Endpoints header or inject a second header line.
 */
final class InvalidCspReportUriException extends InvalidArgumentException
{
    public function __construct(string $uri)
    {
        parent::__construct(sprintf(
            'Invalid CSP report URI "%s". It must be an absolute https:// URL with a host, or a path starting with a single slash.',
            $uri,
        ));
    }
}
