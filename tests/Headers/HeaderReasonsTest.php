<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Headers\HeaderReasons;
use Waynestate\Helmet\Headers\SecurityHeaders;

/**
 * Every header and directive HELMET can send has a reason, so adding one
 * without saying why fails here first.
 */
final class HeaderReasonsTest extends TestCase
{
    public function testEveryHeaderTheFullestBuildSendsHasAReason(): void
    {
        // Every option on, so every header build() can emit is present.
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'nonce',
            true,
            cspReportUri: '/csp-report',
            corp: 'same-origin',
        );
        $reportOnly = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'nonce',
            false,
            cspReportOnly: true,
        );

        foreach (array_merge(array_keys($headers), array_keys($reportOnly), ['Cache-Control', 'X-Powered-By']) as $name) {
            self::assertNotNull(HeaderReasons::header($name), $name . ' needs a reason in HeaderReasons');
        }
    }

    public function testEveryDirectiveTheFullestPolicySendsHasAReason(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'nonce',
            true,
            cspReportUri: '/csp-report',
            cspReportOnly: true,
            trustedTypesEnabled: true,
        );

        foreach (explode(';', $headers['Content-Security-Policy-Report-Only']) as $part) {
            $directive = strtok(trim($part), ' ');
            if ($directive === false) {
                continue;
            }
            self::assertNotNull(HeaderReasons::directive($directive), $directive . ' needs a reason in HeaderReasons');
        }
        foreach (array_keys(SecurityHeaders::defaultCspPolicy()) as $directive) {
            self::assertContains($directive, HeaderReasons::directives());
        }
    }

    public function testLookupsIgnoreCaseAndUnknownNamesGetNull(): void
    {
        self::assertSame(HeaderReasons::header('X-Frame-Options'), HeaderReasons::header('x-frame-options'));
        self::assertSame(HeaderReasons::directive('script-src'), HeaderReasons::directive('SCRIPT-SRC'));
        self::assertNull(HeaderReasons::header('X-Made-Up'));
        self::assertNull(HeaderReasons::directive('worker-src'));
        self::assertContains('Strict-Transport-Security', HeaderReasons::headers());
    }

    public function testReasonsAreShortAndUseNoLongDashes(): void
    {
        foreach (HeaderReasons::directives() as $directive) {
            $this->assertPlain((string) HeaderReasons::directive($directive));
        }
        foreach (HeaderReasons::headers() as $header) {
            $this->assertPlain((string) HeaderReasons::header($header));
        }
    }

    private function assertPlain(string $reason): void
    {
        self::assertNotSame('', $reason);
        self::assertLessThanOrEqual(400, strlen($reason), $reason);
        self::assertStringNotContainsString("\u{2014}", $reason);
        self::assertStringNotContainsString("\u{2013}", $reason);
    }
}
