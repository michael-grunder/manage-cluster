<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Mgrunder\CreateCluster\BuildInfo;
use Mgrunder\CreateCluster\BuildInfoSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BuildInfoTest extends TestCase
{
    public function testFromMetadataReadsAnEmbeddedBuild(): void
    {
        $buildInfo = BuildInfo::fromMetadata([
            'version' => '1.2.3',
            'commit' => 'abc1234',
            'built_at' => '2026-09-05T20:04:01+00:00',
        ]);

        self::assertSame('1.2.3', $buildInfo->version);
        self::assertSame('abc1234', $buildInfo->commit);
        self::assertNotNull($buildInfo->builtAt);
        self::assertSame('2026-09-05T20:04:01+00:00', $buildInfo->builtAt->format(DateTimeInterface::ATOM));
        self::assertSame(BuildInfoSource::Build, $buildInfo->source);
    }

    public function testFromMetadataAllowsMissingCommitAndBuildTime(): void
    {
        $buildInfo = BuildInfo::fromMetadata(['version' => '1.2.3', 'commit' => null, 'built_at' => null]);

        self::assertNull($buildInfo->commit);
        self::assertNull($buildInfo->builtAt);
    }

    /**
     * @param array<mixed> $metadata
     */
    #[DataProvider('invalidMetadataProvider')]
    public function testFromMetadataRejectsUnusableMetadata(array $metadata, string $expectedMessage): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        BuildInfo::fromMetadata($metadata);
    }

    /**
     * @return iterable<string, array{metadata: array<mixed>, expectedMessage: string}>
     */
    public static function invalidMetadataProvider(): iterable
    {
        yield 'missing version' => [
            'metadata' => ['commit' => 'abc1234'],
            'expectedMessage' => 'missing a "version" string',
        ];

        yield 'blank version' => [
            'metadata' => ['version' => '   '],
            'expectedMessage' => 'missing a "version" string',
        ];

        yield 'non-string commit' => [
            'metadata' => ['version' => '1.2.3', 'commit' => 1234],
            'expectedMessage' => '"commit" must be null or a non-empty string',
        ];

        yield 'non-string build time' => [
            'metadata' => ['version' => '1.2.3', 'built_at' => 1757102641],
            'expectedMessage' => '"built_at" must be null or an ISO 8601 string',
        ];

        yield 'unparsable build time' => [
            'metadata' => ['version' => '1.2.3', 'built_at' => 'last tuesday'],
            'expectedMessage' => 'not an ISO 8601 timestamp: last tuesday',
        ];
    }

    public function testConstructorRejectsAnEmptyVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BuildInfo('', null, null, BuildInfoSource::Checkout);
    }

    public function testConstructorRejectsABlankCommit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BuildInfo('1.2.3', ' ', null, BuildInfoSource::Build);
    }

    public function testMetadataRoundTripsThroughToMetadataArray(): void
    {
        $buildInfo = new BuildInfo(
            '1.2.3',
            'abc1234-dirty',
            new DateTimeImmutable('2026-09-05T20:04:01+00:00'),
            BuildInfoSource::Build,
        );

        self::assertSame(
            ['version' => '1.2.3', 'commit' => 'abc1234-dirty', 'built_at' => '2026-09-05T20:04:01+00:00'],
            $buildInfo->toMetadataArray(),
        );

        $restored = BuildInfo::fromMetadata($buildInfo->toMetadataArray());

        self::assertEquals($buildInfo, $restored);
    }
}
