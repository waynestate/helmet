<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Platform;

use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Platform\Presets;
use Waynestate\Helmet\Platform\UnknownPresetException;

final class PresetsTest extends TestCase
{
    public function testCitadelPresetTrustsTheDockerBridgeGateway(): void
    {
        self::assertSame(['172.18.0.1'], Presets::proxies('citadel'));
    }

    public function testCitadelPresetDocumentsNatAssumption(): void
    {
        $readme = dirname(__DIR__, 2) . '/README.md';
        $text = file_get_contents($readme);
        self::assertIsString($text, 'README.md must exist at the repository root.');

        $pos = strpos($text, 'source-NATed');
        self::assertNotFalse(
            $pos,
            'README.md must document the citadel preset NAT assumption.',
        );

        // The note belongs to the citadel preset, so the word must sit in
        // the same paragraph: the 600 characters leading up to the phrase.
        $window = substr($text, max(0, $pos - 600), 600);
        self::assertMatchesRegularExpression('/\bcitadel\b/i', $window);
    }

    public function testDockerPresetTrustsNothing(): void
    {
        self::assertSame([], Presets::proxies('docker'));
    }

    public function testNamesListsBothPresets(): void
    {
        self::assertSame(['citadel', 'docker'], Presets::names());
    }

    public function testExistsIsTrueForKnownPresets(): void
    {
        self::assertTrue(Presets::exists('citadel'));
        self::assertTrue(Presets::exists('docker'));
    }

    public function testExistsIsFalseForUnknownPresets(): void
    {
        self::assertFalse(Presets::exists('openshift'));
        self::assertFalse(Presets::exists(''));
        self::assertFalse(Presets::exists('Citadel'));
    }

    public function testTrustedHeadersAreTheSameForEveryPreset(): void
    {
        self::assertSame(
            ['X-Forwarded-For', 'X-Forwarded-Proto'],
            Presets::TRUSTED_HEADERS,
        );
    }

    public function testTrustedHeadersNeverIncludeHostPrefixOrPort(): void
    {
        // A platform that rewrites the host (OpenShift appends the route
        // host) would let a client pre-seed Host and Prefix, and Citadel's
        // HA-Proxy never sets Port at all, so a client value would pass
        // through untouched. All three stay out.
        self::assertNotContains('X-Forwarded-Host', Presets::TRUSTED_HEADERS);
        self::assertNotContains('X-Forwarded-Prefix', Presets::TRUSTED_HEADERS);
        self::assertNotContains('X-Forwarded-Port', Presets::TRUSTED_HEADERS);
    }

    public function testUnknownPresetThrowsNamingThePresetAndTheKnownNames(): void
    {
        try {
            Presets::proxies('openshift');
            self::fail('Expected an UnknownPresetException.');
        } catch (UnknownPresetException $e) {
            self::assertStringContainsString('openshift', $e->getMessage());
            self::assertStringContainsString('citadel', $e->getMessage());
            self::assertStringContainsString('docker', $e->getMessage());
        }
    }

    public function testUnknownPresetExceptionIsAnInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Presets::proxies('nope');
    }
}
