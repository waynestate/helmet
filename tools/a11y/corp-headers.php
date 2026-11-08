<?php

declare(strict_types=1);

/**
 * Print HELMET's security headers as JSON for the CORP browser check (H13).
 *
 * Builds the header list through the same public API the middleware and
 * helmet_boot() use, with corp => 'same-origin' turned on and every other
 * option at its default, and prints name to value as a JSON object on stdout.
 * tools/a11y/corp-check.mjs reads that file and serves it on every response.
 *
 * Usage: php tools/a11y/corp-headers.php > corp.json
 */

use Waynestate\Helmet\Headers\SecurityHeaders;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$headers = SecurityHeaders::build(
    policy: SecurityHeaders::defaultCspPolicy(),
    nonce: SecurityHeaders::generateNonce(),
    isHttps: false,
    corp: 'same-origin',
);

echo json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
