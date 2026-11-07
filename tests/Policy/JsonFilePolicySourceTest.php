<?php

declare(strict_types=1);

namespace Waynestate\Helmet\Tests\Policy;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Helmet\Policy\JsonFilePolicySource;
use Waynestate\Helmet\Policy\PolicyReadException;
use Waynestate\Helmet\Policy\VersionStatus;

/**
 * The policy reader against both shapes: CREED's real file (versions under
 * "versions", every date optional) and the older flat one.
 */
final class JsonFilePolicySourceTest extends TestCase
{
    private const CREED = __DIR__ . '/../fixtures/policy-creed.json';

    private const FLAT = __DIR__ . '/../fixtures/policy.json';

    /**
     * CREED's policy.schema.json keeps a component's versions under
     * "versions". Reading the component as if it were the version map found
     * nothing, so every real app's PHP was "unknown".
     */
    public function testReadsCreedsVersionsWrapper(): void
    {
        $components = (new JsonFilePolicySource(self::CREED))->components();

        self::assertSame(
            ['active_until' => '2025-12-31', 'security_until' => '2027-12-31'],
            $components['php']['8.3'],
        );
        self::assertSame('2027-02-24', $components['laravel']['12']['security_until']);

        $status = new VersionStatus(new DateTimeImmutable('2026-06-01'), $components);
        self::assertSame(VersionStatus::SECURITY, $status->status('php', '8.3'));
        self::assertSame(VersionStatus::ACTIVE, $status->status('php', '8.4'));
        self::assertEquals(new DateTimeImmutable('2028-12-31'), $status->securityUntil('php', '8.4'));
    }

    public function testAVersionWithoutActiveUntilIsSecurityOnly(): void
    {
        $components = (new JsonFilePolicySource(self::CREED))->components();
        $status = new VersionStatus(new DateTimeImmutable('2026-06-01'), $components);

        self::assertSame(VersionStatus::SECURITY, $status->status('php', '8.5'));
    }

    public function testAVersionWithoutSecurityUntilIsSkipped(): void
    {
        $components = (new JsonFilePolicySource(self::CREED))->components();

        self::assertArrayNotHasKey('9.0', $components['php']);
        self::assertNull((new VersionStatus(new DateTimeImmutable('2026-06-01'), $components))->securityUntil('php', '9.0'));
    }

    public function testStillReadsTheFlatShape(): void
    {
        $components = (new JsonFilePolicySource(self::FLAT))->components();

        self::assertSame('2028-11-30', $components['php']['8.3']['security_until']);
    }

    public function testAMissingFileThrows(): void
    {
        $this->expectException(PolicyReadException::class);

        new JsonFilePolicySource(__DIR__ . '/../fixtures/no-such-policy.json');
    }
}
