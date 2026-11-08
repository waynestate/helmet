<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Health;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Health\Readiness;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * Readiness is the "send me traffic?" answer: 200 while no check failed,
 * 503 once one did, a JSON body of names and statuses, and no detail that
 * could leak (addresses, exception text, the policy).
 */
final class ReadinessTest extends TestCase
{
    /**
     * @return \Closure(): array<string, string>
     */
    private function goodHeaders(): \Closure
    {
        return static fn(): array => SecurityHeaders::build(SecurityHeaders::defaultCspPolicy(), 'probe-nonce', false);
    }

    /**
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    private function body(Readiness $readiness): array
    {
        /** @var array{status: string, checks: array<string, array<string, mixed>>} $decoded */
        $decoded = json_decode($readiness->response()['body'], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testStandardChecksPassForAGoodConfig(): void
    {
        $readiness = Readiness::standard(new TrustedProxies(['172.18.0.1']), $this->goodHeaders());
        $response = $readiness->response();

        self::assertSame(200, $response['status']);
        self::assertSame('application/json; charset=utf-8', $response['headers']['Content-Type']);
        self::assertSame('no-store', $response['headers']['Cache-Control']);

        $body = $this->body($readiness);
        self::assertSame('ready', $body['status']);
        self::assertSame(['headers', 'trusted_proxies', 'debug_output'], array_keys($body['checks']));
        self::assertSame(['status' => 'pass', 'csp' => 'enforce', 'style_src_attr' => 'inline'], $body['checks']['headers']);
        self::assertSame(['status' => 'pass', 'count' => 1, 'broad' => 0], $body['checks']['trusted_proxies']);
    }

    public function testTheBodyNeverCarriesAddressesOrThePolicy(): void
    {
        $readiness = Readiness::standard(new TrustedProxies(['172.18.0.1', '10.0.0.0/8']), $this->goodHeaders());
        $raw = $readiness->response()['body'];

        self::assertStringNotContainsString('172.18.0.1', $raw);
        self::assertStringNotContainsString('10.0.0.0', $raw);
        self::assertStringNotContainsString('wayneui', $raw);
        self::assertStringNotContainsString('nonce', $raw);
    }

    public function testReportOnlyModeIsNamed(): void
    {
        $headers = static fn(): array => SecurityHeaders::build(SecurityHeaders::defaultCspPolicy(), 'n', false, 31536000, true, null, true);
        $body = $this->body(Readiness::standard(new TrustedProxies(['172.18.0.1']), $headers, true));

        self::assertSame('pass', $body['checks']['headers']['status']);
        self::assertSame('report-only', $body['checks']['headers']['csp']);
    }

    public function testAHeaderBuildThatThrowsFailsAndIsLogged(): void
    {
        $logger = new ReadinessTestLogger();
        $readiness = Readiness::standard(
            new TrustedProxies(['172.18.0.1']),
            static fn(): array => SecurityHeaders::build(SecurityHeaders::defaultCspPolicy(), 'n', false, 31536000, true, null, false, 'not-a-coop-value'),
            false,
            'inline',
            $logger,
        );
        $response = $readiness->response();

        self::assertSame(503, $response['status']);
        self::assertSame('not ready', $this->body($readiness)['status']);
        self::assertSame('fail', $this->body($readiness)['checks']['headers']['status']);
        self::assertStringNotContainsString('COOP', $response['body']);
        self::assertSame('helmet.readiness_check_failed', $logger->records[0]['message']);
        self::assertSame('headers', $logger->records[0]['context']['check']);
    }

    public function testAMissingCspHeaderFails(): void
    {
        $readiness = Readiness::standard(new TrustedProxies(['172.18.0.1']), static fn(): array => ['X-Content-Type-Options' => 'nosniff']);

        self::assertSame(503, $readiness->response()['status']);
    }

    public function testUnregisteredHeadersFail(): void
    {
        $readiness = (new Readiness())->withHeaders($this->goodHeaders(), false, 'inline', false);
        $body = $this->body($readiness);

        self::assertSame(503, $readiness->response()['status']);
        self::assertFalse($body['checks']['headers']['registered']);
    }

    public function testNoTrustedProxyIsAWarningNotAFailure(): void
    {
        $readiness = Readiness::standard(new TrustedProxies([]), $this->goodHeaders());
        $body = $this->body($readiness);

        self::assertSame(200, $readiness->response()['status']);
        self::assertSame(['status' => 'warn', 'count' => 0, 'broad' => 0], $body['checks']['trusted_proxies']);
    }

    public function testABroadTrustedRangeIsAWarning(): void
    {
        $body = $this->body(Readiness::standard(new TrustedProxies(['10.0.0.0/8']), $this->goodHeaders()));

        self::assertSame(['status' => 'warn', 'count' => 1, 'broad' => 1], $body['checks']['trusted_proxies']);
    }

    public function testDebugOutputOnIsAWarning(): void
    {
        $readiness = (new Readiness())->withDebugOutput(true);

        self::assertSame(200, $readiness->response()['status']);
        self::assertSame('warn', $this->body($readiness)['checks']['debug_output']['status']);
    }

    public function testDisplayErrorsReading(): void
    {
        $before = ini_get('display_errors');
        try {
            ini_set('display_errors', '1');
            self::assertTrue(Readiness::displayErrorsOn());
            ini_set('display_errors', 'stderr');
            self::assertFalse(Readiness::displayErrorsOn());
            ini_set('display_errors', '0');
            self::assertFalse(Readiness::displayErrorsOn());
        } finally {
            ini_set('display_errors', (string) $before);
        }
    }

    public function testAppCheckTrueAndNullPass(): void
    {
        $readiness = (new Readiness())
            ->withCheck('database', static fn(): bool => true)
            ->withCheck('cache', static fn(): ?bool => null);

        self::assertSame(200, $readiness->response()['status']);
        self::assertSame(['database', 'cache'], $readiness->names());
    }

    public function testAppCheckFalseFails(): void
    {
        $readiness = (new Readiness())
            ->withCheck('database', static fn(): bool => false)
            ->withCheck('cache', static fn(): bool => true);
        $body = $this->body($readiness);

        self::assertSame(503, $readiness->response()['status']);
        self::assertSame('fail', $body['checks']['database']['status']);
        self::assertSame('pass', $body['checks']['cache']['status']);
    }

    public function testAThrowingAppCheckFailsAndKeepsTheMessageOutOfTheBody(): void
    {
        $logger = new ReadinessTestLogger();
        $readiness = (new Readiness($logger))->withCheck('database', static function (): bool {
            throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused to db-secret-host');
        });
        $response = $readiness->response();

        self::assertSame(503, $response['status']);
        self::assertStringNotContainsString('db-secret-host', $response['body']);
        self::assertStringNotContainsString('SQLSTATE', $response['body']);
        self::assertSame('database', $logger->records[0]['context']['check']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertStringContainsString('Connection refused', $logger->records[0]['context']['message']);
    }

    public function testAThrowingLoggerDoesNotBreakTheProbe(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('disk full');
            }
        };
        $readiness = (new Readiness($logger))->withCheck('database', static function (): bool {
            throw new \RuntimeException('down');
        });

        self::assertSame(503, $readiness->response()['status']);
    }

    public function testNoChecksIsReady(): void
    {
        $response = (new Readiness())->response();

        self::assertSame(200, $response['status']);
        self::assertSame('{"status":"ready","checks":{}}', $response['body']);
    }

    public function testWithIsImmutable(): void
    {
        $base = new Readiness();
        $with = $base->withCheck('database', static fn(): bool => false);

        self::assertSame([], $base->names());
        self::assertSame(200, $base->response()['status']);
        self::assertSame(503, $with->response()['status']);
    }

    public function testInvalidCheckNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Readiness())->withCheck('Data Base', static fn(): bool => true);
    }

    public function testDuplicateCheckNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Readiness::standard(new TrustedProxies([]), $this->goodHeaders())->withCheck('headers', static fn(): bool => true);
    }
}

/**
 * A logger that keeps what it was given.
 */
final class ReadinessTestLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
