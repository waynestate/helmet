<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Laravel;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The endpoint that receives Content-Security-Policy violation reports.
 *
 * Browsers POST a report here when a page's CSP (or
 * Content-Security-Policy-Report-Only) is violated and the policy names this
 * endpoint via report-to / report-uri. The report body is attacker-influenced
 * and can be large, so the controller caps the body size, accepts only the two
 * report content types, and logs one line per violation - the violated
 * directive, the blocked URI's host, and the document path - never the full
 * body.
 *
 * The two content types carry different shapes. The legacy
 * application/csp-report body is a single object with the report under
 * "csp-report" (kebab-case fields). The Reporting API
 * (application/reports+json) body is a JSON array of report objects, each
 * with a "type" and a "body" (camelCase fields); browsers batch other report
 * types into the same POST, so only the csp-violation entries are logged.
 *
 * The route is not registered by the service provider. An app opts in by
 * calling Route::cspReport() (see CspReportRoute), so the endpoint is off
 * unless the app wants it.
 */
final class CspReportController
{
    /**
     * The largest report body, in bytes, that the endpoint will accept.
     *
     * A single CSP report is a few hundred bytes; 16 KiB is far more than any
     * legitimate report and bounds the memory a malformed or malicious report
     * can force.
     */
    public const int MAX_BODY_BYTES = 16 * 1024;

    /**
     * The most entries of a Reporting API batch that the endpoint processes.
     *
     * A browser batches a handful of reports per POST; 20 is far more than any
     * legitimate batch and bounds the work (and log lines) one request can
     * force.
     */
    public const int MAX_REPORTS = 20;

    /**
     * The content types a browser sends a CSP report with.
     *
     * @var list<string>
     */
    private const array REPORT_CONTENT_TYPES = [
        'application/csp-report',
        'application/reports+json',
    ];

    public function __invoke(Request $request): Response
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        if (!in_array($contentType, self::REPORT_CONTENT_TYPES, true)) {
            return new Response('Unsupported media type.', 415);
        }

        // An early exit on the declared length, then the real check on the
        // body actually received.
        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && (int) $contentLength > self::MAX_BODY_BYTES) {
            return new Response('Report too large.', 413);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new Response('Report too large.', 413);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new Response('Invalid report.', 400);
        }

        if ($contentType === 'application/reports+json') {
            // The Reporting API batches: the body is a JSON array of report
            // objects. Anything that is not an array (a bare object, a
            // scalar) is not a report batch.
            if (!array_is_list($data)) {
                return new Response('Invalid report.', 400);
            }

            // Browsers batch other report types (deprecations, integrity
            // violations) into the same POST, so only the csp-violation
            // entries are logged - and only the first MAX_REPORTS of them.
            foreach (array_slice($data, 0, self::MAX_REPORTS) as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? null) !== 'csp-violation') {
                    continue;
                }

                $report = is_array($entry['body'] ?? null) ? $entry['body'] : [];
                $this->logViolation(
                    (string) ($report['effectiveDirective'] ?? 'unknown'),
                    (string) ($report['blockedURL'] ?? ''),
                    (string) ($report['documentURL'] ?? ''),
                );
            }

            return new Response('', 204);
        }

        // The legacy format: a single object with the report under
        // "csp-report".
        $report = $data['csp-report'] ?? null;
        if (!is_array($report)) {
            return new Response('Invalid report.', 400);
        }

        $this->logViolation(
            (string) ($report['violated-directive'] ?? $report['effective-directive'] ?? 'unknown'),
            (string) ($report['blocked-uri'] ?? ''),
            (string) ($report['document-uri'] ?? ''),
        );

        return new Response('', 204);
    }

    /**
     * Log one line for a violation: the three fields an operator needs to
     * triage, and nothing from the rest of the report.
     */
    private function logViolation(string $directive, string $blockedUri, string $documentUri): void
    {
        Log::warning(sprintf(
            'CSP violation: directive=%s blocked_host=%s document_path=%s',
            $directive,
            $this->hostOf($blockedUri),
            $this->pathOf($documentUri),
        ));
    }

    /**
     * The host of a URI, or "-" when it has none.
     */
    private function hostOf(string $uri): string
    {
        if ($uri === '') {
            return '-';
        }

        $host = parse_url($uri, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '-';
    }

    /**
     * The path of a URI, or "-" when it has none.
     */
    private function pathOf(string $uri): string
    {
        if ($uri === '') {
            return '-';
        }

        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '-';
    }
}
