<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\PrimaryAddPlan;
use Mgrunder\CreateCluster\PrimaryAddPlanner;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type NodeOverrides array{reachable?: bool, failed?: bool, loading?: bool, handshake?: bool, managed?: bool, failoverInProgress?: bool}
 * @phpstan-type ShardSpec array{slots?: SlotRange|null, node?: NodeOverrides}
 */
final class PrimaryAddPlannerTest extends TestCase
{
    public function testTheBusiestPrimaryDonatesItsHighestSlots(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 999)],
            7001 => ['slots' => new SlotRange(1000, 9999)],
            7002 => ['slots' => new SlotRange(10000, 16383)],
        ]);

        $plan = (new PrimaryAddPlanner())->plan($view, 7010, 16);

        self::assertInstanceOf(PrimaryAddPlan::class, $plan);
        self::assertSame(7010, $plan->newPrimaryPort);
        self::assertSame(7001, $plan->donorPort);
        self::assertSame('node-7001', $plan->donorNodeId);
        self::assertSame('9984-9999', $plan->describeRanges());
        self::assertSame(16, $plan->slotCount());
    }

    public function testDonationNeverTakesMoreThanHalfOfTheDonor(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 6)],
            7001 => ['slots' => new SlotRange(7, 16383)],
        ]);

        $plan = (new PrimaryAddPlanner())->plan($view, 7010, 16_384);

        self::assertInstanceOf(PrimaryAddPlan::class, $plan);
        self::assertSame(8188, $plan->slotCount());
        self::assertSame('8196-16383', $plan->describeRanges());
    }

    public function testFragmentedOwnershipDonatesFromTheHighestRanges(): void
    {
        $view = $this->view([
            7000 => ['slots' => null],
            7001 => ['slots' => new SlotRange(0, 99)],
        ]);

        $view = $this->withRanges($view, 7001, [new SlotRange(0, 99), new SlotRange(500, 509)]);

        $plan = (new PrimaryAddPlanner())->plan($view, 7010, 16);

        self::assertInstanceOf(PrimaryAddPlan::class, $plan);
        self::assertSame('94-99,500-509', $plan->describeRanges());
        self::assertSame(16, $plan->slotCount());
    }

    public function testAPrimaryOwningOneSlotIsNeverDrained(): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 0)],
        ]);

        self::assertNull((new PrimaryAddPlanner())->plan($view, 7010, 16));
    }

    /**
     * @param NodeOverrides $donorOverrides
     */
    #[DataProvider('unusableDonorProvider')]
    public function testUnsettledPrimariesDoNotDonate(array $donorOverrides): void
    {
        $view = $this->view([
            7000 => ['slots' => new SlotRange(0, 16383), 'node' => $donorOverrides],
        ]);

        self::assertNull((new PrimaryAddPlanner())->plan($view, 7010, 16));
    }

    /**
     * @return iterable<string, array{donorOverrides: NodeOverrides}>
     */
    public static function unusableDonorProvider(): iterable
    {
        yield 'unreachable donor' => ['donorOverrides' => ['reachable' => false]];
        yield 'failed donor' => ['donorOverrides' => ['failed' => true]];
        yield 'loading donor' => ['donorOverrides' => ['loading' => true]];
        yield 'handshaking donor' => ['donorOverrides' => ['handshake' => true]];
        yield 'donor is failing over' => ['donorOverrides' => ['failoverInProgress' => true]];
    }

    public function testEmptyClusterHasNoDonor(): void
    {
        self::assertNull((new PrimaryAddPlanner())->plan($this->view([]), 7010, 16));
    }

    public function testSlotBudgetMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PrimaryAddPlanner())->plan($this->view([7000 => ['slots' => new SlotRange(0, 16383)]]), 7010, 0);
    }

    /**
     * @param list<SlotRange> $ranges
     */
    private function withRanges(ChaosClusterView $view, int $port, array $ranges): ChaosClusterView
    {
        $primary = $view->primaryStateByPort[$port];
        $primaryStateByPort = $view->primaryStateByPort;
        $primaryStateByPort[$port] = new ChaosPrimaryState(
            port: $primary->port,
            nodeId: $primary->nodeId,
            reachable: $primary->reachable,
            slotRanges: $ranges,
            replicaPorts: $primary->replicaPorts,
            healthyReplicaCount: $primary->healthyReplicaCount,
            syncingReplicaCount: $primary->syncingReplicaCount,
            failedReplicaCount: $primary->failedReplicaCount,
        );

        return new ChaosClusterView(
            clusterId: $view->clusterId,
            seedPort: $view->seedPort,
            topologyHash: $view->topologyHash,
            clusterDown: $view->clusterDown,
            broadlyHealthy: $view->broadlyHealthy,
            nodeStateByPort: $view->nodeStateByPort,
            primaryStateByPort: $primaryStateByPort,
            replicaStateByPort: $view->replicaStateByPort,
            degradedPrimaryPorts: $view->degradedPrimaryPorts,
        );
    }

    /**
     * @param array<int, ShardSpec> $shards
     */
    private function view(array $shards): ChaosClusterView
    {
        $nodeStateByPort = [];
        $primaryStateByPort = [];

        foreach ($shards as $port => $shard) {
            $slots = $shard['slots'] ?? null;
            $ranges = $slots instanceof SlotRange ? [$slots] : [];
            $overrides = $shard['node'] ?? [];

            $nodeStateByPort[$port] = new ChaosNodeState(
                port: $port,
                nodeId: sprintf('node-%d', $port),
                role: 'primary',
                primaryPort: null,
                knownByCluster: true,
                reachable: $overrides['reachable'] ?? true,
                isFailed: $overrides['failed'] ?? false,
                isHandshake: $overrides['handshake'] ?? false,
                isLoading: $overrides['loading'] ?? false,
                isSyncing: false,
                linkStatus: '',
                slotRanges: $ranges,
                pid: null,
                managed: $overrides['managed'] ?? true,
                health: 'online',
                replicationOffset: 1_000,
                failoverInProgress: $overrides['failoverInProgress'] ?? false,
            );

            $primaryStateByPort[$port] = new ChaosPrimaryState(
                port: $port,
                nodeId: sprintf('node-%d', $port),
                reachable: $overrides['reachable'] ?? true,
                slotRanges: $ranges,
                replicaPorts: [],
                healthyReplicaCount: 0,
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
}
