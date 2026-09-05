<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\PrimaryFailoverPlan;
use Mgrunder\CreateCluster\PrimaryFailoverPlanner;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type NodeOverrides array{reachable?: bool, failed?: bool, syncing?: bool, loading?: bool, handshake?: bool, linkStatus?: string, managed?: bool, failoverInProgress?: bool, offset?: int|null, primaryPort?: int}
 * @phpstan-type ShardSpec array{slots?: SlotRange|null, node?: NodeOverrides, replicas?: array<int, NodeOverrides>}
 */
final class PrimaryFailoverPlannerTest extends TestCase
{
    public function testPlansOneCandidatePerCaughtUpReplicaOrderedByPort(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 5461), 'replicas' => [7004 => [], 7003 => []]],
            7001 => ['slots' => new SlotRange(5462, 10922), 'replicas' => [7005 => []]],
        ]);

        $plans = (new PrimaryFailoverPlanner())->candidates($view);

        self::assertSame(
            [[7000, 7003], [7000, 7004], [7001, 7005]],
            array_map(
                static fn (PrimaryFailoverPlan $plan): array => [$plan->primaryPort, $plan->replicaPort],
                $plans,
            ),
        );

        self::assertSame('node-7003', $plans[0]->replicaNodeId);
        self::assertSame('node-7000', $plans[0]->primaryNodeId);
        self::assertSame(5462, $plans[0]->slotCount());
        self::assertSame(0, $plans[0]->replicationLagBytes);
    }

    public function testReplicationLagIsMeasuredAgainstTheOwnPrimary(): void
    {
        $view = $this->view([
            7000 => [
                'slots' => new SlotRange(0, 16383),
                'node' => ['offset' => 5_000],
                'replicas' => [7003 => ['offset' => 4_500]],
            ],
        ]);

        $plans = (new PrimaryFailoverPlanner())->candidates($view);

        self::assertCount(1, $plans);
        self::assertSame(500, $plans[0]->replicationLagBytes);
        self::assertSame('500 bytes', $plans[0]->describeLag());
    }

    public function testReplicaAheadOfItsPrimaryReportsNoNegativeLag(): void
    {
        $view = $this->view([
            7000 => [
                'slots' => new SlotRange(0, 16383),
                'node' => ['offset' => 4_000],
                'replicas' => [7003 => ['offset' => 4_200]],
            ],
        ]);

        self::assertSame(0, (new PrimaryFailoverPlanner())->candidates($view)[0]->replicationLagBytes);
    }

    public function testLaggingReplicaIsNotPromotable(): void
    {
        $view = $this->view([
            7000 => [
                'slots' => new SlotRange(0, 16383),
                'node' => ['offset' => 10_000],
                'replicas' => [7003 => ['offset' => 4_000]],
            ],
        ]);

        self::assertSame([], (new PrimaryFailoverPlanner(maxReplicationLagBytes: 1_000))->candidates($view));
        self::assertCount(1, (new PrimaryFailoverPlanner(maxReplicationLagBytes: 6_000))->candidates($view));
    }

    /**
     * @param NodeOverrides $replicaOverrides
     */
    #[DataProvider('unpromotableReplicaProvider')]
    public function testUnpromotableReplicasAreSkipped(array $replicaOverrides): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 16383), 'replicas' => [7003 => $replicaOverrides]],
        ]);

        self::assertSame([], (new PrimaryFailoverPlanner())->candidates($view));
    }

    /**
     * @return iterable<string, array{replicaOverrides: NodeOverrides}>
     */
    public static function unpromotableReplicaProvider(): iterable
    {
        yield 'unreachable replica' => ['replicaOverrides' => ['reachable' => false]];
        yield 'failed replica' => ['replicaOverrides' => ['failed' => true]];
        yield 'syncing replica' => ['replicaOverrides' => ['syncing' => true]];
        yield 'loading replica' => ['replicaOverrides' => ['loading' => true]];
        yield 'handshaking replica' => ['replicaOverrides' => ['handshake' => true]];
        yield 'replication link down' => ['replicaOverrides' => ['linkStatus' => 'down']];
        yield 'unmanaged replica' => ['replicaOverrides' => ['managed' => false]];
        yield 'failover already running' => ['replicaOverrides' => ['failoverInProgress' => true]];
        yield 'offset not reported' => ['replicaOverrides' => ['offset' => null]];
        yield 'attached to another primary' => ['replicaOverrides' => ['primaryPort' => 7001]];
    }

    /**
     * @param NodeOverrides $primaryOverrides
     */
    #[DataProvider('undemotablePrimaryProvider')]
    public function testUndemotablePrimariesAreSkipped(array $primaryOverrides): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 16383), 'node' => $primaryOverrides, 'replicas' => [7003 => []]],
        ]);

        self::assertSame([], (new PrimaryFailoverPlanner())->candidates($view));
    }

    /**
     * @return iterable<string, array{primaryOverrides: NodeOverrides}>
     */
    public static function undemotablePrimaryProvider(): iterable
    {
        yield 'unreachable primary' => ['primaryOverrides' => ['reachable' => false]];
        yield 'failed primary' => ['primaryOverrides' => ['failed' => true]];
        yield 'loading primary' => ['primaryOverrides' => ['loading' => true]];
        yield 'unmanaged primary' => ['primaryOverrides' => ['managed' => false]];
        yield 'failover already running' => ['primaryOverrides' => ['failoverInProgress' => true]];
        yield 'offset not reported' => ['primaryOverrides' => ['offset' => null]];
    }

    public function testPrimaryWithoutSlotsIsNeverDemoted(): void
    {
        $view = $this->view([
            7000 => ['slots' => null, 'replicas' => [7003 => []]],
        ]);

        self::assertSame([], (new PrimaryFailoverPlanner())->candidates($view));
    }

    public function testNegativeLagBudgetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PrimaryFailoverPlanner(maxReplicationLagBytes: -1);
    }

    /**
     * @param array<int, ShardSpec> $shards
     */
    private function view(array $shards): ChaosClusterView
    {
        $nodeStateByPort = [];
        $primaryStateByPort = [];

        foreach ($shards as $primaryPort => $shard) {
            $slots = $shard['slots'] ?? null;
            $ranges = $slots instanceof SlotRange ? [$slots] : [];
            $primaryOverrides = $shard['node'] ?? [];
            $replicas = $shard['replicas'] ?? [];
            $replicaPorts = array_map('intval', array_keys($replicas));

            $nodeStateByPort[$primaryPort] = $this->node($primaryPort, 'primary', $ranges, $primaryOverrides);

            foreach ($replicas as $replicaPort => $overrides) {
                $overrides['primaryPort'] ??= $primaryPort;
                $nodeStateByPort[$replicaPort] = $this->node($replicaPort, 'replica', [], $overrides);
            }

            $primaryStateByPort[$primaryPort] = new ChaosPrimaryState(
                port: $primaryPort,
                nodeId: sprintf('node-%d', $primaryPort),
                reachable: $primaryOverrides['reachable'] ?? true,
                slotRanges: $ranges,
                replicaPorts: $replicaPorts,
                healthyReplicaCount: count($replicaPorts),
                syncingReplicaCount: 0,
                failedReplicaCount: 0,
            );
        }

        return new ChaosClusterView(
            clusterId: 'test-cluster',
            seedPort: 7000,
            topologyHash: 'hash',
            clusterDown: false,
            broadlyHealthy: true,
            nodeStateByPort: $nodeStateByPort,
            primaryStateByPort: $primaryStateByPort,
            replicaStateByPort: [],
            degradedPrimaryPorts: [],
        );
    }

    /**
     * @param list<SlotRange> $slotRanges
     * @param NodeOverrides $overrides
     */
    private function node(int $port, string $role, array $slotRanges, array $overrides): ChaosNodeState
    {
        return new ChaosNodeState(
            port: $port,
            nodeId: sprintf('node-%d', $port),
            role: $role,
            primaryPort: $overrides['primaryPort'] ?? null,
            knownByCluster: true,
            reachable: $overrides['reachable'] ?? true,
            isFailed: $overrides['failed'] ?? false,
            isHandshake: $overrides['handshake'] ?? false,
            isLoading: $overrides['loading'] ?? false,
            isSyncing: $overrides['syncing'] ?? false,
            linkStatus: $role === 'replica' ? ($overrides['linkStatus'] ?? 'up') : '',
            slotRanges: $slotRanges,
            pid: null,
            managed: $overrides['managed'] ?? true,
            health: 'online',
            replicationOffset: array_key_exists('offset', $overrides) ? $overrides['offset'] : 1_000,
            failoverInProgress: $overrides['failoverInProgress'] ?? false,
        );
    }
}
