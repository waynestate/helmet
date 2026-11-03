<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Support\Facades\Log;

/**
 * H43: keep debug output off in production. When the app boots with
 * app.debug true in the production environment, the provider writes one
 * warning, helmet.debug_enabled_in_production, through the Log facade.
 *
 * The warning fires while the application boots, during parent::setUp(), so
 * a test cannot prepare its conditions in its own body. Each test instead
 * declares them in the constructor, the only hook PHPUnit runs before
 * setUp, and defineEnvironment() applies them and puts the log expectation
 * in place before the providers boot.
 */
final class DebugWarningTest extends TestCase
{
    private mixed $bootDebug = true;
    private string $bootEnvironment = 'production';
    private bool $bootWarnDebug = true;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $loggedWarnings = [];

    public function __construct(string $name)
    {
        parent::__construct($name);

        // The remaining tests keep the defaults: debug on, production,
        // warning enabled.
        match ($name) {
            'testDebugWarningIsNotLoggedWhenDebugIsOff' => $this->bootDebug = false,
            'testDebugWarningIsNotLoggedOutsideProduction' => $this->bootEnvironment = 'local',
            'testDebugWarningIsNotLoggedWhenOptedOut' => $this->bootWarnDebug = false,
            'testDebugWarningIsNotLoggedWhenDebugIsAString' => $this->bootDebug = 'false',
            default => null,
        };
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Testbench pins the container's "env" binding to "testing" while
        // the application is created, before this hook runs, and the
        // framework does not re-detect it once it is bound. Set the binding
        // and the matching config value here, while the provider has not
        // booted yet, so the boot-time check sees the intended environment.
        $app['config']->set('app.debug', $this->bootDebug);
        $app['config']->set('app.env', $this->bootEnvironment);
        $app['config']->set('helmet.warn_debug', $this->bootWarnDebug);
        $app->instance('env', $this->bootEnvironment);

        // The warning fires while the providers boot, after this method
        // returns, so the expectation has to be in place now. Every warning
        // is captured and asserted in the test body, so a stray warning
        // fails the assertion instead of going unseen.
        Log::shouldReceive('warning')
            ->zeroOrMoreTimes()
            ->withArgs(function (mixed $message, mixed $context = []): bool {
                $this->loggedWarnings[] = [$message, $context];

                return true;
            });
    }

    public function testDebugWarningIsLoggedOnceWhenDebugIsOnInProduction(): void
    {
        self::assertCount(1, $this->loggedWarnings);
        self::assertSame('helmet.debug_enabled_in_production', $this->loggedWarnings[0][0]);
        self::assertSame(['event' => 'debug_enabled_in_production'], $this->loggedWarnings[0][1]);
    }

    public function testDebugWarningIsNotLoggedWhenDebugIsOff(): void
    {
        self::assertSame([], $this->loggedWarnings);
    }

    public function testDebugWarningIsNotLoggedOutsideProduction(): void
    {
        self::assertSame([], $this->loggedWarnings);
    }

    public function testDebugWarningIsNotLoggedWhenOptedOut(): void
    {
        self::assertSame([], $this->loggedWarnings);
    }

    public function testDebugWarningIsNotLoggedWhenDebugIsAString(): void
    {
        self::assertSame([], $this->loggedWarnings);
    }

    public function testDebugWarningContextCarriesOnlyTheEventKey(): void
    {
        self::assertCount(1, $this->loggedWarnings);

        [$message, $context] = $this->loggedWarnings[0];

        self::assertSame('helmet.debug_enabled_in_production', $message);
        self::assertSame(['event'], array_keys($context));
        self::assertSame('debug_enabled_in_production', $context['event']);
    }
}
