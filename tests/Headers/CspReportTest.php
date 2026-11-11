<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Headers\CspReport;
use Waynestate\Helmet\Laravel\CspReportController;

/**
 * The framework-free half of the CSP report endpoint (H7, HELMET-10).
 *
 * CspReport::parse() decides the answer and pulls the three fields an
 * operator triages from a report body; the Laravel controller and the raw
 * PHP helmet_csp_report_response() both call it, so the two paths cannot
 * drift. Nothing here sends or logs: parse() is pure.
 */
final class CspReportTest extends TestCase
{
    public function testTheLimitsAreTheControllersLimits(): void
    {
        self::assertSame(16 * 1024, CspReport::MAX_BODY_BYTES);
        self::assertSame(20, CspReport::MAX_REPORTS);
        self::assertSame(CspReport::MAX_BODY_BYTES, CspReportController::MAX_BODY_BYTES);
        self::assertSame(CspReport::MAX_REPORTS, CspReportController::MAX_REPORTS);
    }

    public function testALegacyReportYieldsOneViolation(): void
    {
        $body = (string) json_encode(['csp-report' => [
            'document-uri' => 'https://app.example.edu/courses/12?tab=grades',
            'violated-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example.com/x.js',
            'original-policy' => "script-src 'self'",
        ]]);

        $result = CspReport::parse('application/csp-report', $body);

        self::assertSame(204, $result['status']);
        self::assertSame('', $result['body']);
        self::assertSame([[
            'directive' => 'script-src-elem',
            'blocked_host' => 'evil.example.com',
            'document_path' => '/courses/12',
        ]], $result['violations']);
    }

    public function testTheContentTypeIsReadCaseBlindAndWithoutParameters(): void
    {
        $body = (string) json_encode(['csp-report' => ['violated-directive' => 'img-src']]);

        $result = CspReport::parse(' Application/CSP-Report; charset=utf-8', $body);

        self::assertSame(204, $result['status']);
        self::assertCount(1, $result['violations']);
    }

    public function testALegacyReportFallsBackToEffectiveDirectiveAndDashes(): void
    {
        $body = (string) json_encode(['csp-report' => ['effective-directive' => 'style-src-attr']]);

        $result = CspReport::parse('application/csp-report', $body);

        self::assertSame([[
            'directive' => 'style-src-attr',
            'blocked_host' => '-',
            'document_path' => '-',
        ]], $result['violations']);
    }

    public function testAReportWithNoDirectiveIsUnknown(): void
    {
        $body = (string) json_encode(['csp-report' => ['blocked-uri' => 'inline']]);

        $result = CspReport::parse('application/csp-report', $body);

        self::assertSame('unknown', $result['violations'][0]['directive']);
        self::assertSame('-', $result['violations'][0]['blocked_host']);
    }

    public function testAReportingApiBatchKeepsOnlyCspViolations(): void
    {
        $body = (string) json_encode([
            ['type' => 'deprecation', 'body' => ['id' => 'x']],
            ['type' => 'csp-violation', 'body' => [
                'documentURL' => 'https://app.example.edu/home',
                'effectiveDirective' => 'script-src-elem',
                'blockedURL' => 'https://cdn.example.net/lib.js',
            ]],
            'not-an-object',
            ['type' => 'csp-violation'],
        ]);

        $result = CspReport::parse('application/reports+json', $body);

        self::assertSame(204, $result['status']);
        self::assertSame([
            ['directive' => 'script-src-elem', 'blocked_host' => 'cdn.example.net', 'document_path' => '/home'],
            ['directive' => 'unknown', 'blocked_host' => '-', 'document_path' => '-'],
        ], $result['violations']);
    }

    public function testABatchIsCappedAtTwentyReports(): void
    {
        $entry = ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src']];
        $body = (string) json_encode(array_fill(0, 25, $entry));

        $result = CspReport::parse('application/reports+json', $body);

        self::assertSame(204, $result['status']);
        self::assertCount(20, $result['violations']);
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function refusals(): iterable
    {
        $legacy = (string) json_encode(['csp-report' => ['violated-directive' => 'img-src']]);

        yield 'wrong content type' => ['application/json', $legacy, 415, 'Unsupported media type.'];
        yield 'no content type' => ['', $legacy, 415, 'Unsupported media type.'];
        yield 'oversized body' => ['application/csp-report', str_repeat('a', 16 * 1024 + 1), 413, 'Report too large.'];
        yield 'invalid json' => ['application/csp-report', '{not json', 400, 'Invalid report.'];
        yield 'json scalar' => ['application/csp-report', '"a string"', 400, 'Invalid report.'];
        yield 'missing csp-report' => ['application/csp-report', '{"other":{}}', 400, 'Invalid report.'];
        yield 'reports+json object' => ['application/reports+json', '{"type":"csp-violation"}', 400, 'Invalid report.'];
    }

    #[DataProvider('refusals')]
    public function testARefusedReportYieldsNoViolations(string $contentType, string $body, int $status, string $text): void
    {
        $result = CspReport::parse($contentType, $body);

        self::assertSame($status, $result['status']);
        self::assertSame($text, $result['body']);
        self::assertSame([], $result['violations']);
    }

    public function testTheDeclaredLengthIsCheckedBeforeTheBody(): void
    {
        $legacy = (string) json_encode(['csp-report' => ['violated-directive' => 'img-src']]);

        $result = CspReport::parse('application/csp-report', $legacy, 16 * 1024 + 1);

        self::assertSame(413, $result['status']);
        self::assertSame([], $result['violations']);
    }

    public function testTheContentTypeIsCheckedBeforeTheLength(): void
    {
        $result = CspReport::parse('text/plain', str_repeat('a', 16 * 1024 + 1), 16 * 1024 + 1);

        self::assertSame(415, $result['status']);
    }

    public function testTheLogLineCarriesTheThreeFieldsAndNothingElse(): void
    {
        self::assertSame(
            'CSP violation: directive=script-src-elem blocked_host=evil.example.com document_path=/courses/12',
            CspReport::logLine([
                'directive' => 'script-src-elem',
                'blocked_host' => 'evil.example.com',
                'document_path' => '/courses/12',
            ]),
        );
    }
}
