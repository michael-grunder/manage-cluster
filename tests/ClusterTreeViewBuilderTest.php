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

        self::assertCount(3, $entries);
        self::assertSame('127.0.0.1:7000', $entries[0]->node->address());
        self::assertSame(0, $entries[0]->depth);
        self::assertSame('0-5460', $entries[0]->slotRange);
        self::assertSame('127.0.0.1:7001', $entries[1]->node->address());
        self::assertSame(1, $entries[1]->depth);
        self::assertNull($entries[1]->slotRange);
        self::assertSame('127.0.0.1:7002', $entries[2]->node->address());
    }

    public function testBuildOmitsReplicasForPrimariesOnlyMode(): void
    {
        $builder = new ClusterTreeViewBuilder();

        $entries = $builder->build($this->sampleShards(), ClusterTreeViewMode::PrimariesOnly);

        self::assertCount(2, $entries);
        self::assertSame('127.0.0.1:7000', $entries[0]->node->address());
        self::assertSame('127.0.0.1:7002', $entries[1]->node->address());
        self::assertSame(0, $entries[0]->depth);
        self::assertSame(0, $entries[1]->depth);
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
