<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\PrimaryRemovePlan;
use Mgrunder\CreateCluster\PrimaryRemovePlanner;
use Mgrunder\CreateCluster\SlotMigrationPlan;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type NodeOverrides array{reachable?: bool, failed?: bool, syncing?: bool, loading?: bool, handshake?: bool, linkStatus?: string, managed?: bool, failoverInProgress?: bool, primaryPort?: int}
 * @phpstan-type ShardSpec array{slots?: SlotRange|null, node?: NodeOverrides, replicas?: array<int, NodeOverrides>}
 */
final class PrimaryRemovePlannerTest extends TestCase
{
    public function testDrainSpreadsTheVictimSlotsOverEverySurvivingPrimary(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 3999)],
            7001 => ['slots' => new SlotRange(4000, 7999)],
            7002 => ['slots' => new SlotRange(8000, 11999)],
            7003 => ['slots' => new SlotRange(12000, 16383), 'replicas' => [7013 => []]],
        ]);

        $plan = $this->planFor($view, 7003);

        self::assertSame(
            [[7000, '12000-13461'], [7001, '13462-14922'], [7002, '14923-16383']],
            array_map(
                static fn (SlotMigrationPlan $drain): array => [$drain->destinationPort, $drain->describeRanges()],
                $plan->drainPlans,
            ),
        );
        self::assertSame(4384, $plan->slotCount());
        self::assertSame([7013], $plan->replicaPorts);
        // The shard that takes the largest share also inherits the replicas.
        self::assertSame(7000, $plan->replicaRecipientPort);
        self::assertSame('node-7000', $plan->replicaRecipientNodeId);
    }

    public function testEveryRemovableNonSeedPrimaryIsOffered(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 3999)],
            7001 => ['slots' => new SlotRange(4000, 7999)],
            7002 => ['slots' => new SlotRange(8000, 11999)],
            7003 => ['slots' => new SlotRange(12000, 16383)],
        ]);

        self::assertSame(
            [7001, 7002, 7003],
            array_map(
                static fn (PrimaryRemovePlan $plan): int => $plan->port,
                (new PrimaryRemovePlanner())->candidates($view, 7000),
            ),
        );
    }

    public function testAThreePrimaryClusterCannotShrink(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 5461)],
            7001 => ['slots' => new SlotRange(5462, 10922)],
            7002 => ['slots' => new SlotRange(10923, 16383)],
        ]);

        self::assertSame([], (new PrimaryRemovePlanner())->candidates($view, 7000));
    }

    public function testAnEmptyPrimaryLeavesWithoutAnySlotMigration(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 5461)],
            7001 => ['slots' => new SlotRange(5462, 10922)],
            7002 => ['slots' => new SlotRange(10923, 16383)],
            7003 => ['slots' => null, 'replicas' => [7013 => []]],
        ]);

        $plan = $this->planFor($view, 7003);

        self::assertSame([], $plan->drainPlans);
        self::assertSame(0, $plan->slotCount());
        self::assertSame('none', $plan->describeDrain());
        // With nothing to hand over, the replicas go to the least redundant shard.
        self::assertSame(7000, $plan->replicaRecipientPort);
    }

    public function testTheDiscoverySeedIsNeverRemoved(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 3999)],
            7001 => ['slots' => new SlotRange(4000, 7999)],
            7002 => ['slots' => new SlotRange(8000, 11999)],
            7003 => ['slots' => new SlotRange(12000, 16383)],
        ]);

        $removable = array_map(
            static fn (PrimaryRemovePlan $plan): int => $plan->port,
            (new PrimaryRemovePlanner())->candidates($view, 7003),
        );

        self::assertNotContains(7003, $removable);
    }

    /**
     * @param NodeOverrides $victimOverrides
     */
    #[DataProvider('unremovablePrimaryProvider')]
    public function testUnsettledOrUnmanagedPrimariesAreNotRemoved(array $victimOverrides): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 3999)],
            7001 => ['slots' => new SlotRange(4000, 7999)],
            7002 => ['slots' => new SlotRange(8000, 11999)],
            7003 => ['slots' => new SlotRange(12000, 16383), 'node' => $victimOverrides],
        ]);

        $removable = array_map(
            static fn (PrimaryRemovePlan $plan): int => $plan->port,
            (new PrimaryRemovePlanner())->candidates($view, 7000),
        );

        self::assertNotContains(7003, $removable);
    }

    /**
     * @return iterable<string, array{victimOverrides: NodeOverrides}>
     */
    public static function unremovablePrimaryProvider(): iterable
    {
        yield 'unreachable primary' => ['victimOverrides' => ['reachable' => false]];
        yield 'failed primary' => ['victimOverrides' => ['failed' => true]];
        yield 'loading primary' => ['victimOverrides' => ['loading' => true]];
        yield 'unmanaged primary' => ['victimOverrides' => ['managed' => false]];
        yield 'primary is failing over' => ['victimOverrides' => ['failoverInProgress' => true]];
    }

    public function testAPrimaryWithAnUnmovableReplicaWaits(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 3999)],
            7001 => ['slots' => new SlotRange(4000, 7999)],
            7002 => ['slots' => new SlotRange(8000, 11999)],
            7003 => ['slots' => new SlotRange(12000, 16383), 'replicas' => [7013 => ['reachable' => false]]],
        ]);

        $removable = array_map(
            static fn (PrimaryRemovePlan $plan): int => $plan->port,
            (new PrimaryRemovePlanner())->candidates($view, 7000),
        );

        self::assertNotContains(7003, $removable);
    }

    private function planFor(ChaosClusterView $view, int $port): PrimaryRemovePlan
    {
        foreach ((new PrimaryRemovePlanner())->candidates($view, 7000) as $plan) {
            if ($plan->port === $port) {
                return $plan;
            }
        }

        self::fail(sprintf('No removal plan was produced for primary %d.', $port));
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
            $overrides = $shard['node'] ?? [];
            $replicas = $shard['replicas'] ?? [];
            $replicaPorts = array_map('intval', array_keys($replicas));

            $nodeStateByPort[$primaryPort] = $this->node($primaryPort, 'primary', $ranges, $overrides);

            $healthyReplicaCount = 0;
            foreach ($replicas as $replicaPort => $replicaOverrides) {
                $replicaOverrides['primaryPort'] ??= $primaryPort;
                $replica = $this->node($replicaPort, 'replica', [], $replicaOverrides);
                $nodeStateByPort[$replicaPort] = $replica;
                if ($replica->isHealthyReplica()) {
                    $healthyReplicaCount++;
                }
            }

            $primaryStateByPort[$primaryPort] = new ChaosPrimaryState(
                port: $primaryPort,
                nodeId: sprintf('node-%d', $primaryPort),
                reachable: $overrides['reachable'] ?? true,
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
