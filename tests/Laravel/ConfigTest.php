<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Waynestate\Helmet\Headers\SecurityHeaders;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_PROXIES');
        $this->clearEnv('HELMET_CSP');

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
}
