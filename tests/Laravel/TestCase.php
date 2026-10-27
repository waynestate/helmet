<?php declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Laravel;

use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionObject;
use Waynestate\Helmet\Laravel\HelmetServiceProvider;

/**
 * Base case for the Laravel adapter tests.
 *
 * It registers the HELMET service provider so the `helmet` config is merged
 * and the two middlewares (SecurityHeaders and InjectRibbon) are appended to
 * the `web` middleware group, exactly as they would be in a real application.
 *
 * A note on env vars: the package config (config/helmet.php) is the only place
 * that reads env(), and it is evaluated while the application is being created
 * (during parent::setUp(), when the provider registers). A test that wants to
 * feed an env var into the config therefore sets it in the global environment
 * and re-requires the config file, rather than relying on Testbench's
 * defineEnvironment() (which runs after the provider has already registered).
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [HelmetServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The `web` middleware group includes EncryptCookies, which needs an
        // application key. Testbench does not set one, so give the test app a
        // throwaway key (generated per run, never a real secret).
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    /**
     * Put a value in the global environment so env() sees it.
     */
    protected function putEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * Remove a value from the global environment.
     */
    protected function clearEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    /**
     * Re-evaluate the package config file against the current environment and
     * store the result under the `helmet` config key.
     *
     * This mirrors what mergeConfigFrom() does at boot time, but lets a test
     * change the environment first and observe the effect.
     */
    protected function reloadPackageConfig(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/helmet.php';

        $this->app['config']->set('helmet', $config);
    }

    /**
     * Read the kernel's global middleware list (a protected property with no
     * public getter).
     *
     * @return list<string>
     */
    protected function globalMiddlewareList(FoundationKernel $kernel): array
    {
        $property = (new ReflectionObject($kernel))->getProperty('middleware');

        return $property->getValue($kernel);
    }

    /**
     * Server variables for an HTTPS request that arrived through the trusted
     * proxy 10.0.0.1 (tests add that address to helmet.proxies).
     *
     * @return array<string, string>
     */
    protected function httpsViaTrustedProxy(): array
    {
        return [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
    }
}
