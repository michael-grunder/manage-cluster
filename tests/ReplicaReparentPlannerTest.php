<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\ReplicaReparentPlan;
use Mgrunder\CreateCluster\ReplicaReparentPlanner;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type NodeOverrides array{reachable?: bool, failed?: bool, syncing?: bool, loading?: bool, handshake?: bool, linkStatus?: string, managed?: bool, failoverInProgress?: bool, primaryPort?: int}
 * @phpstan-type ShardSpec array{slots?: SlotRange|null, node?: NodeOverrides, replicas?: array<int, NodeOverrides>}
 */
final class ReplicaReparentPlannerTest extends TestCase
{
    public function testPlansEveryOtherSlotOwningPrimaryAsARecipient(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 5461), 'replicas' => [7003 => [], 7004 => []]],
            7001 => ['slots' => new SlotRange(5462, 10922), 'replicas' => [7005 => []]],
            7002 => ['slots' => new SlotRange(10923, 16383), 'replicas' => [7006 => []]],
        ]);

        $plans = (new ReplicaReparentPlanner())->candidates($view);

        // Only the two-replica shard can spare a replica, and it may go to
        // either of the other two primaries.
        self::assertSame(
            [[7003, 7000, 7001], [7003, 7000, 7002], [7004, 7000, 7001], [7004, 7000, 7002]],
            array_map(
                static fn (ReplicaReparentPlan $plan): array => [
                    $plan->replicaPort,
                    $plan->sourcePrimaryPort,
                    $plan->targetPrimaryPort,
                ],
                $plans,
            ),
        );

        self::assertSame('node-7003', $plans[0]->replicaNodeId);
        self::assertSame('node-7000', $plans[0]->sourcePrimaryNodeId);
        self::assertSame('node-7001', $plans[0]->targetPrimaryNodeId);
    }

    public function testDonorKeepsASpareReplicaUnlessDrainingIsAllowed(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 8191), 'replicas' => [7003 => []]],
            7001 => ['slots' => new SlotRange(8192, 16383), 'replicas' => [7004 => []]],
        ]);

        self::assertSame([], (new ReplicaReparentPlanner())->candidates($view));

        $plans = (new ReplicaReparentPlanner())->candidates($view, allowDrainingDonor: true);

        self::assertSame(
            [[7003, 7001], [7004, 7000]],
            array_map(
                static fn (ReplicaReparentPlan $plan): array => [$plan->replicaPort, $plan->targetPrimaryPort],
                $plans,
            ),
        );
    }

    /**
     * @param NodeOverrides $replicaOverrides
     */
    #[DataProvider('unmovableReplicaProvider')]
    public function testUnmovableReplicasAreSkipped(array $replicaOverrides): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 8191), 'replicas' => [7003 => $replicaOverrides, 7004 => [], 7007 => []]],
            7001 => ['slots' => new SlotRange(8192, 16383), 'replicas' => [7005 => [], 7006 => []]],
        ]);

        $moved = array_map(
            static fn (ReplicaReparentPlan $plan): int => $plan->replicaPort,
            (new ReplicaReparentPlanner())->candidates($view),
        );

        self::assertNotContains(7003, $moved);
        self::assertContains(7004, $moved);
    }

    /**
     * @return iterable<string, array{replicaOverrides: NodeOverrides}>
     */
    public static function unmovableReplicaProvider(): iterable
    {
        yield 'unreachable replica' => ['replicaOverrides' => ['reachable' => false]];
        yield 'failed replica' => ['replicaOverrides' => ['failed' => true]];
        yield 'syncing replica' => ['replicaOverrides' => ['syncing' => true]];
        yield 'loading replica' => ['replicaOverrides' => ['loading' => true]];
        yield 'handshaking replica' => ['replicaOverrides' => ['handshake' => true]];
        yield 'replication link down' => ['replicaOverrides' => ['linkStatus' => 'down']];
        yield 'unmanaged replica' => ['replicaOverrides' => ['managed' => false]];
        yield 'failover already running' => ['replicaOverrides' => ['failoverInProgress' => true]];
    }

    /**
     * @param NodeOverrides $targetOverrides
     */
    #[DataProvider('unattachableTargetProvider')]
    public function testUnattachableRecipientsAreSkipped(array $targetOverrides): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 8191), 'replicas' => [7003 => [], 7004 => []]],
            7001 => ['slots' => new SlotRange(8192, 16383), 'node' => $targetOverrides, 'replicas' => []],
        ]);

        self::assertSame([], (new ReplicaReparentPlanner())->candidates($view));
    }

    /**
     * @return iterable<string, array{targetOverrides: NodeOverrides}>
     */
    public static function unattachableTargetProvider(): iterable
    {
        yield 'unreachable recipient' => ['targetOverrides' => ['reachable' => false]];
        yield 'failed recipient' => ['targetOverrides' => ['failed' => true]];
        yield 'loading recipient' => ['targetOverrides' => ['loading' => true]];
        yield 'handshaking recipient' => ['targetOverrides' => ['handshake' => true]];
        yield 'recipient is failing over' => ['targetOverrides' => ['failoverInProgress' => true]];
    }

    public function testRecipientWithoutSlotsIsSkipped(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 16383), 'replicas' => [7003 => [], 7004 => []]],
            7001 => ['slots' => null, 'replicas' => []],
        ]);

        self::assertSame([], (new ReplicaReparentPlanner())->candidates($view));
    }

    public function testReplicasOfAnUnreachableDonorStayPut(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 5461), 'node' => ['reachable' => false], 'replicas' => [7003 => [], 7004 => []]],
            7001 => ['slots' => new SlotRange(5462, 10922), 'replicas' => [7005 => [], 7006 => []]],
            7002 => ['slots' => new SlotRange(10923, 16383), 'replicas' => [7007 => []]],
        ]);

        $moves = array_map(
            static fn (ReplicaReparentPlan $plan): array => [$plan->replicaPort, $plan->targetPrimaryPort],
            (new ReplicaReparentPlanner())->candidates($view),
        );

        // 7000 is unreachable, so it neither gives up nor receives a replica.
        self::assertSame([[7005, 7002], [7006, 7002]], $moves);
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

            $healthyReplicaCount = 0;
            foreach ($replicas as $replicaPort => $overrides) {
                $overrides['primaryPort'] ??= $primaryPort;
                $replicaState = $this->node($replicaPort, 'replica', [], $overrides);
                $nodeStateByPort[$replicaPort] = $replicaState;
                if ($replicaState->isHealthyReplica()) {
                    $healthyReplicaCount++;
                }
            }

            $primaryStateByPort[$primaryPort] = new ChaosPrimaryState(
                port: $primaryPort,
                nodeId: sprintf('node-%d', $primaryPort),
                reachable: $primaryOverrides['reachable'] ?? true,
                slotRanges: $ranges,
                replicaPorts: $replicaPorts,
                healthyReplicaCount: $healthyReplicaCount,
                syncingReplicaCount: 0,
                failedReplicaCount: 0,
            );
        }

        ksort($nodeStateByPort, SORT_NUMERIC);

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
            replicationOffset: 1_000,
            failoverInProgress: $overrides['failoverInProgress'] ?? false,
        );
    }
}
