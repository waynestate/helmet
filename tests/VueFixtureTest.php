<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * The Vue fixture (tools/vue-vite) and its browser check
 * (tools/a11y/vue-check.mjs) prove README's "Raw PHP with a Vue (Vite) build"
 * recipe in Chromium (H51). The proof is only worth something while the
 * three agree on one placeholder: the default of helmet_csp_nonce_swap(),
 * the html.cspNonce the fixture builds with, and the string the check
 * swaps. The fixture's packages are exact versions so the lock and the
 * manifest cannot drift apart quietly. A helmet that fits a different head
 * is just a hat.
 */
final class VueFixtureTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private function defaultPlaceholder(): string
    {
        $parameter = (new ReflectionFunction('Waynestate\Helmet\helmet_csp_nonce_swap'))->getParameters()[2];
        $default = $parameter->getDefaultValue();
        $this->assertIsString($default);

        return $default;
    }

    public function testTheFixtureBuildsWithTheSwapDefaultPlaceholder(): void
    {
        $config = (string) file_get_contents(self::ROOT . '/tools/vue-vite/vite.config.js');

        $this->assertStringContainsString(
            "cspNonce: '" . $this->defaultPlaceholder() . "'",
            $config,
            'tools/vue-vite/vite.config.js must set html.cspNonce to the default placeholder of helmet_csp_nonce_swap().',
        );
    }

    public function testTheBrowserCheckSwapsTheSamePlaceholder(): void
    {
        $check = (string) file_get_contents(self::ROOT . '/tools/a11y/vue-check.mjs');

        $this->assertStringContainsString(
            'const PLACEHOLDER = "' . $this->defaultPlaceholder() . '";',
            $check,
            'tools/a11y/vue-check.mjs must swap the default placeholder of helmet_csp_nonce_swap().',
        );
    }

    public function testTheReadmeDocumentsTheSamePlaceholder(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');

        $this->assertStringContainsString("cspNonce: '" . $this->defaultPlaceholder() . "'", $readme);
    }

    public function testTheFixturePinsExactVersionsThatTheLockAgreesWith(): void
    {
        /** @var array{dependencies: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents(self::ROOT . '/tools/vue-vite/package.json'), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{packages: array<string, array{version?: string}>} $lock */
        $lock = json_decode((string) file_get_contents(self::ROOT . '/tools/vue-vite/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['@vitejs/plugin-vue', 'vite', 'vue'], array_keys($manifest['dependencies']));
        foreach ($manifest['dependencies'] as $name => $version) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, $name . ' must be an exact version in tools/vue-vite/package.json.');
            $this->assertSame($version, $lock['packages']['node_modules/' . $name]['version'] ?? null, $name . ' in package-lock.json does not match package.json; run npm install in tools/vue-vite.');
        }
    }
}
