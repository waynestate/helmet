<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Waynestate\Playbook\Playbook;

require_once dirname(__DIR__) . '/fixtures/playbook-stub.php';

/**
 * The Laravel config reads HELMET_PRESET through Setting, so the private
 * waynestate/playbook package can supply the preset when the environment
 * has no value. The stub (tests/fixtures/playbook-stub.php) stands in for
 * the package; the values are preset names, not WSU data.
 */
final class PlaybookPresetTest extends TestCase
{
    protected function setUp(): void
    {
        // The app boots in parent::setUp() and evaluates the config then, so
        // the stub must be clean before the boot, not after it.
        Playbook::reset();
        $this->clearEnv('HELMET_PRESET');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->clearEnv('HELMET_PRESET');
        Playbook::reset();
        parent::tearDown();
    }

    public function testPlaybookSuppliesThePresetWhenTheEnvironmentIsEmpty(): void
    {
        Playbook::set('HELMET_PRESET', 'citadel');
        $this->reloadPackageConfig();

        self::assertSame('citadel', config('helmet.preset'));
        self::assertSame(['172.18.0.1'], config('helmet.proxies'));
    }

    public function testTheEnvironmentBeatsPlaybook(): void
    {
        $this->putEnv('HELMET_PRESET', 'docker');
        Playbook::set('HELMET_PRESET', 'citadel');
        $this->reloadPackageConfig();

        self::assertSame('docker', config('helmet.preset'));
        self::assertSame([], config('helmet.proxies'));
    }

    public function testAnUnknownPresetFromPlaybookStillThrows(): void
    {
        Playbook::set('HELMET_PRESET', 'openshift');

        $this->expectException(\Waynestate\Helmet\Platform\UnknownPresetException::class);
        $this->reloadPackageConfig();
    }

    public function testNeitherEnvironmentNorPlaybookLeavesNoPresetAndNoError(): void
    {
        $this->reloadPackageConfig();

        self::assertNull(config('helmet.preset'));
        self::assertSame([], config('helmet.proxies'));
    }
}
