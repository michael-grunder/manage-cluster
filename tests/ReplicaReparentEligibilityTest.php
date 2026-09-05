<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\ReplicaReparentEligibility;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\TestCase;

final class ReplicaReparentEligibilityTest extends TestCase
{
    public function testHealthyClusterHasNoBlockers(): void
    {
        self::assertSame([], (new ReplicaReparentEligibility())->blockers($this->view(), false));
    }

    public function testClusterDownIsTheOnlyReportedBlocker(): void
    {
        $view = $this->view(clusterDown: true);

        self::assertSame(['cluster is down'], (new ReplicaReparentEligibility())->blockers($view, false));
        self::assertSame(['cluster is down'], (new ReplicaReparentEligibility())->blockers($view, true));
    }

    public function testASingleSlotOwningPrimaryLeavesNowhereToMoveTo(): void
    {
        $view = $this->view(primaries: [
            7000 => $this->primary(7000, new SlotRange(0, 16383)),
            7001 => $this->primary(7001, null),
        ]);

        self::assertSame(
            ['needs at least 2 reachable slot-owning primaries, found 1'],
            (new ReplicaReparentEligibility())->blockers($view, false),
        );
    }

    public function testUnreachablePrimariesDoNotCountAsRecipientsEvenWithUnsafe(): void
    {
        $view = $this->view(
            primaries: [
                7000 => $this->primary(7000, new SlotRange(0, 8191)),
                7001 => $this->primary(7001, new SlotRange(8192, 16383), reachable: false),
            ],
        );

        self::assertSame(
            ['needs at least 2 reachable slot-owning primaries, found 1'],
            (new ReplicaReparentEligibility())->blockers($view, true),
        );
    }

    public function testUnsettledMembershipBlocksNormalRunsOnly(): void
    {
        $view = $this->view(
            nodes: [
                7001 => $this->node(7001, 'primary', failed: true),
                7005 => $this->node(7005, 'replica', primaryPort: 7000, handshake: true),
            ],
        );

        self::assertSame(
            ['failed primaries=7001', 'handshaking nodes=7005'],
            (new ReplicaReparentEligibility())->blockers($view, false),
        );
        self::assertSame([], (new ReplicaReparentEligibility())->blockers($view, true));
    }

    public function testKilledReplicasDoNotBlockReparenting(): void
    {
        $view = $this->view(
            nodes: [7003 => $this->node(7003, 'replica', primaryPort: 7000, reachable: false, failed: true)],
            degradedPrimaryPorts: [7000],
        );

        self::assertSame([], (new ReplicaReparentEligibility())->blockers($view, false));
    }

    /**
     * @param array<int, ChaosPrimaryState>|null $primaries
     * @param array<int, ChaosNodeState> $nodes
     * @param list<int> $degradedPrimaryPorts
     */
    private function view(
        ?array $primaries = null,
        array $nodes = [],
        bool $clusterDown = false,
        array $degradedPrimaryPorts = [],
    ): ChaosClusterView {
        $primaries ??= [
            7000 => $this->primary(7000, new SlotRange(0, 8191)),
            7001 => $this->primary(7001, new SlotRange(8192, 16383)),
        ];

        $nodeStateByPort = [];
        foreach ($primaries as $port => $primary) {
            $nodeStateByPort[$port] = $this->node($port, 'primary', reachable: $primary->reachable);
        }

        foreach ($nodes as $port => $node) {
            $nodeStateByPort[$port] = $node;
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
            degradedPrimaryPorts: $degradedPrimaryPorts,
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
