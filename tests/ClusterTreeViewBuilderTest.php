<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\ClusterTreeViewBuilder;
use Mgrunder\CreateCluster\ClusterTreeViewMode;
use PHPUnit\Framework\TestCase;

final class ClusterTreeViewBuilderTest extends TestCase
{
    public function testBuildIncludesPrimariesAndReplicasForAllNodesMode(): void
    {
        $builder = new ClusterTreeViewBuilder();

        $entries = $builder->build($this->sampleShards(), ClusterTreeViewMode::AllNodes);

        self::assertCount(4, $entries);
        self::assertSame('7000', $entries[0]->nodeLabel());
        self::assertSame(0, $entries[0]->depth);
        self::assertSame('0-5460', $entries[0]->slotRange);
        self::assertSame('  7001', $entries[1]->nodeLabel());
        self::assertSame(1, $entries[1]->depth);
        self::assertNull($entries[1]->slotRange);
        self::assertSame('  7003', $entries[2]->nodeLabel());
        self::assertSame('7002', $entries[3]->nodeLabel());
    }

    public function testBuildOmitsReplicasForPrimariesOnlyMode(): void
    {
        $builder = new ClusterTreeViewBuilder();

        $entries = $builder->build($this->sampleShards(), ClusterTreeViewMode::PrimariesOnly);

        self::assertCount(2, $entries);
        self::assertSame('7000', $entries[0]->nodeLabel());
        self::assertSame('7002', $entries[1]->nodeLabel());
        self::assertSame(0, $entries[0]->depth);
        self::assertSame(0, $entries[1]->depth);
    }

    public function testBuildIncludesOnlyFailedReplicasAndTheirPrimaries(): void
    {
        $builder = new ClusterTreeViewBuilder();

        $entries = $builder->build($this->sampleShards(), ClusterTreeViewMode::FailedReplicasOnly);

        self::assertCount(2, $entries);
        self::assertSame('7000', $entries[0]->nodeLabel());
        self::assertFalse($entries[0]->selectable);
        self::assertSame(0, $entries[0]->depth);
        self::assertSame('  7003', $entries[1]->nodeLabel());
        self::assertTrue($entries[1]->selectable);
        self::assertSame(1, $entries[1]->depth);
    }

    /**
     * @return list<ClusterShardStatus>
     */
    private function sampleShards(): array
    {
        return [
            new ClusterShardStatus(
                slotStart: 0,
                slotEnd: 5460,
                master: new ClusterNodeStatus('master-7000', '127.0.0.1', 7000, '', 'master', 100, 'online'),
                replicas: [
                    new ClusterNodeStatus('replica-7001', '127.0.0.1', 7001, '', 'replica', 99, 'online'),
                    new ClusterNodeStatus('replica-7003', '127.0.0.1', 7003, '', 'replica', 40, 'fail'),
                ],
            ),
            new ClusterShardStatus(
                slotStart: 5461,
                slotEnd: 10922,
                master: new ClusterNodeStatus('master-7002', '127.0.0.1', 7002, '', 'master', 110, 'online'),
                replicas: [],
            ),
        ];
    }
}
