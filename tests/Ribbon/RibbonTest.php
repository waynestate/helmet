<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Ribbon;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Ribbon\Ribbon;

use function Waynestate\Helmet\Ribbon\helmet_ribbon;

final class RibbonTest extends TestCase
{
    private const POLICY = __DIR__ . '/../fixtures/policy.json';

    /**
     * php 8.3 is active until 2027-11-30, so on 2026-10-28 it is active and
     * the ribbon shows no warning line.
     */
    public function testActiveVersionShowsNoWarningLine(): void
    {
        $ribbon = new Ribbon(
            'staging',
            true,
            self::POLICY,
            [['php', '8.3']],
            new DateTimeImmutable('2026-10-28'),
        );

        self::assertSame(
            '<div class="helmet-ribbon" data-environment="staging">staging</div>',
            $ribbon->html(),
        );
    }

    /**
     * php 8.3 is in its security-only phase from 2027-12-01 to 2028-11-30, so
     * on 2028-01-01 the ribbon shows a security-only warning line.
     */
    public function testSecurityOnlyVersionShowsWarningLine(): void
    {
        $ribbon = new Ribbon(
            'staging',
            true,
            self::POLICY,
            [['php', '8.3']],
            new DateTimeImmutable('2028-01-01'),
        );

        $html = $ribbon->html();

        self::assertStringContainsString(
            '<div class="helmet-ribbon-warning">PHP 8.3 is in its security-only phase and will not receive new features. Upgrade to a supported version.</div>',
            $html,
        );
        // The environment label is still present.
        self::assertStringContainsString('data-environment="staging"', $html);
    }

    /**
     * php 8.3 is end of life after 2028-11-30, so on 2029-01-01 the ribbon
     * shows an end-of-life warning line.
     */
    public function testEolVersionShowsWarningLine(): void
    {
        $ribbon = new Ribbon(
            'staging',
            true,
            self::POLICY,
            [['php', '8.3']],
            new DateTimeImmutable('2029-01-01'),
        );

        $html = $ribbon->html();

        self::assertStringContainsString(
            '<div class="helmet-ribbon-warning">PHP 8.3 is end of life and no longer receives security updates. Upgrade to a supported version.</div>',
            $html,
        );
    }

    /**
     * With no policy path the ribbon shows no warning line, even though a
     * policy file exists that would produce one. No file is read.
     */
    public function testNoPolicyPathShowsNoWarningLine(): void
    {
        $ribbon = new Ribbon(
            'staging',
            true,
            null,
            [['php', '8.3']],
            new DateTimeImmutable('2028-01-01'),
        );

        self::assertSame(
            '<div class="helmet-ribbon" data-environment="staging">staging</div>',
            $ribbon->html(),
        );
    }

    /**
     * A broken policy file (invalid JSON) produces no warning and never breaks
     * the page: the ribbon still renders the environment label.
     */
    public function testBrokenPolicyFileStillRendersRibbon(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'helmet-policy-');
        self::assertIsString($path);
        file_put_contents($path, '{ this is not valid json');

        try {
            $ribbon = new Ribbon(
                'staging',
                true,
                $path,
                [['php', '8.3']],
                new DateTimeImmutable('2028-01-01'),
            );

            self::assertSame(
                '<div class="helmet-ribbon" data-environment="staging">staging</div>',
                $ribbon->html(),
            );
        } finally {
            unlink($path);
        }
    }

    /**
     * A missing policy file produces no warning and never breaks the page.
     */
    public function testMissingPolicyFileStillRendersRibbon(): void
    {
        $ribbon = new Ribbon(
            'staging',
            true,
            __DIR__ . '/does-not-exist.json',
            [['php', '8.3']],
            new DateTimeImmutable('2028-01-01'),
        );

        self::assertSame(
            '<div class="helmet-ribbon" data-environment="staging">staging</div>',
            $ribbon->html(),
        );
    }

    /**
     * Every non-production environment gets the ribbon.
     */
    #[DataProvider('nonProductionEnvironments')]
    public function testEveryNonProductionEnvironmentShowsTheRibbon(string $environment): void
    {
        $ribbon = new Ribbon($environment);

        self::assertTrue($ribbon->shouldShow());
        self::assertSame(
            '<div class="helmet-ribbon" data-environment="' . $environment . '">' . $environment . '</div>',
            $ribbon->html(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonProductionEnvironments(): iterable
    {
        foreach (Ribbon::ENVIRONMENTS as $environment) {
            yield $environment => [$environment];
        }
    }

    /**
     * Production, its aliases, an empty name and any name the ribbon does
     * not know are all production: no ribbon, even when enabled. A typo in
     * APP_ENV should hide the ribbon, not paint "prodution" on a live site.
     */
    #[DataProvider('productionLikeEnvironments')]
    public function testProductionAndUnknownEnvironmentsNeverShowTheRibbon(string $environment): void
    {
        $ribbon = new Ribbon($environment, true, self::POLICY, [['php', '8.1']], new DateTimeImmutable('2028-01-01'));

        self::assertFalse($ribbon->shouldShow());
        self::assertSame('', $ribbon->html());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function productionLikeEnvironments(): iterable
    {
        yield 'production' => ['production'];
        yield 'PRODUCTION' => ['PRODUCTION'];
        yield 'prod' => ['prod'];
        yield 'live' => ['live'];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'unknown' => ['qa'];
        yield 'typo' => ['stagin'];
    }

    /**
     * The match ignores case and surrounding space, and the label keeps the
     * name exactly as given (escaped).
     */
    public function testEnvironmentMatchIgnoresCaseAndKeepsTheLabel(): void
    {
        self::assertTrue(Ribbon::showsFor('STAGING'));
        self::assertTrue(Ribbon::showsFor(' Local '));
        self::assertFalse(Ribbon::showsFor('Production'));

        self::assertSame(
            '<div class="helmet-ribbon" data-environment="STAGING">STAGING</div>',
            (new Ribbon('STAGING'))->html(),
        );
    }

    /**
     * A disabled ribbon stays hidden in a non-production environment too.
     */
    public function testDisabledRibbonIsHiddenInStaging(): void
    {
        $ribbon = new Ribbon('staging', false);

        self::assertFalse($ribbon->shouldShow());
        self::assertSame('', $ribbon->html());
    }

    /**
     * The raw PHP helper follows the same rule as the class and the Laravel
     * adapter: it renders in staging and never in production.
     */
    public function testHelperRendersInStagingAndNeverInProduction(): void
    {
        self::assertSame(
            '<div class="helmet-ribbon" data-environment="staging">staging</div>',
            helmet_ribbon('staging')->html(),
        );
        self::assertSame('', helmet_ribbon('production')->html());
        self::assertSame('', helmet_ribbon('production', true, self::POLICY)->html());
        self::assertSame('', helmet_ribbon('staging', false)->html());
    }

    /**
     * The helper checks the running PHP version against the policy, and a
     * missing policy file still renders the plain ribbon.
     */
    public function testHelperChecksTheRunningPhpVersionAgainstThePolicy(): void
    {
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $path = tempnam(sys_get_temp_dir(), 'helmet-policy-');
        self::assertIsString($path);

        try {
            // A policy whose only PHP entry is the running version, long past
            // end of life, so the warning shows whatever today's date is.
            file_put_contents($path, (string) json_encode([
                'schema_version' => 1,
                'components' => [
                    'php' => [
                        $version => ['active_until' => '2000-01-01', 'security_until' => '2000-06-01'],
                    ],
                ],
            ]));

            self::assertSame(
                '<div class="helmet-ribbon" data-environment="staging">staging'
                . '<div class="helmet-ribbon-warning">PHP ' . $version . ' is end of life and no longer receives security updates. Upgrade to a supported version.</div>'
                . '</div>',
                helmet_ribbon('staging', true, $path)->html(),
            );
        } finally {
            unlink($path);
        }

        self::assertSame(
            '<div class="helmet-ribbon" data-environment="staging">staging</div>',
            helmet_ribbon('staging', true, __DIR__ . '/does-not-exist.json')->html(),
        );
    }
}
