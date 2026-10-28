<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Ribbon;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Ribbon\Ribbon;

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
            '<div class="helmet-ribbon-warning">Php 8.3 is in its security-only phase and will not receive new features. Upgrade to a supported version.</div>',
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
            '<div class="helmet-ribbon-warning">Php 8.3 is end of life and no longer receives security updates. Upgrade to a supported version.</div>',
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
}
