<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Headers\SecurityHeadersMiddleware;
use Waynestate\Helmet\Proxy\TrustedProxies;

/**
 * HELMET-17 (ADR-0001): the inline style attribute mode. "inline" is the
 * default and must stay byte for byte what every earlier release sent.
 */
final class StyleSrcAttrTest extends TestCase
{
    /**
     * @param array<string, list<string>|null> $custom
     *
     * @return array<string, string>
     */
    private function build(string $mode, bool $reportOnly = false, ?string $reportUri = null, array $custom = []): array
    {
        return SecurityHeaders::build(
            SecurityHeaders::mergePolicies(SecurityHeaders::defaultCspPolicy(), $custom),
            'n0nce',
            false,
            31536000,
            true,
            $reportUri,
            $reportOnly,
            'same-origin',
            null,
            null,
            false,
            false,
            ['default'],
            $mode,
        );
    }

    public function testInlineIsTheDefaultAndUnchanged(): void
    {
        $policy = SecurityHeaders::defaultCspPolicy();
        $before = SecurityHeaders::build($policy, 'n0nce', false);

        self::assertSame($before, $this->build('inline'));
        self::assertStringContainsString("style-src-attr 'unsafe-inline'", $before['Content-Security-Policy']);
        self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $before);
    }

    public function testNoneBlocksInlineStyleAttributes(): void
    {
        $headers = $this->build('none');

        self::assertStringContainsString("style-src-attr 'none'", $headers['Content-Security-Policy']);
        self::assertStringNotContainsString("'unsafe-inline'", $headers['Content-Security-Policy']);
        self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $headers);
    }

    public function testNoneLeavesTheRestOfThePolicyAlone(): void
    {
        $inline = $this->build('inline')['Content-Security-Policy'];
        $none = $this->build('none')['Content-Security-Policy'];

        self::assertSame(
            str_replace("style-src-attr 'unsafe-inline'", "style-src-attr 'none'", $inline),
            $none,
        );
    }

    public function testReportKeepsEnforcingAndAddsAProbe(): void
    {
        $headers = $this->build('report');

        self::assertStringContainsString("style-src-attr 'unsafe-inline'", $headers['Content-Security-Policy']);
        self::assertSame("style-src-attr 'none'", $headers['Content-Security-Policy-Report-Only']);
    }

    public function testTheProbeReportsToTheSameDestination(): void
    {
        $headers = $this->build('report', false, '/csp-report');

        self::assertSame(
            "style-src-attr 'none'; report-to csp-endpoint; report-uri /csp-report",
            $headers['Content-Security-Policy-Report-Only'],
        );
        self::assertSame('csp-endpoint="/csp-report"', $headers['Reporting-Endpoints']);
    }

    public function testReportOnAReportOnlyPolicyStrictensTheOneHeader(): void
    {
        $headers = $this->build('report', true);

        self::assertArrayNotHasKey('Content-Security-Policy', $headers);
        self::assertStringContainsString("style-src-attr 'none'", $headers['Content-Security-Policy-Report-Only']);
        self::assertStringContainsString("'nonce-n0nce'", $headers['Content-Security-Policy-Report-Only']);
    }

    public function testAnAppValueWinsInEveryMode(): void
    {
        $custom = ['style-src-attr' => ["'unsafe-hashes'", "'sha256-abc='"]];

        foreach (['none', 'report'] as $mode) {
            $headers = $this->build($mode, false, null, $custom);
            self::assertStringContainsString("style-src-attr 'sha256-abc=' 'unsafe-hashes'", $headers['Content-Security-Policy'], $mode);
            self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $headers, $mode);
        }
    }

    public function testARemovedDirectiveStaysRemoved(): void
    {
        $headers = $this->build('none', false, null, ['style-src-attr' => null]);

        self::assertStringNotContainsString('style-src-attr', $headers['Content-Security-Policy']);
    }

    public function testTheHeaderOrderStartsWithThePolicies(): void
    {
        self::assertSame(
            ['Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'X-Content-Type-Options'],
            array_slice(array_keys($this->build('report')), 0, 3),
        );
    }

    public function testAnUnknownModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"strict"');
        $this->build('strict');
    }

    public function testTheModesAreNamed(): void
    {
        self::assertSame(['inline', 'report', 'none'], SecurityHeaders::STYLE_SRC_ATTR_MODES);
        self::assertSame('none', SecurityHeaders::validateStyleSrcAttr('none'));
    }

    public function testTheMiddlewarePassesTheModeThrough(): void
    {
        $middleware = new SecurityHeadersMiddleware(
            new TrustedProxies([]),
            null,
            true,
            null,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            null,
            false,
            false,
            ['default'],
            false,
            [],
            'none',
        );
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $response = $middleware->process(new ServerRequest('GET', 'http://app.example/'), $handler);

        self::assertStringContainsString("style-src-attr 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testTheMiddlewareRejectsAnUnknownModeAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecurityHeadersMiddleware(
            new TrustedProxies([]),
            null,
            true,
            null,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            null,
            false,
            false,
            ['default'],
            false,
            [],
            'off',
        );
    }
}
