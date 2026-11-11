<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Headers;

use Psr\Log\LoggerInterface;
use Waynestate\Helmet\Metrics\RequestMetrics;

/**
 * The framework-free half of the CSP report endpoint.
 *
 * Browsers POST a report when a page's Content-Security-Policy (or its
 * Report-Only twin) is violated and the policy names an endpoint through
 * report-to / report-uri. The body is attacker-influenced and can be large,
 * so the endpoint caps the body, accepts only the two report content types,
 * and keeps three fields per violation (the directive, the blocked URI's host
 * and the document's path), never the rest of the body.
 *
 * The two content types carry different shapes. The legacy
 * application/csp-report body is a single object with the report under
 * "csp-report" (kebab-case fields). The Reporting API
 * (application/reports+json) body is a JSON array of report objects, each
 * with a "type" and a "body" (camelCase fields); browsers batch other report
 * types into the same POST, so only the csp-violation entries count.
 *
 * parse() is pure: it decides the answer and pulls out the violations, and
 * sends and logs nothing. answer() adds the method check, the log lines and
 * the metrics tick. The Laravel controller, the PSR-15 middleware and the raw
 * PHP helmet_csp_report() all go through here, so the three paths cannot
 * drift: one referee, one rulebook.
 */
final class CspReport
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

    /**
     * The headers on every answer, refusals included.
     *
     * @var array<string, string>
     */
    private const array HEADERS = [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'no-store',
    ];

    /**
     * Decide the answer for a report and pull out its violations.
     *
     * The checks run in a fixed order: the media type (415), the declared
     * length and then the real length (413), then the JSON shape (400). A
     * refusal carries no violations.
     *
     * @param string   $contentType    The Content-Type header as sent (parameters and case are ignored).
     * @param string   $body           The request body.
     * @param int|null $declaredLength The Content-Length header, when the request sent one.
     *
     * @return array{status: int, body: string, violations: list<array{directive: string, blocked_host: string, document_path: string}>}
     */
    public static function parse(string $contentType, string $body, ?int $declaredLength = null): array
    {
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));
        if (!in_array($mediaType, self::REPORT_CONTENT_TYPES, true)) {
            return self::refusal(415, 'Unsupported media type.');
        }

        // An early exit on the declared length, then the real check on the
        // body actually received.
        if ($declaredLength !== null && $declaredLength > self::MAX_BODY_BYTES) {
            return self::refusal(413, 'Report too large.');
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return self::refusal(413, 'Report too large.');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return self::refusal(400, 'Invalid report.');
        }

        $violations = [];

        if ($mediaType === 'application/reports+json') {
            // The Reporting API batches: the body is a JSON array of report
            // objects. A bare object is not a batch.
            if (!array_is_list($data)) {
                return self::refusal(400, 'Invalid report.');
            }

            foreach (array_slice($data, 0, self::MAX_REPORTS) as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? null) !== 'csp-violation') {
                    continue;
                }

                $report = is_array($entry['body'] ?? null) ? $entry['body'] : [];
                $violations[] = self::violation(
                    self::text($report['effectiveDirective'] ?? null, 'unknown'),
                    self::text($report['blockedURL'] ?? null),
                    self::text($report['documentURL'] ?? null),
                );
            }

            return ['status' => 204, 'body' => '', 'violations' => $violations];
        }

        // The legacy format: a single object with the report under
        // "csp-report".
        $report = $data['csp-report'] ?? null;
        if (!is_array($report)) {
            return self::refusal(400, 'Invalid report.');
        }

        $violations[] = self::violation(
            self::text($report['violated-directive'] ?? $report['effective-directive'] ?? null, 'unknown'),
            self::text($report['blocked-uri'] ?? null),
            self::text($report['document-uri'] ?? null),
        );

        return ['status' => 204, 'body' => '', 'violations' => $violations];
    }

    /**
     * Answer a report request: the method check, the parse, one log line and
     * one metrics tick per violation.
     *
     * A method other than POST is refused with 405 and Allow: POST before
     * the body is looked at. A logger or a metrics store that throws never
     * changes the answer: the browser does not care how our evening went.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function answer(
        string $method,
        string $contentType,
        string $body,
        ?int $declaredLength,
        ?LoggerInterface $logger = null,
        ?RequestMetrics $metrics = null,
    ): array {
        if (strtoupper($method) !== 'POST') {
            return [
                'status' => 405,
                'headers' => self::HEADERS + ['Allow' => 'POST'],
                'body' => 'Method not allowed.',
            ];
        }

        $result = self::parse($contentType, $body, $declaredLength);

        foreach ($result['violations'] as $violation) {
            try {
                $logger?->warning(self::logLine($violation), $violation);
            } catch (\Throwable) {
                // A logging failure must never change the answer.
            }

            try {
                $metrics?->cspViolation($violation['directive']);
            } catch (\Throwable) {
                // The violation is already in the log.
            }
        }

        return ['status' => $result['status'], 'headers' => self::HEADERS, 'body' => $result['body']];
    }

    /**
     * The one log line for a violation: the three fields an operator needs to
     * triage, and nothing from the rest of the report.
     *
     * @param array{directive: string, blocked_host: string, document_path: string} $violation
     */
    public static function logLine(array $violation): string
    {
        return sprintf(
            'CSP violation: directive=%s blocked_host=%s document_path=%s',
            $violation['directive'],
            $violation['blocked_host'],
            $violation['document_path'],
        );
    }

    /**
     * A digit-only Content-Length value as an int, anything else as null.
     */
    public static function declaredLength(mixed $value): ?int
    {
        return is_string($value) && $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @return array{status: int, body: string, violations: list<array{directive: string, blocked_host: string, document_path: string}>}
     */
    private static function refusal(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body, 'violations' => []];
    }

    /**
     * @return array{directive: string, blocked_host: string, document_path: string}
     */
    private static function violation(string $directive, string $blockedUri, string $documentUri): array
    {
        return [
            'directive' => $directive,
            'blocked_host' => self::hostOf($blockedUri),
            'document_path' => self::pathOf($documentUri),
        ];
    }

    /**
     * A report field as text. A scalar becomes its string form; a missing
     * value, null, an array or an object becomes the default.
     */
    private static function text(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * The host of a URI, or "-" when it has none.
     */
    private static function hostOf(string $uri): string
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
    private static function pathOf(string $uri): string
    {
        if ($uri === '') {
            return '-';
        }

        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '-';
    }
}
