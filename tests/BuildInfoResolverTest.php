<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use DateTimeInterface;
use Mgrunder\CreateCluster\BuildInfo;
use Mgrunder\CreateCluster\BuildInfoResolver;
use Mgrunder\CreateCluster\BuildInfoSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BuildInfoResolverTest extends TestCase
{
    private string $metadataPath = '';

    protected function setUp(): void
    {
        $this->metadataPath = sprintf('%s/manage-cluster-build-info-%s.json', sys_get_temp_dir(), bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if ($this->metadataPath !== '' && is_file($this->metadataPath)) {
            unlink($this->metadataPath);
        }
    }

    public function testResolvesEmbeddedBuildMetadata(): void
    {
        $this->writeMetadata('{"version":"1.2.3","commit":"abc1234","built_at":"2026-09-05T20:04:01+00:00"}');

        $buildInfo = (new BuildInfoResolver($this->metadataPath, static fn (): ?string => self::fail('git should not be consulted for a built archive')))->resolve();

        self::assertSame('1.2.3', $buildInfo->version);
        self::assertSame('abc1234', $buildInfo->commit);
        self::assertNotNull($buildInfo->builtAt);
        self::assertSame('2026-09-05T20:04:01+00:00', $buildInfo->builtAt->format(DateTimeInterface::ATOM));
        self::assertSame(BuildInfoSource::Build, $buildInfo->source);
    }

    public function testDescribesACheckoutWhenNoMetadataIsEmbedded(): void
    {
        $buildInfo = (new BuildInfoResolver($this->metadataPath, static fn (): string => 'abc1234-dirty'))->resolve();

        self::assertSame(BuildInfo::VERSION, $buildInfo->version);
        self::assertSame('abc1234-dirty', $buildInfo->commit);
        self::assertNull($buildInfo->builtAt);
        self::assertSame(BuildInfoSource::Checkout, $buildInfo->source);
    }

    public function testDescribesACheckoutWithoutGit(): void
    {
        $buildInfo = (new BuildInfoResolver($this->metadataPath, static fn (): ?string => null))->resolve();

        self::assertSame(BuildInfo::VERSION, $buildInfo->version);
        self::assertNull($buildInfo->commit);
        self::assertSame(BuildInfoSource::Checkout, $buildInfo->source);
    }

    public function testRejectsMalformedMetadataJson(): void
    {
        $this->writeMetadata('{"version":');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        (new BuildInfoResolver($this->metadataPath, static fn (): ?string => null))->resolve();
    }

    public function testRejectsMetadataThatIsNotAnObject(): void
    {
        $this->writeMetadata('"1.2.3"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a JSON object');

        (new BuildInfoResolver($this->metadataPath, static fn (): ?string => null))->resolve();
    }

    public function testDescribeWorkingTreeReturnsNullOutsideARepository(): void
    {
        self::assertNull(BuildInfoResolver::describeWorkingTree(sys_get_temp_dir() . '/manage-cluster-not-a-repo-' . bin2hex(random_bytes(4))));
    }

    private function writeMetadata(string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->metadataPath, $contents));
    }
}
