<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Dotenv\Dotenv;
use Waynestate\Helmet\Headers\SecurityHeaders;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_PROXIES');
        $this->clearEnv('HELMET_CSP');
        $this->clearEnv('HELMET_HSTS_MAX_AGE');
        $this->clearEnv('HELMET_RIBBON');

        parent::tearDown();
    }

    public function testProxiesEnvVarIsSplitIntoAList(): void
    {
        $this->putEnv('HELMET_PROXIES', '10.0.0.1, 10.0.0.0/8');
        $this->reloadPackageConfig();

        self::assertSame(['10.0.0.1', '10.0.0.0/8'], config('helmet.proxies'));
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
            config('helmet.csp')
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
}
