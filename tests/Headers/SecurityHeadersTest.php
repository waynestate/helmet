<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Headers;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testDefaultPermissionsPolicyDeniesTheFiveFeatures(): void
    {
        $policy = SecurityHeaders::defaultPermissionsPolicy();

        self::assertSame([], $policy['camera']);
        self::assertSame([], $policy['geolocation']);
        self::assertSame([], $policy['microphone']);
        self::assertSame([], $policy['payment']);
        self::assertSame([], $policy['usb']);
        // interest-cohort is obsolete: the browser vendors dropped it, so the
        // default deny list does not carry it.
        self::assertArrayNotHasKey('interest-cohort', $policy);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function coopValues(): array
    {
        return [
            'unsafe-none' => ['unsafe-none'],
            'same-origin-allow-popups' => ['same-origin-allow-popups'],
            'same-origin' => ['same-origin'],
            'noopener-allow-popups' => ['noopener-allow-popups'],
        ];
    }

    #[DataProvider('coopValues')]
    public function testBuildCoopAcceptsEveryAllowedValue(string $value): void
    {
        self::assertSame($value, SecurityHeaders::buildCoop($value));
    }

    public function testBuildCoopRejectsInvalidValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COOP must be one of');

        SecurityHeaders::buildCoop('bogus');
    }

    public function testBuildCoopRejectsEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecurityHeaders::buildCoop('');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function corpValues(): array
    {
        return [
            'same-site' => ['same-site'],
            'same-origin' => ['same-origin'],
            'cross-origin' => ['cross-origin'],
        ];
    }

    #[DataProvider('corpValues')]
    public function testBuildCorpAcceptsEveryAllowedValue(string $value): void
    {
        self::assertSame($value, SecurityHeaders::buildCorp($value));
    }

    public function testBuildCorpRejectsInvalidValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CORP must be one of');

        SecurityHeaders::buildCorp('bogus');
    }

    public function testBuildCorpRejectsEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecurityHeaders::buildCorp('');
    }

    public function testBuildPermissionsPolicyRendersTheDefaultDenyList(): void
    {
        self::assertSame(
            'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            SecurityHeaders::buildPermissionsPolicy(SecurityHeaders::defaultPermissionsPolicy()),
        );
    }

    public function testBuildPermissionsPolicyRendersSelfAndOrigins(): void
    {
        $value = SecurityHeaders::buildPermissionsPolicy([
            'camera' => ['self'],
            'microphone' => ['self', 'https://x.wayne.edu'],
        ]);

        self::assertSame(
            'camera=(self), microphone=(self "https://x.wayne.edu")',
            $value,
        );
    }

    public function testBuildPermissionsPolicyAcceptsOriginWithPort(): void
    {
        self::assertSame(
            'camera=("https://x.wayne.edu:8443")',
            SecurityHeaders::buildPermissionsPolicy(['camera' => ['https://x.wayne.edu:8443']]),
        );
    }

    public function testBuildPermissionsPolicyEmptyMapReturnsNull(): void
    {
        self::assertNull(SecurityHeaders::buildPermissionsPolicy([]));
    }

    public function testBuildPermissionsPolicyRejectsUppercaseFeatureName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feature name "Camera" is invalid');

        SecurityHeaders::buildPermissionsPolicy(['Camera' => []]);
    }

    public function testBuildPermissionsPolicyRejectsFeatureNameStartingWithDigit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecurityHeaders::buildPermissionsPolicy(['9lives' => []]);
    }

    public function testBuildPermissionsPolicyRejectsFeatureNameWithSpace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecurityHeaders::buildPermissionsPolicy(['cam era' => []]);
    }

    public function testBuildPermissionsPolicyRejectsNonStringFeatureName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feature names must be strings');

        SecurityHeaders::buildPermissionsPolicy([0 => []]);
    }

    public function testBuildPermissionsPolicyRejectsNonArrayAllowlist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allowlist for "camera" must be an array');

        SecurityHeaders::buildPermissionsPolicy(['camera' => 'self']);
    }

    public function testBuildPermissionsPolicyRejectsNonStringEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecurityHeaders::buildPermissionsPolicy(['camera' => [123]]);
    }

    public function testBuildPermissionsPolicyRejectsHttpOrigin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an https origin');

        SecurityHeaders::buildPermissionsPolicy(['camera' => ['http://x.wayne.edu']]);
    }

    public function testBuildPermissionsPolicyRejectsOriginWithPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain a path');

        SecurityHeaders::buildPermissionsPolicy(['camera' => ['https://x.wayne.edu/cam']]);
    }

    public function testBuildPermissionsPolicyRejectsOriginWithTrailingSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain a path');

        SecurityHeaders::buildPermissionsPolicy(['camera' => ['https://x.wayne.edu/']]);
    }

    public function testBuildPermissionsPolicyRejectsOriginWithQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain a query or fragment');

        SecurityHeaders::buildPermissionsPolicy(['camera' => ['https://x.wayne.edu?x=1']]);
    }

    public function testBuildPermissionsPolicyRejectsOriginWithFragment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain a query or fragment');

        SecurityHeaders::buildPermissionsPolicy(['camera' => ['https://x.wayne.edu#f']]);
    }

    public function testBuildPermissionsPolicyRejectsOriginWithCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain credentials');

        SecurityHeaders::buildPermissionsPolicy(['camera' => ['https://u:p@x.wayne.edu']]);
    }

    public function testBuildPermissionsPolicyRejectsHostlessOrigin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an https origin with a host');

        SecurityHeaders::buildPermissionsPolicy(['camera' => ['https:']]);
    }

    public function testBuildSendsCoopByDefault(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
        );

        self::assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
    }

    public function testBuildOmitsCoopWhenNull(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            null,
        );

        self::assertArrayNotHasKey('Cross-Origin-Opener-Policy', $headers);
    }

    public function testBuildSendsConfiguredCoop(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'unsafe-none',
        );

        self::assertSame('unsafe-none', $headers['Cross-Origin-Opener-Policy']);
    }

    public function testBuildOmitsCorpByDefault(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
        );

        self::assertArrayNotHasKey('Cross-Origin-Resource-Policy', $headers);
    }

    public function testBuildSendsConfiguredCorp(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'same-origin',
            'same-site',
        );

        self::assertSame('same-site', $headers['Cross-Origin-Resource-Policy']);
    }

    public function testBuildRejectsInvalidCoop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('COOP must be one of');

        SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'bogus',
        );
    }

    public function testBuildRejectsInvalidCorp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CORP must be one of');

        SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'same-origin',
            'bogus',
        );
    }

    public function testBuildSendsConfiguredPermissionsPolicy(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            ['camera' => ['self']],
        );

        self::assertSame('camera=(self)', $headers['Permissions-Policy']);
    }

    public function testBuildOmitsPermissionsPolicyWhenEmpty(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            [],
        );

        self::assertArrayNotHasKey('Permissions-Policy', $headers);
    }

    public function testBuildRejectsInvalidPermissionsPolicy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feature name "Camera" is invalid');

        SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            ['Camera' => []],
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

    // ── HSTS preload ──────────────────────────────────────────────────────

    public function testBuildHstsPreloadAppendsPreloadDirective(): void
    {
        self::assertSame(
            'max-age=31536000; includeSubDomains; preload',
            SecurityHeaders::buildHsts(31536000, true, true),
        );
    }

    public function testBuildHstsPreloadAcceptsLongerMaxAge(): void
    {
        self::assertSame(
            'max-age=63072000; includeSubDomains; preload',
            SecurityHeaders::buildHsts(63072000, true, true),
        );
    }

    public function testBuildHstsPreloadRejectsShortMaxAge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HSTS preload requires a max-age of at least 31536000');

        SecurityHeaders::buildHsts(600, true, true);
    }

    public function testBuildHstsPreloadRejectsNoIncludeSubDomains(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HSTS preload requires a max-age of at least 31536000');

        SecurityHeaders::buildHsts(31536000, false, true);
    }

    public function testBuildSendsPreloadHstsOnHttps(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            true,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            null,
            true,
        );

        self::assertSame(
            'max-age=31536000; includeSubDomains; preload',
            $headers['Strict-Transport-Security'],
        );
    }

    public function testBuildOmitsHstsEntirelyWhenNotHttpsEvenWithPreload(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            null,
            true,
        );

        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function testBuildRejectsPreloadWithShortMaxAge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HSTS preload requires a max-age of at least 31536000');

        SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            true,
            600,
            true,
            null,
            false,
            'same-origin',
            null,
            null,
            true,
        );
    }

    // ── Trusted Types ─────────────────────────────────────────────────────

    public function testBuildTrustedTypesRendersSinglePolicy(): void
    {
        self::assertSame(
            "require-trusted-types-for 'script'; trusted-types 'default'",
            SecurityHeaders::buildTrustedTypes(['default']),
        );
    }

    public function testBuildTrustedTypesRendersMultiplePolicies(): void
    {
        self::assertSame(
            "require-trusted-types-for 'script'; trusted-types 'default' 'my-policy'",
            SecurityHeaders::buildTrustedTypes(['default', 'my-policy']),
        );
    }

    public function testBuildTrustedTypesRejectsEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trusted Types requires at least one policy');

        SecurityHeaders::buildTrustedTypes([]);
    }

    public function testBuildTrustedTypesRejectsPolicyStartingWithDigit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('identifier-like names');

        SecurityHeaders::buildTrustedTypes(['9lives']);
    }

    public function testBuildTrustedTypesRejectsNonStringPolicy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('identifier-like names');

        SecurityHeaders::buildTrustedTypes([123]);
    }

    public function testBuildAppendsTrustedTypesToReportOnlyCsp(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            true,
            'same-origin',
            null,
            null,
            false,
            true,
            ['default'],
        );

        $csp = $headers['Content-Security-Policy-Report-Only'];
        self::assertStringContainsString("require-trusted-types-for 'script'", $csp);
        self::assertStringContainsString("trusted-types 'default'", $csp);
    }

    public function testBuildDoesNotAppendTrustedTypesToEnforcingCsp(): void
    {
        $headers = SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            null,
            false,
            true,
            ['default'],
        );

        $csp = $headers['Content-Security-Policy'];
        self::assertStringNotContainsString('require-trusted-types-for', $csp);
        self::assertStringNotContainsString('trusted-types', $csp);
    }

    public function testBuildValidatesTrustedTypesEvenWhenReportOnlyOff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('identifier-like names');

        SecurityHeaders::build(
            SecurityHeaders::defaultCspPolicy(),
            'testnonce',
            false,
            31536000,
            true,
            null,
            false,
            'same-origin',
            null,
            null,
            false,
            true,
            ['9lives'],
        );
    }

    // ── no-store ──────────────────────────────────────────────────────────

    public function testNoStoreAppliesDisabledReturnsFalse(): void
    {
        self::assertFalse(SecurityHeaders::noStoreApplies(false, '/anything', []));
    }

    public function testNoStoreAppliesEnabledNoPathsReturnsTrue(): void
    {
        self::assertTrue(SecurityHeaders::noStoreApplies(true, '/anything', []));
    }

    public function testNoStoreAppliesEnabledMatchingPathReturnsTrue(): void
    {
        self::assertTrue(SecurityHeaders::noStoreApplies(true, '/api/data', ['/api']));
    }

    public function testNoStoreAppliesEnabledNonMatchingPathReturnsFalse(): void
    {
        self::assertFalse(SecurityHeaders::noStoreApplies(true, '/public', ['/api']));
    }

    public function testNoStoreAppliesIgnoresEmptyPrefix(): void
    {
        self::assertFalse(SecurityHeaders::noStoreApplies(true, '/api', ['']));
    }
}
