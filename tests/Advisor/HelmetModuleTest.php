<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Advisor;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Waynestate\Advisor\Advisor;
use Waynestate\Advisor\Config;
use Waynestate\Advisor\Content\ContentBundle;
use Waynestate\Advisor\Exception\ToolInputError;
use Waynestate\Advisor\Module\ModuleContext;
use Waynestate\Advisor\Playbook\Settings;
use Waynestate\Advisor\Security\PathGuard;
use Waynestate\Advisor\Security\ProcessOutput;
use Waynestate\Advisor\Security\ProcessRunner;
use Waynestate\Advisor\Support\FixedClock;
use Waynestate\Helmet\Advisor\HelmetModule;
use Waynestate\Helmet\Headers\SecurityHeaders;
use Waynestate\Helmet\Policy\JsonFilePolicySource;
use Waynestate\Helmet\Policy\VersionWarning;

/**
 * The ADVISOR module against fixture apps, with ADVISOR's real bundled
 * content and a process runner that fails loudly if a tool ever tries to
 * start a subprocess.
 */
final class HelmetModuleTest extends TestCase
{
    private const TODAY = '2026-11-07';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/helmet-advisor-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testTheModuleDeclaresTwoReadOnlyToolsInItsOwnGroup(): void
    {
        $module = $this->module();

        self::assertSame('helmet', $module->id());
        self::assertSame(['helmet_headers', 'helmet_php_support'], array_map(static fn($t) => $t->name, $module->tools()));
        foreach ($module->tools() as $tool) {
            self::assertTrue($tool->readOnly, $tool->name . ' must be declared read-only');
            self::assertFalse($tool->openWorld, $tool->name . ' must not reach the network');
            self::assertSame('helmet', $tool->group);
            self::assertSame(false, $tool->inputSchema['additionalProperties']);
            self::assertSame(['path'], array_keys($tool->inputSchema['properties']));
            self::assertStringContainsString('HELMET reports; PROCTOR decides.', $tool->description);
        }
        self::assertSame([], $module->resources());
        self::assertSame([], $module->prompts());
    }

    public function testHeadersAreExactlyWhatSecurityHeadersBuildsEachWithAReason(): void
    {
        $app = $this->app('plain');
        $result = $this->headers(['path' => 'plain']);

        $expected = SecurityHeaders::build(SecurityHeaders::defaultCspPolicy(), HelmetModule::NONCE_PLACEHOLDER, true);
        $sent = [];
        foreach ($result['headers'] as $header) {
            $sent[$header['name']] = $header['value'];
            self::assertNotNull($header['reason'], $header['name'] . ' needs a reason');
        }
        self::assertSame($expected, $sent);
        self::assertSame('HTTPS responses only', $result['headers'][array_search('Strict-Transport-Security', array_keys($sent), true)]['when']);

        self::assertSame('helmet', $result['tool']);
        self::assertSame('plain', $result['app']);
        self::assertStringContainsString('in-process', $result['ran']);
        self::assertStringContainsString('no .env read', $result['ran']);
        self::assertStringEndsWith('HELMET reports; PROCTOR decides.', $result['notice']);
        self::assertStringContainsString('src/Headers/SecurityHeaders.php', $result['source']['code']);
        self::assertSame('X-Powered-By', $result['removed'][0]['name']);
        self::assertDirectoryExists($app);
    }

    public function testEveryDefaultDirectiveComesBackWithItsSourcesAndReason(): void
    {
        $this->app('plain');
        $result = $this->headers(['path' => 'plain']);

        $byDirective = [];
        foreach ($result['csp']['directives'] as $directive) {
            self::assertNotNull($directive['reason'], $directive['directive'] . ' needs a reason');
            $byDirective[$directive['directive']] = $directive['sources'];
        }
        self::assertSame(array_keys(SecurityHeaders::defaultCspPolicy()), array_keys($byDirective));

        // The nonce and strict-dynamic are on the wire, so they are in the answer.
        self::assertContains("'nonce-" . HelmetModule::NONCE_PLACEHOLDER . "'", $byDirective['script-src']);
        self::assertContains("'strict-dynamic'", $byDirective['script-src']);
        self::assertContains("'unsafe-inline'", $byDirective['style-src-attr']);
        self::assertNotContains("'unsafe-inline'", $byDirective['style-src-elem']);
        self::assertSame(["'none'"], $byDirective['object-src']);
        self::assertStringContainsString(HelmetModule::NONCE_PLACEHOLDER, $result['csp']['nonce']);
    }

    public function testWiringReportsTheLockedVersionTheFrameworkAndAnUnchangedPublishedConfig(): void
    {
        $app = $this->app('laravel-app');
        $this->write($app . '/composer.lock', json_encode(['packages' => [
            ['name' => 'laravel/framework', 'version' => 'v12.40.0'],
            ['name' => 'waynestate/helmet', 'version' => '0.3.1'],
        ]], JSON_THROW_ON_ERROR));
        mkdir($app . '/config');
        copy(dirname(__DIR__, 2) . '/config/helmet.php', $app . '/config/helmet.php');

        $wiring = $this->headers(['path' => 'laravel-app'])['wiring'];

        self::assertSame('0.3.1', $wiring['installed']);
        self::assertTrue($wiring['laravel']);
        self::assertSame('matches the shipped default', $wiring['published_config']);
        self::assertSame([], $wiring['boot_calls']);
        self::assertStringContainsString('0.3.1', implode(' ', $wiring['notes']));
    }

    public function testWiringFlagsAnEditedConfigAndAHelmetBootCall(): void
    {
        $app = $this->app('raw-app');
        mkdir($app . '/config');
        $this->write($app . '/config/helmet.php', "<?php\n\nreturn ['csp' => ['img-src' => ['https://example.edu']]];\n");
        mkdir($app . '/public');
        $this->write($app . '/public/index.php', "<?php\nrequire __DIR__ . '/../vendor/autoload.php';\n\$helmet = helmet_boot(['preset' => 'citadel']);\n");

        $wiring = $this->headers(['path' => 'raw-app'])['wiring'];

        self::assertNull($wiring['installed']);
        self::assertFalse($wiring['laravel']);
        self::assertSame('edited', $wiring['published_config']);
        self::assertSame(['public/index.php'], $wiring['boot_calls']);
        self::assertStringContainsString('No composer.lock', implode(' ', $wiring['notes']));
    }

    public function testPhpSupportReportsTheFloorFromTheBundledCreedPolicy(): void
    {
        $this->app('app', ['require' => ['php' => '^8.3'], 'config' => ['platform' => ['php' => '8.3.0']]]);
        $result = $this->phpSupport(['path' => 'app']);

        self::assertSame('^8.3', $result['constraint']);
        self::assertNull($result['constraint_problem']);
        self::assertSame(self::TODAY, $result['date']);
        self::assertSame('8.3', $result['floor']['version']);
        self::assertSame('security', $result['floor']['status']);
        self::assertSame('2025-12-31', $result['floor']['active_until']);
        self::assertSame('2027-12-31', $result['floor']['security_until']);
        self::assertSame(419, $result['floor']['days_of_security_left']);
        self::assertSame('8.3.0', $result['platform_pin']['declared']);
        self::assertSame('8.3', $result['platform_pin']['version']);
        self::assertSame(['8.3', '8.4', '8.5'], array_column($result['allowed'], 'version'));
        self::assertSame(['security', 'active', 'active'], array_column($result['allowed'], 'status'));
        self::assertStringContainsString('security-only', $result['summary']);
        self::assertStringContainsString('2027-12-31', $result['summary']);

        // The warning is the ribbon's own text, from the same policy file.
        $policy = new JsonFilePolicySource($this->bundle()->policyFile());
        $ribbon = (new VersionWarning($policy, new DateTimeImmutable(self::TODAY)))->warning('php', '8.3');
        self::assertSame($ribbon, $result['floor']['warning']);

        self::assertSame('creed/policy/policy.json', $result['source']['policy']);
        self::assertNotNull($result['source']['policy_refreshed_on']);
        self::assertSame('app/composer.json', $result['source']['app_file']);
        self::assertStringContainsString('JsonFilePolicySource', $result['ran']);
        self::assertStringContainsString('HELMET reports; PROCTOR decides.', $result['notice']);
    }

    public function testAnEndOfLifeFloorSaysSo(): void
    {
        $this->app('old', ['require' => ['php' => '>=8.1']]);
        $result = $this->phpSupport(['path' => 'old']);

        self::assertSame('8.1', $result['floor']['version']);
        self::assertSame('eol', $result['floor']['status']);
        self::assertLessThan(0, $result['floor']['days_of_security_left']);
        self::assertNull($result['platform_pin']);
        self::assertStringContainsString('end of life', $result['summary']);
        self::assertSame('8.1', $result['allowed'][0]['version']);
    }

    public function testMissingUnboundedAndBrokenConstraintsAreExplainedNotGuessed(): void
    {
        $this->app('none', ['name' => 'acme/none']);
        $none = $this->phpSupport(['path' => 'none']);
        self::assertNull($none['constraint']);
        self::assertNull($none['floor']);
        self::assertStringContainsString('declares no PHP constraint', $none['summary']);

        $this->app('any', ['require' => ['php' => '*']]);
        $any = $this->phpSupport(['path' => 'any']);
        self::assertNull($any['floor']);
        self::assertStringContainsString('no lower bound', $any['summary']);

        $this->app('broken', ['require' => ['php' => 'banana']]);
        $broken = $this->phpSupport(['path' => 'broken']);
        self::assertNull($broken['floor']);
        self::assertNotNull($broken['constraint_problem']);
        self::assertSame($broken['constraint_problem'], $broken['summary']);
    }

    public function testAFolderWithoutComposerJsonIsRefused(): void
    {
        $app = $this->root . '/empty';
        mkdir($app);

        $this->expectException(ToolInputError::class);
        $this->expectExceptionMessage('composer.json');
        $this->phpSupport(['path' => 'empty']);
    }

    public function testPathsOutsideTheProjectRootAreRefused(): void
    {
        $this->app('app', ['require' => ['php' => '^8.3']]);
        $outside = dirname($this->root);

        foreach ([['path' => $outside], ['path' => '../..'], ['path' => '~/.ssh'], ['path' => 'app/composer.json']] as $args) {
            foreach ([0, 1] as $tool) {
                try {
                    ($this->module()->tools()[$tool]->handler)($args);
                    self::fail('Expected a refusal for ' . json_encode($args));
                } catch (ToolInputError $e) {
                    self::assertMatchesRegularExpression('/project|directory/', $e->getMessage());
                }
            }
        }
    }

    public function testTheToolsWriteNothing(): void
    {
        $app = $this->app('app', ['require' => ['php' => '^8.3']]);
        mkdir($app . '/public');
        $this->write($app . '/public/index.php', "<?php\nhelmet_boot([]);\n");
        $before = $this->snapshot();

        $this->headers(['path' => 'app']);
        $this->phpSupport(['path' => 'app']);
        $this->headers([]);

        self::assertSame($before, $this->snapshot());
    }

    public function testComposerJsonKeepsAdvisorOutOfRequire(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('waynestate/advisor', $json['require']);
        self::assertArrayHasKey('waynestate/advisor', $json['require-dev']);
        self::assertArrayHasKey('waynestate/advisor', $json['suggest']);
    }

    private function module(): HelmetModule
    {
        $processes = new class implements ProcessRunner {
            public function run(array $argv, string $cwd, int $timeoutSeconds): ProcessOutput
            {
                throw new RuntimeException('A HELMET tool started a subprocess; the tools must run in-process.');
            }
        };

        return new HelmetModule(new ModuleContext(
            Config::fromEnv(['ADVISOR_ENABLED' => '1', 'ADVISOR_PROJECT_ROOT' => $this->root]),
            $this->bundle(),
            new FixedClock(self::TODAY),
            new PathGuard($this->root),
            $processes,
            new Settings(),
        ));
    }

    private function bundle(): ContentBundle
    {
        return new ContentBundle(dirname((string) (new ReflectionClass(Advisor::class))->getFileName()) . '/../data');
    }

    /** @param array<string, mixed> $args */
    private function headers(array $args): array
    {
        return ($this->module()->tools()[0]->handler)($args);
    }

    /** @param array<string, mixed> $args */
    private function phpSupport(array $args): array
    {
        return ($this->module()->tools()[1]->handler)($args);
    }

    /** @param array<string, mixed>|null $composer */
    private function app(string $name, ?array $composer = null): string
    {
        $dir = $this->root . '/' . $name;
        mkdir($dir, 0777, true);
        if ($composer !== null) {
            $this->write($dir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return $dir;
    }

    private function write(string $file, string $contents): void
    {
        file_put_contents($file, $contents);
    }

    /** @return array<string, string> path to hash, for every file under the root */
    private function snapshot(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $out[$file->getPathname()] = (string) hash_file('sha256', $file->getPathname()) . '@' . $file->getMTime();
            }
        }
        ksort($out);

        return $out;
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
