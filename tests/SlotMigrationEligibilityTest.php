<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\SlotMigrationEligibility;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\TestCase;

final class SlotMigrationEligibilityTest extends TestCase
{
    public function testSettledClusterHasNoBlockers(): void
    {
        self::assertSame([], (new SlotMigrationEligibility())->blockers($this->view(), false));
    }

    public function testReportsEveryUnsettledConditionWithSortedPorts(): void
    {
        $view = $this->view(
            nodes: [
                7000 => $this->node(7000, reachable: true),
                7002 => $this->node(7002, reachable: false, failed: true),
                7001 => $this->node(7001, reachable: true, syncing: true, loading: true),
            ],
            degradedPrimaryPorts: [7002],
        );

        self::assertSame(
            [
                'degraded primaries=7002',
                'unreachable nodes=7002',
                'failed nodes=7002',
                'syncing nodes=7001',
                'loading nodes=7001',
            ],
            (new SlotMigrationEligibility())->blockers($view, false),
        );
    }

    public function testIgnoresManagedNodesThatClusterDoesNotKnow(): void
    {
        $unknownNode = $this->node(7010, reachable: false, failed: true, syncing: true, loading: true, knownByCluster: false);

        self::assertSame([], (new SlotMigrationEligibility())->blockers($this->view(nodes: [7010 => $unknownNode]), false));
    }

    public function testUnsafeSkipsSettledClusterChecksButNotClusterDown(): void
    {
        $unsettled = $this->view(
            nodes: [7001 => $this->node(7001, reachable: false, failed: true, syncing: true, loading: true)],
            degradedPrimaryPorts: [7000],
        );

        self::assertSame([], (new SlotMigrationEligibility())->blockers($unsettled, true));
        self::assertSame(
            ['cluster is down'],
            (new SlotMigrationEligibility())->blockers($this->view(clusterDown: true), true),
        );
    }

    /**
     * @param array<int, ChaosNodeState>|null $nodes
     * @param list<int> $degradedPrimaryPorts
     */
    private function view(?array $nodes = null, array $degradedPrimaryPorts = [], bool $clusterDown = false): ChaosClusterView
    {
        $nodes ??= [
            7000 => $this->node(7000, reachable: true),
            7001 => $this->node(7001, reachable: true),
        ];

        return new ChaosClusterView(
            clusterId: 'test-cluster',
            seedPort: 7000,
            topologyHash: 'hash',
            clusterDown: $clusterDown,
            broadlyHealthy: !$clusterDown,
            nodeStateByPort: $nodes,
            primaryStateByPort: [
                7000 => new ChaosPrimaryState(
                    port: 7000,
                    nodeId: 'node-7000',
                    reachable: true,
                    slotRanges: [new SlotRange(0, 8191)],
                    replicaPorts: [],
                    healthyReplicaCount: 1,
                    syncingReplicaCount: 0,
                    failedReplicaCount: 0,
                ),
                7001 => new ChaosPrimaryState(
                    port: 7001,
                    nodeId: 'node-7001',
                    reachable: true,
                    slotRanges: [new SlotRange(8192, 16383)],
                    replicaPorts: [],
                    healthyReplicaCount: 1,
                    syncingReplicaCount: 0,
                    failedReplicaCount: 0,
                ),
            ],
            replicaStateByPort: [],
            degradedPrimaryPorts: $degradedPrimaryPorts,
        );
    }

    private function node(
        int $port,
        bool $reachable,
        bool $failed = false,
        bool $syncing = false,
        bool $loading = false,
        bool $knownByCluster = true,
    ): ChaosNodeState {
        return new ChaosNodeState(
            port: $port,
            nodeId: sprintf('node-%d', $port),
            role: 'replica',
            primaryPort: 7000,
            knownByCluster: $knownByCluster,
            reachable: $reachable,
            isFailed: $failed,
            isHandshake: false,
            isLoading: $loading,
            isSyncing: $syncing,
            linkStatus: $syncing ? 'down' : 'up',
            slotRanges: [],
            pid: null,
            managed: true,
            health: $failed ? 'fail' : 'online',
        );
    }
}
