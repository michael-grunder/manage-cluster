<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use DateTimeImmutable;
use Mgrunder\CreateCluster\BuildInfo;
use Mgrunder\CreateCluster\BuildInfoRenderer;
use Mgrunder\CreateCluster\BuildInfoSource;
use Mgrunder\CreateCluster\InvocationName;
use PHPUnit\Framework\TestCase;

final class BuildInfoRendererTest extends TestCase
{
    public function testRendersAnEmbeddedBuildInUtc(): void
    {
        $rendered = (new BuildInfoRenderer())->render(
            new BuildInfo('1.2.3', 'abc1234', new DateTimeImmutable('2026-09-05T22:04:01+02:00'), BuildInfoSource::Build),
            new InvocationName('./manage-cluster.phar', 'manage-cluster'),
        );

        $lines = explode(PHP_EOL, $rendered);

        self::assertSame('manage-cluster 1.2.3', $lines[0]);
        self::assertContains('commit    abc1234', $lines);
        self::assertContains('built     2026-09-05 20:04:01 UTC', $lines);
        self::assertContains('source    PHAR build', $lines);
        self::assertContains('php       ' . PHP_VERSION, $lines);
    }

    public function testOmitsUnknownCommitAndBuildTime(): void
    {
        $rendered = (new BuildInfoRenderer())->render(
            new BuildInfo('1.2.3', null, null, BuildInfoSource::Checkout),
            InvocationName::default(),
        );

        self::assertStringContainsString('manage-cluster 1.2.3', $rendered);
        self::assertStringNotContainsString('commit', $rendered);
        self::assertStringNotContainsString('built', $rendered);
        self::assertStringContainsString('source    source checkout', $rendered);
    }
}
