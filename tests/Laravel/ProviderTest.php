<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Support\ServiceProvider;
use Waynestate\Helmet\Laravel\HelmetServiceProvider;
use Waynestate\Helmet\Laravel\InjectRibbon;
use Waynestate\Helmet\Laravel\SecurityHeaders;

final class ProviderTest extends TestCase
{
    public function testConfigKeyIsMerged(): void
    {
        $config = config('helmet');

        self::assertIsArray($config);
        self::assertArrayHasKey('proxies', $config);
        self::assertArrayHasKey('csp', $config);
        self::assertArrayHasKey('hsts', $config);
        self::assertArrayHasKey('ribbon', $config);
        self::assertArrayHasKey('policy_path', $config);
    }

    /**
     * In a Testbench app the providers boot before the HTTP kernel is first
     * resolved, and resolving the kernel replaces the router's middleware
     * groups with its own. The check therefore runs after the kernel is built,
     * which is the state a request sees. (Checking the router before that
     * passed while every request went out without headers.)
     */
    public function testMiddlewaresAreInTheWebGroupOnceTheKernelIsBuilt(): void
    {
        $this->app->make(Kernel::class);

        $web = $this->app['router']->getMiddlewareGroups()['web'];

        self::assertContains(SecurityHeaders::class, $web);
        self::assertContains(InjectRibbon::class, $web);
        // Appended, so they run inside the framework's own web middleware.
        self::assertSame(
            [SecurityHeaders::class, InjectRibbon::class],
            array_slice($web, -2)
        );
    }

    public function testMiddlewaresAreInTheWebGroupOnlyOnce(): void
    {
        $this->app->make(Kernel::class);

        $counts = array_count_values($this->app['router']->getMiddlewareGroups()['web']);

        self::assertSame(1, $counts[SecurityHeaders::class]);
        self::assertSame(1, $counts[InjectRibbon::class]);
    }

    /**
     * The kernel copies its own group lists over the router's on every
     * append. A middleware that lived only in the router would vanish here.
     */
    public function testMiddlewaresSurviveAnotherPackageAppendingThroughTheKernel(): void
    {
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(FoundationKernel::class, $kernel);

        $kernel->appendMiddlewareToGroup('web', 'Some\\Other\\Package\\Middleware');

        $web = $this->app['router']->getMiddlewareGroups()['web'];
        self::assertContains('Some\\Other\\Package\\Middleware', $web);
        self::assertContains(SecurityHeaders::class, $web);
        self::assertContains(InjectRibbon::class, $web);
    }

    public function testConfigPublishTagExists(): void
    {
        self::assertContains('helmet-config', ServiceProvider::publishableGroups());

        $paths = ServiceProvider::pathsToPublish(HelmetServiceProvider::class, 'helmet-config');
        self::assertNotEmpty($paths);

        $destinations = array_keys($paths);
        self::assertCount(1, $destinations);
        self::assertStringEndsWith('helmet.php', $destinations[0]);
    }
}
