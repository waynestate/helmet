<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Headers\InvalidCspReportUriException;
use Waynestate\Helmet\Headers\SecurityHeaders;

final class SecurityHeadersTest extends TestCase
{
    public function testDefaultPolicyIncludesHardeningDirectives(): void
    {
        $policy = SecurityHeaders::defaultCspPolicy();

        self::assertSame(["'self'"], $policy['base-uri']);
        self::assertSame(["'none'"], $policy['object-src']);
        self::assertSame(["'self'"], $policy['form-action']);
        self::assertSame(["'self'"], $policy['frame-ancestors']);
    }

    public function testDefaultPolicyKeepsUnsafeInlineOnlyOnStyleSrcAttr(): void
    {
        $policy = SecurityHeaders::defaultCspPolicy();

        self::assertSame(["'unsafe-inline'"], $policy['style-src-attr']);
        self::assertNotContains("'unsafe-inline'", $policy['style-src-elem']);
        self::assertNotContains("'unsafe-inline'", $policy['style-src']);
    }

    public function testBuildCspAddsStrictDynamicAlongsideNonce(): void
    {
        $csp = SecurityHeaders::buildCsp(SecurityHeaders::defaultCspPolicy(), 'testnonce');

        self::assertStringContainsString("'nonce-testnonce'", $csp);
        self::assertStringContainsString("'strict-dynamic'", $csp);
        // strict-dynamic belongs to script-src, next to the nonce.
        self::assertMatchesRegularExpression(
            "#script-src [^;]*'nonce-testnonce'[^;]*'strict-dynamic'[^;]*;#",
            $csp,
        );
    }

    /**
     * Vite and Vue inject <style> and <link> tags that carry the nonce, so
     * the style directives need it too.
     */
    public function testBuildCspNoncesTheStyleDirectives(): void
    {
        $csp = SecurityHeaders::buildCsp(SecurityHeaders::defaultCspPolicy(), 'testnonce');

        self::assertMatchesRegularExpression(
            "#(^|;) style-src [^;]*'nonce-testnonce';#",
            $csp,
        );
        self::assertMatchesRegularExpression(
            "#(^|;) style-src-elem [^;]*'nonce-testnonce';#",
            $csp,
        );
    }

    /**
     * An app may remove a style directive with null. The nonce is only added
     * to the directives that are present, and the CSP still builds.
     */
    public function testBuildCspStillBuildsWhenStyleSrcElemIsRemoved(): void
    {
        $policy = SecurityHeaders::mergePolicies(
            SecurityHeaders::defaultCspPolicy(),
            ['style-src-elem' => null],
        );

        $csp = SecurityHeaders::buildCsp($policy, 'testnonce');

        // style-src-elem is removed, so it must not appear in the CSP.
        self::assertStringNotContainsString('style-src-elem', $csp);
        // The nonce is still added to the directives that are present.
        self::assertStringContainsString("'nonce-testnonce'", $csp);
    }

    /**
     * Vite and Vue inline small fonts as data: URIs, so the default policy
     * allows data: for fonts.
     */
    public function testDefaultPolicyAllowsDataFonts(): void
    {
        $policy = SecurityHeaders::defaultCspPolicy();

        self::assertContains('data:', $policy['font-src']);
    }

    public function testMergePoliciesReplacesDirectiveAndKeepsTheRest(): void
    {
        $default = SecurityHeaders::defaultCspPolicy();
        $merged = SecurityHeaders::mergePolicies($default, ['default-src' => ["'none'"]]);

        self::assertSame(["'none'"], $merged['default-src']);
        self::assertSame($default['style-src'], $merged['style-src']);
        self::assertArrayHasKey('base-uri', $merged);
    }

    public function testMergePoliciesKeepsDefaultForAbsentDirective(): void
    {
        $default = SecurityHeaders::defaultCspPolicy();
        $merged = SecurityHeaders::mergePolicies($default, ['img-src' => ["'self'"]]);

        self::assertSame(["'self'"], $merged['img-src']);
        self::assertSame($default['script-src'], $merged['script-src']);
    }

    public function testMergePoliciesRemovesDirectiveWhenNull(): void
    {
        $default = SecurityHeaders::defaultCspPolicy();
        $merged = SecurityHeaders::mergePolicies($default, ['form-action' => null]);

        self::assertArrayNotHasKey('form-action', $merged);
        self::assertArrayHasKey('base-uri', $merged);
    }

    public function testMergePoliciesWithEmptyCustomReturnsDefault(): void
    {
        $default = SecurityHeaders::defaultCspPolicy();

        self::assertSame($default, SecurityHeaders::mergePolicies($default, []));
    }

    public function testBuildHstsDefaultIsOneYearWithSubDomains(): void
    {
        self::assertSame(
            'max-age=31536000; includeSubDomains',
            SecurityHeaders::buildHsts(),
        );
    }

    public function testBuildHstsCustomMaxAgeKeepsSubDomains(): void
    {
        self::assertSame('max-age=600; includeSubDomains', SecurityHeaders::buildHsts(600));
    }

    public function testBuildHstsCanDropSubDomains(): void
    {
        self::assertSame('max-age=600', SecurityHeaders::buildHsts(600, false));
    }

    public function testBuildHstsRejectsNegativeMaxAge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-negative');

        SecurityHeaders::buildHsts(-1);
    }

    public function testBuildSendsConfiguredHstsOnHttps(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            true,
            600,
            false,
        );

        self::assertSame('max-age=600', $headers['Strict-Transport-Security']);
    }

    public function testBuildOmitsHstsWhenNotHttps(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            600,
            false,
        );

        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function testBuildPermissionsPolicyCoversPaymentAndUsb(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
        );

        self::assertSame(
            'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            $headers['Permissions-Policy'],
        );
    }

    public function testBuildSendsContentSecurityPolicyByDefault(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
        );

        self::assertArrayHasKey('Content-Security-Policy', $headers);
        self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $headers);
    }

    public function testBuildSendsReportOnlyHeaderWhenReportOnly(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            true,
        );

        self::assertArrayHasKey('Content-Security-Policy-Report-Only', $headers);
        self::assertArrayNotHasKey('Content-Security-Policy', $headers);
    }

    public function testBuildSendsReportingEndpointsWhenReportUriSet(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            'https://example.com/csp-report',
        );

        self::assertSame(
            'csp-endpoint="https://example.com/csp-report"',
            $headers['Reporting-Endpoints'],
        );
        $csp = $headers['Content-Security-Policy'];
        self::assertStringContainsString('report-uri https://example.com/csp-report', $csp);
        self::assertStringContainsString('report-to csp-endpoint', $csp);
    }

    public function testBuildOmitsReportingDirectivesWhenNoReportUri(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
        );

        self::assertArrayNotHasKey('Reporting-Endpoints', $headers);
        self::assertStringNotContainsString('report-uri', $headers['Content-Security-Policy']);
        self::assertStringNotContainsString('report-to', $headers['Content-Security-Policy']);
    }

    /**
     * Report-only is exactly when reports are wanted, so the Reporting-Endpoints
     * header and the report directives are sent in that mode too.
     */
    public function testBuildReportOnlyStillSendsReportingEndpoints(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            '/csp-report',
            true,
        );

        self::assertSame('csp-endpoint="/csp-report"', $headers['Reporting-Endpoints']);
        $csp = $headers['Content-Security-Policy-Report-Only'];
        self::assertStringContainsString('report-uri /csp-report', $csp);
        self::assertStringContainsString('report-to csp-endpoint', $csp);
    }

    public function testValidateReportUriAcceptsAbsoluteHttps(): void
    {
        self::assertSame(
            'https://example.com/csp-report',
            SecurityHeaders::validateReportUri('https://example.com/csp-report'),
        );
    }

    public function testValidateReportUriAcceptsRelativePath(): void
    {
        self::assertSame('/csp-report', SecurityHeaders::validateReportUri('/csp-report'));
    }

    public function testValidateReportUriRejectsEmpty(): void
    {
        $this->expectException(InvalidCspReportUriException::class);

        SecurityHeaders::validateReportUri('');
    }

    public function testValidateReportUriRejectsHttp(): void
    {
        $this->expectException(InvalidCspReportUriException::class);

        SecurityHeaders::validateReportUri('http://example.com/csp-report');
    }

    public function testValidateReportUriRejectsProtocolRelative(): void
    {
        $this->expectException(InvalidCspReportUriException::class);

        SecurityHeaders::validateReportUri('//example.com/csp-report');
    }

    public function testValidateReportUriRejectsHostlessAbsolute(): void
    {
        $this->expectException(InvalidCspReportUriException::class);

        SecurityHeaders::validateReportUri('https:');
    }

    public function testValidateReportUriRejectsWhitespace(): void
    {
        $this->expectException(InvalidCspReportUriException::class);

        SecurityHeaders::validateReportUri("https://example.com/csp\nreport");
    }

    public function testValidateReportUriRejectsDoubleQuote(): void
    {
        $this->expectException(InvalidCspReportUriException::class);

        SecurityHeaders::validateReportUri('https://example.com/csp"report');
    }

    public function testBuildCspThrowsOnInvalidReportUri(): void
    {
        $this->expectException(InvalidCspReportUriException::class);

        SecurityHeaders::buildCsp(SecurityHeaders::defaultCspPolicy(), 'testnonce', 'http://example.com');
    }
}
