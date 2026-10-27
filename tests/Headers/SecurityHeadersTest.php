<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use PHPUnit\Framework\TestCase;
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
            $csp
        );
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
            SecurityHeaders::buildHsts()
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
            false
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
            false
        );

        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function testBuildPermissionsPolicyCoversPaymentAndUsb(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false
        );

        self::assertSame(
            'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            $headers['Permissions-Policy']
        );
    }
}
