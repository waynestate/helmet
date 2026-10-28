<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Dotenv\Dotenv;
use Waynestate\Helmet\Headers\SecurityHeaders;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_PROXIES');
        $this->clearEnv('HELMET_PRESET');
        $this->clearEnv('HELMET_SCOPE');
        $this->clearEnv('HELMET_CSP');
        $this->clearEnv('HELMET_HSTS_MAX_AGE');
        $this->clearEnv('HELMET_HSTS_INCLUDE_SUBDOMAINS');
        $this->clearEnv('HELMET_RIBBON');
        $this->clearEnv('HELMET_CSP_REPORT_URI');
        $this->clearEnv('HELMET_CSP_REPORT_ONLY');
        $this->clearEnv('HELMET_COOP');
        $this->clearEnv('HELMET_CORP');
        $this->clearEnv('HELMET_PERMISSIONS_POLICY');

        parent::tearDown();
    }

    public function testProxiesEnvVarIsSplitIntoAList(): void
    {
        $this->putEnv('HELMET_PROXIES', '10.0.0.1, 10.0.0.0/8');
        $this->reloadPackageConfig();

        self::assertSame(['10.0.0.1', '10.0.0.0/8'], config('helmet.proxies'));
    }

    public function testPresetEnvVarFillsProxies(): void
    {
        $this->putEnv('HELMET_PRESET', 'citadel');
        $this->reloadPackageConfig();
        self::assertSame(['172.18.0.1'], config('helmet.proxies'));
        self::assertSame('citadel', config('helmet.preset'));

        $this->putEnv('HELMET_PRESET', 'docker');
        $this->reloadPackageConfig();
        self::assertSame([], config('helmet.proxies'));
        self::assertSame('docker', config('helmet.preset'));
    }

    public function testNoPresetLeavesPresetNullAndProxiesEmpty(): void
    {
        self::assertNull(config('helmet.preset'));
        self::assertSame([], config('helmet.proxies'));
    }

    public function testProxiesEnvVarWinsOverPreset(): void
    {
        $this->putEnv('HELMET_PRESET', 'citadel');
        $this->putEnv('HELMET_PROXIES', '10.0.0.1');
        $this->reloadPackageConfig();

        self::assertSame(['10.0.0.1'], config('helmet.proxies'));
        // The preset is still reported, but the explicit list is what is used.
        self::assertSame('citadel', config('helmet.preset'));
    }

    public function testUnknownPresetThrowsAtConfigLoad(): void
    {
        $this->putEnv('HELMET_PRESET', 'openshift');

        $this->expectException(\Waynestate\Helmet\Platform\UnknownPresetException::class);
        $this->reloadPackageConfig();
    }

    public function testScopeEnvVarIsReadWithGlobalAsTheDefault(): void
    {
        self::assertSame('global', config('helmet.scope'));

        $this->putEnv('HELMET_SCOPE', 'web');
        $this->reloadPackageConfig();
        self::assertSame('web', config('helmet.scope'));

        $this->putEnv('HELMET_SCOPE', 'global');
        $this->reloadPackageConfig();
        self::assertSame('global', config('helmet.scope'));
    }

    public function testUnknownScopeFallsBackToGlobal(): void
    {
        $this->putEnv('HELMET_SCOPE', 'everywhere');
        $this->reloadPackageConfig();

        self::assertSame('global', config('helmet.scope'));
    }

    public function testHstsIncludeSubDomainsEnvVarIsReadWithTrueAsTheDefault(): void
    {
        self::assertTrue(config('helmet.hsts_include_subdomains'));

        $this->putEnv('HELMET_HSTS_INCLUDE_SUBDOMAINS', 'false');
        $this->reloadPackageConfig();
        self::assertFalse(config('helmet.hsts_include_subdomains'));

        $this->putEnv('HELMET_HSTS_INCLUDE_SUBDOMAINS', 'true');
        $this->reloadPackageConfig();
        self::assertTrue(config('helmet.hsts_include_subdomains'));
    }

    public function testValidCspEnvVarOverridesTheDefault(): void
    {
        $custom = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", 'https://env-csp-test.example'],
        ];
        $this->putEnv('HELMET_CSP', json_encode($custom));
        $this->reloadPackageConfig();

        self::assertSame($custom, config('helmet.csp'));
    }

    public function testInvalidCspEnvVarKeepsTheDefault(): void
    {
        $this->putEnv('HELMET_CSP', '{not valid json');
        $this->reloadPackageConfig();

        self::assertSame(SecurityHeaders::defaultCspPolicy(), config('helmet.csp'));
    }

    public function testEmptyCspEnvVarKeepsTheDefault(): void
    {
        $this->putEnv('HELMET_CSP', '');
        $this->reloadPackageConfig();

        self::assertSame(SecurityHeaders::defaultCspPolicy(), config('helmet.csp'));
    }

    /**
     * The README shows HELMET_CSP written for a .env file. Feed that exact
     * line through the .env parser Laravel uses, to prove the quoting works.
     */
    public function testCspLineFromTheReadmeSurvivesTheDotEnvParser(): void
    {
        $line = 'HELMET_CSP="{\"img-src\":[\"\'self\'\",\"data:\"],\"form-action\":null}"';
        $this->putEnv('HELMET_CSP', Dotenv::parse($line)['HELMET_CSP']);
        $this->reloadPackageConfig();

        self::assertSame(
            ['img-src' => ["'self'", 'data:'], 'form-action' => null],
            config('helmet.csp'),
        );
    }

    public function testOtherEnvVarsAreReadWithTheirDefaults(): void
    {
        self::assertSame(31536000, config('helmet.hsts'));
        self::assertTrue(config('helmet.ribbon'));
        self::assertSame([], config('helmet.proxies'));

        $this->putEnv('HELMET_HSTS_MAX_AGE', '600');
        $this->putEnv('HELMET_RIBBON', 'false');
        $this->reloadPackageConfig();

        self::assertSame(600, config('helmet.hsts'));
        self::assertFalse(config('helmet.ribbon'));
    }

    public function testCspReportUriEnvVarIsReadWithNullAsTheDefault(): void
    {
        self::assertNull(config('helmet.csp_report_uri'));

        $this->putEnv('HELMET_CSP_REPORT_URI', 'https://example.com/csp-report');
        $this->reloadPackageConfig();
        self::assertSame('https://example.com/csp-report', config('helmet.csp_report_uri'));

        // An empty value is treated as "no endpoint", the same as unset.
        $this->putEnv('HELMET_CSP_REPORT_URI', '');
        $this->reloadPackageConfig();
        self::assertNull(config('helmet.csp_report_uri'));
    }

    public function testCspReportOnlyEnvVarIsReadWithFalseAsTheDefault(): void
    {
        self::assertFalse(config('helmet.csp_report_only'));

        $this->putEnv('HELMET_CSP_REPORT_ONLY', 'true');
        $this->reloadPackageConfig();
        self::assertTrue(config('helmet.csp_report_only'));

        $this->putEnv('HELMET_CSP_REPORT_ONLY', 'false');
        $this->reloadPackageConfig();
        self::assertFalse(config('helmet.csp_report_only'));
    }

    public function testCoopEnvVarIsReadWithSameOriginAsTheDefault(): void
    {
        self::assertSame('same-origin', config('helmet.coop'));

        $this->putEnv('HELMET_COOP', 'unsafe-none');
        $this->reloadPackageConfig();
        self::assertSame('unsafe-none', config('helmet.coop'));

        // An empty value is treated as "no COOP header", the same as null.
        $this->putEnv('HELMET_COOP', '');
        $this->reloadPackageConfig();
        self::assertNull(config('helmet.coop'));
    }

    public function testCorpEnvVarIsReadWithNullAsTheDefault(): void
    {
        self::assertNull(config('helmet.corp'));

        $this->putEnv('HELMET_CORP', 'same-site');
        $this->reloadPackageConfig();
        self::assertSame('same-site', config('helmet.corp'));

        // An empty value is treated as "no CORP header", the same as unset.
        $this->putEnv('HELMET_CORP', '');
        $this->reloadPackageConfig();
        self::assertNull(config('helmet.corp'));
    }

    public function testPermissionsPolicyEnvVarIsReadWithNullAsTheDefault(): void
    {
        self::assertNull(config('helmet.permissions_policy'));

        $this->putEnv('HELMET_PERMISSIONS_POLICY', '{"camera":["self"]}');
        $this->reloadPackageConfig();
        self::assertSame(['camera' => ['self']], config('helmet.permissions_policy'));

        // An empty JSON object is meaningful: it sends no Permissions-Policy
        // header, so it must survive as an empty array, not fall back to null.
        $this->putEnv('HELMET_PERMISSIONS_POLICY', '{}');
        $this->reloadPackageConfig();
        self::assertSame([], config('helmet.permissions_policy'));

        // Invalid JSON keeps the default (null), like HELMET_CSP.
        $this->putEnv('HELMET_PERMISSIONS_POLICY', '{not valid json');
        $this->reloadPackageConfig();
        self::assertNull(config('helmet.permissions_policy'));

        // An empty value is treated as "not set", the same as unset.
        $this->putEnv('HELMET_PERMISSIONS_POLICY', '');
        $this->reloadPackageConfig();
        self::assertNull(config('helmet.permissions_policy'));
    }
}
