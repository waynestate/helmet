<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Support\Facades\Log;

/**
 * H21: trust the proxy's address, not its whole network. When the resolved
 * trusted-proxy list contains a broad entry (an IPv4 range shorter than /16
 * or an IPv6 range shorter than /48), the provider writes one warning,
 * helmet.broad_trusted_proxy, through the Log facade.
 *
 * As in DebugWarningTest, the warning fires while the application boots,
 * during parent::setUp(), so a test cannot prepare its conditions in its own
 * body. Each test instead declares them in the constructor, the only hook
 * PHPUnit runs before setUp, and defineEnvironment() applies them and puts the
 * log expectation in place before the providers boot.
 */
final class BroadProxyWarningTest extends TestCase
{
    /** @var list<string> */
    private array $bootProxies = ['10.0.0.0/8'];
    private bool $bootWarnBroadProxies = true;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $loggedWarnings = [];

    public function __construct(string $name)
    {
        parent::__construct($name);

        // The remaining tests keep the defaults: a broad entry, warning on.
        if ($name === 'testBootStaysQuietForANarrowEntry') {
            $this->bootProxies = ['203.0.113.0/24'];
        } elseif ($name === 'testOptOutSilencesTheWarning') {
            $this->bootWarnBroadProxies = false;
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Keep the debug-in-production warning out of the captured logs so a
        // test sees only the broad-proxy warning.
        $app['config']->set('app.debug', false);
        $app['config']->set('helmet.proxies', $this->bootProxies);
        $app['config']->set('helmet.warn_broad_proxies', $this->bootWarnBroadProxies);

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

    public function testBootLogsTheWarningForABroadEntry(): void
    {
        self::assertCount(1, $this->loggedWarnings);
        self::assertSame('helmet.broad_trusted_proxy', $this->loggedWarnings[0][0]);
        self::assertSame(
            ['event' => 'broad_trusted_proxy', 'entries' => ['10.0.0.0/8']],
            $this->loggedWarnings[0][1],
        );
    }

    public function testBootStaysQuietForANarrowEntry(): void
    {
        self::assertSame([], $this->loggedWarnings);
    }

    public function testOptOutSilencesTheWarning(): void
    {
        self::assertSame([], $this->loggedWarnings);
    }
}
