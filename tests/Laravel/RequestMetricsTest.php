<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Waynestate\Helmet\Laravel\RecordRequestMetrics;

/**
 * Request metrics in a Laravel app, with the endpoint on and SQLite as the
 * shared store (the one store every official PHP image can open).
 *
 * The config is set in defineEnvironment(), before the HTTP kernel is built,
 * because the provider decides at that point whether the middleware joins.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class RequestMetricsTest extends TestCase
{
    private string $file = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->file = sys_get_temp_dir() . '/helmet-metrics-laravel-' . bin2hex(random_bytes(6)) . '.sqlite';
        $app['config']->set('helmet.metrics', [
            'enabled' => true,
            'token' => 'secret',
            'allow' => [],
            'storage' => 'sqlite',
            'redis_url' => null,
            'sqlite_path' => $this->file,
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testTheMiddlewareIsTheOutermostGlobalMiddleware(): void
    {
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(FoundationKernel::class, $kernel);

        $global = $this->globalMiddlewareList($kernel);
        self::assertSame(RecordRequestMetrics::class, $global[0] ?? null);
        self::assertSame(1, array_count_values($global)[RecordRequestMetrics::class]);
    }

    public function testRequestsAreCountedByRouteNameTemplateOrUnmatched(): void
    {
        Route::helmetMetrics();
        Route::get('/docs/{page}', static fn() => 'a page')->name('docs.show');
        Route::get('/about/{section}', static fn() => 'about');

        $this->get('/docs/install')->assertOk();
        $this->get('/docs/upgrading')->assertOk();
        $this->get('/about/team')->assertOk();
        $this->get('/no/such/page/' . bin2hex(random_bytes(4)))->assertNotFound();

        $body = $this->scrape();

        self::assertStringContainsString('helmet_http_request_duration_seconds_count{route="docs.show",method="GET",status="2xx"} 2', $body);
        self::assertStringContainsString('helmet_http_request_duration_seconds_count{route="about/{section}",method="GET",status="2xx"} 1', $body);
        self::assertStringContainsString('helmet_http_request_duration_seconds_count{route="unmatched",method="GET",status="4xx"} 1', $body);
        self::assertStringNotContainsString('no/such/page', $body);
        self::assertStringContainsString('helmet_metrics_storage_up 1', $body);
    }

    public function testCspReportsAreCountedByDirective(): void
    {
        Route::helmetMetrics();
        Route::cspReport();

        $report = (string) json_encode(['csp-report' => [
            'document-uri' => 'https://app.example.test/page',
            'violated-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example.test/x.js',
        ]]);
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $report)
            ->assertStatus(204);

        $forged = (string) json_encode(['csp-report' => ['violated-directive' => 'random-' . bin2hex(random_bytes(4))]]);
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $forged)
            ->assertStatus(204);

        $body = $this->scrape();

        self::assertStringContainsString('helmet_csp_violations_total{directive="script-src-elem"} 1', $body);
        self::assertStringContainsString('helmet_csp_violations_total{directive="other"} 1', $body);
    }

    private function scrape(): string
    {
        $response = $this->call('GET', '/metrics', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
        ]);
        $response->assertOk();

        return (string) $response->getContent();
    }
}
