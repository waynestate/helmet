<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Support;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Support\Setting;
use Waynestate\Playbook\Playbook;

require_once dirname(__DIR__) . '/fixtures/playbook-stub.php';

/**
 * Setting resolves one HELMET setting: the real environment first, then the
 * private waynestate/playbook package (stubbed in tests/fixtures), then
 * null. The stub stands in for the package, so no WSU value ever appears in
 * this public repo.
 */
final class SettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clear('HELMET_PRESET');
        Playbook::reset();
    }

    protected function tearDown(): void
    {
        $this->clear('HELMET_PRESET');
        Playbook::reset();
        parent::tearDown();
    }

    public function testEnvironmentBeatsPlaybook(): void
    {
        $this->put('HELMET_PRESET', 'citadel');
        Playbook::set('HELMET_PRESET', 'docker');

        self::assertSame('citadel', Setting::get('HELMET_PRESET'));
    }

    public function testPlaybookIsUsedWhenTheEnvironmentHasNoValue(): void
    {
        Playbook::set('HELMET_PRESET', 'citadel');

        self::assertSame('citadel', Setting::get('HELMET_PRESET'));
    }

    public function testPlaybookIsUsedWhenTheEnvironmentIsEmpty(): void
    {
        $this->put('HELMET_PRESET', '');
        Playbook::set('HELMET_PRESET', 'citadel');

        self::assertSame('citadel', Setting::get('HELMET_PRESET'));
    }

    public function testNeitherSourceGivesNull(): void
    {
        self::assertNull(Setting::get('HELMET_PRESET'));
    }

    public function testAnEmptyPlaybookValueGivesNull(): void
    {
        Playbook::set('HELMET_PRESET', '');

        self::assertNull(Setting::get('HELMET_PRESET'));
    }

    /**
     * Put a value in the global environment, the same way the Laravel tests
     * do (putenv plus both superglobals).
     */
    private function put(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clear(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
