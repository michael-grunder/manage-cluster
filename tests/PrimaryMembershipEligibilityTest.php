<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\PrimaryMembershipEligibility;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\TestCase;

final class PrimaryMembershipEligibilityTest extends TestCase
{
    public function testHealthyClusterHasNoBlockers(): void
    {
        self::assertSame([], (new PrimaryMembershipEligibility())->blockers($this->view(), false));
    }

    public function testClusterDownIsTheOnlyReportedBlocker(): void
    {
        $view = $this->view(clusterDown: true);

        self::assertSame(['cluster is down'], (new PrimaryMembershipEligibility())->blockers($view, false));
        self::assertSame(['cluster is down'], (new PrimaryMembershipEligibility())->blockers($view, true));
    }

    public function testTooFewSlotOwningPrimariesBlocksMembershipChurnEvenWithUnsafe(): void
    {
        $view = $this->view(primaries: [
            7000 => $this->primary(7000, new SlotRange(0, 8191)),
            7001 => $this->primary(7001, new SlotRange(8192, 16383)),
        ]);

        self::assertSame(
            ['needs at least 3 reachable slot-owning primaries, found 2'],
            (new PrimaryMembershipEligibility())->blockers($view, true),
        );
    }

    public function testADownReplicaBlocksMembershipChurn(): void
    {
        $view = $this->view(nodes: [
            7004 => $this->node(7004, 'replica', primaryPort: 7001, reachable: false),
            7003 => $this->node(7003, 'replica', primaryPort: 7000, failed: true),
        ]);

        self::assertSame(
            ['down replicas=7003,7004'],
            (new PrimaryMembershipEligibility())->blockers($view, false),
        );
        self::assertSame([], (new PrimaryMembershipEligibility())->blockers($view, true));
    }

    public function testUnsettledMembershipBlocksNormalRuns(): void
    {
        $view = $this->view(nodes: [
            7002 => $this->node(7002, 'primary', reachable: false),
            7005 => $this->node(7005, 'replica', primaryPort: 7000, handshake: true),
        ]);

        self::assertSame(
            [
                'needs at least 3 reachable slot-owning primaries, found 2',
                'unreachable primaries=7002',
                'handshaking nodes=7005',
            ],
            (new PrimaryMembershipEligibility())->blockers($view, false),
        );
    }

    /**
     * @param array<int, ChaosPrimaryState>|null $primaries
     * @param array<int, ChaosNodeState> $nodes
     */
    private function view(?array $primaries = null, array $nodes = [], bool $clusterDown = false): ChaosClusterView
    {
        $primaries ??= [
            7000 => $this->primary(7000, new SlotRange(0, 5461)),
            7001 => $this->primary(7001, new SlotRange(5462, 10922)),
            7002 => $this->primary(7002, new SlotRange(10923, 16383)),
        ];

        $nodeStateByPort = [];
        foreach ($primaries as $port => $primary) {
            $nodeStateByPort[$port] = $this->node($port, 'primary', reachable: $primary->reachable);
        }

        foreach ($nodes as $port => $node) {
            $nodeStateByPort[$port] = $node;
        }

        if (isset($nodes[7002]) && isset($primaries[7002]) && !$nodes[7002]->reachable) {
            $primaries[7002] = $this->primary(7002, new SlotRange(10923, 16383), reachable: false);
        }

        return new ChaosClusterView(
            clusterId: 'test-cluster',
            seedPort: 7000,
            topologyHash: 'hash',
            clusterDown: $clusterDown,
            broadlyHealthy: !$clusterDown,
            nodeStateByPort: $nodeStateByPort,
            primaryStateByPort: $primaries,
            replicaStateByPort: [],
            degradedPrimaryPorts: [],
        );
    }

    private function primary(int $port, ?SlotRange $range, bool $reachable = true): ChaosPrimaryState
    {
        return new ChaosPrimaryState(
            port: $port,
            nodeId: sprintf('node-%d', $port),
            reachable: $reachable,
            slotRanges: $range instanceof SlotRange ? [$range] : [],
            replicaPorts: [],
            healthyReplicaCount: 1,
            syncingReplicaCount: 0,
            failedReplicaCount: 0,
        );
    }

    private function node(
        int $port,
        string $role,
        ?int $primaryPort = null,
        bool $reachable = true,
        bool $failed = false,
        bool $handshake = false,
    ): ChaosNodeState {
        return new ChaosNodeState(
            port: $port,
            nodeId: sprintf('node-%d', $port),
            role: $role,
            primaryPort: $primaryPort,
            knownByCluster: true,
            reachable: $reachable,
            isFailed: $failed,
            isHandshake: $handshake,
            isLoading: false,
            isSyncing: false,
            linkStatus: $role === 'replica' ? 'up' : '',
            slotRanges: [],
            pid: null,
            managed: true,
            health: $failed ? 'fail' : 'online',
        );
    }
}
