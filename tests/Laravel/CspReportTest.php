<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Waynestate\Helmet\Laravel\CspReportController;

/**
 * The opt-in CSP report endpoint.
 *
 * The route is not registered by the service provider; each test opts in by
 * calling Route::cspReport(), the same way an application would.
 */
final class CspReportTest extends TestCase
{
    public function testReportEndpointAcceptsCspReport(): void
    {
        Route::cspReport();

        Log::shouldReceive('warning')
            ->once()
            ->with('CSP violation: directive=script-src blocked_host=evil.example document_path=/page');

        $body = json_encode([
            'csp-report' => [
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example/script.js',
                'document-uri' => 'https://app.example/page',
            ],
        ]);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], $body);

        $response->assertStatus(204);
    }

    public function testReportEndpointAcceptsReportsJson(): void
    {
        Route::cspReport();

        Log::shouldReceive('warning')
            ->once()
            ->with('CSP violation: directive=script-src blocked_host=evil.example document_path=/page');

        // The generic Reporting API nests the report under "body".
        $body = json_encode([
            'body' => [
                'csp-report' => [
                    'violated-directive' => 'script-src',
                    'blocked-uri' => 'https://evil.example/script.js',
                    'document-uri' => 'https://app.example/page',
                ],
            ],
        ]);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/reports+json',
        ], $body);

        $response->assertStatus(204);
    }

    public function testReportEndpointFallsBackToEffectiveDirectiveAndDashes(): void
    {
        Route::cspReport();

        Log::shouldReceive('warning')
            ->once()
            ->with('CSP violation: directive=img-src blocked_host=- document_path=-');

        $body = json_encode([
            'csp-report' => [
                'effective-directive' => 'img-src',
            ],
        ]);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], $body);

        $response->assertStatus(204);
    }

    public function testReportEndpointRejectsWrongContentType(): void
    {
        Route::cspReport();

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $response->assertStatus(415);
    }

    public function testReportEndpointRejectsOversizedBody(): void
    {
        Route::cspReport();

        // A body just over the 16 KiB cap.
        $body = str_repeat('a', CspReportController::MAX_BODY_BYTES + 1);

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], $body);

        $response->assertStatus(413);
    }

    public function testReportEndpointRejectsOversizedContentLength(): void
    {
        Route::cspReport();

        // The declared length is over the cap, so the endpoint exits before
        // reading the body.
        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
            'CONTENT_LENGTH' => (string) (CspReportController::MAX_BODY_BYTES + 1),
        ], '{}');

        $response->assertStatus(413);
    }

    public function testReportEndpointRejectsInvalidJson(): void
    {
        Route::cspReport();

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], '{not valid json');

        $response->assertStatus(400);
    }

    public function testReportEndpointRejectsMissingCspReport(): void
    {
        Route::cspReport();

        $response = $this->call('POST', '/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], json_encode(['foo' => 'bar']));

        $response->assertStatus(400);
    }
}
