<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\PrimaryFailoverEligibility;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\TestCase;

final class PrimaryFailoverEligibilityTest extends TestCase
{
    public function testHealthyClusterHasNoBlockers(): void
    {
        self::assertSame([], (new PrimaryFailoverEligibility())->blockers($this->view(), false));
    }

    public function testClusterDownIsTheOnlyReportedBlocker(): void
    {
        $view = $this->view(clusterDown: true);

        self::assertSame(['cluster is down'], (new PrimaryFailoverEligibility())->blockers($view, false));
        self::assertSame(['cluster is down'], (new PrimaryFailoverEligibility())->blockers($view, true));
    }

    public function testTooFewSlotOwningPrimariesBlocksFailover(): void
    {
        $view = $this->view(primaries: [
            7000 => $this->primary(7000, new SlotRange(0, 8191)),
            7001 => $this->primary(7001, new SlotRange(8192, 16383)),
        ]);

        self::assertSame(
            ['needs at least 3 slot-owning primaries, found 2'],
            (new PrimaryFailoverEligibility())->blockers($view, false),
        );
    }

    public function testEmptyPrimariesDoNotCountAsVoters(): void
    {
        $view = $this->view(primaries: [
            7000 => $this->primary(7000, new SlotRange(0, 8191)),
            7001 => $this->primary(7001, new SlotRange(8192, 16383)),
            7002 => new ChaosPrimaryState(
                port: 7002,
                nodeId: 'node-7002',
                reachable: true,
                slotRanges: [],
                replicaPorts: [],
                healthyReplicaCount: 1,
                syncingReplicaCount: 0,
                failedReplicaCount: 0,
            ),
        ]);

        self::assertSame(
            ['needs at least 3 slot-owning primaries, found 2'],
            (new PrimaryFailoverEligibility())->blockers($view, false),
        );
    }

    public function testLostVotingMajorityBlocksFailoverEvenWithUnsafe(): void
    {
        $view = $this->view(
            primaries: [
                7000 => $this->primary(7000, new SlotRange(0, 5461)),
                7001 => $this->primary(7001, new SlotRange(5462, 10922), reachable: false),
                7002 => $this->primary(7002, new SlotRange(10923, 16383), reachable: false),
            ],
            nodes: [
                7000 => $this->node(7000, 'primary'),
                7001 => $this->node(7001, 'primary', reachable: false),
                7002 => $this->node(7002, 'primary', reachable: false),
            ],
        );

        self::assertSame(
            ['no voting majority: 1 of 3 slot-owning primaries reachable'],
            (new PrimaryFailoverEligibility())->blockers($view, true),
        );
    }

    public function testUnsettledPrimariesAndHandshakesBlockNormalRuns(): void
    {
        $view = $this->view(
            nodes: [
                7002 => $this->node(7002, 'primary', reachable: false),
                7000 => $this->node(7000, 'primary', failed: true),
                7005 => $this->node(7005, 'replica', primaryPort: 7000, handshake: true),
            ],
        );

        self::assertSame(
            [
                'unreachable primaries=7002',
                'failed primaries=7000',
                'handshaking nodes=7005',
            ],
            (new PrimaryFailoverEligibility())->blockers($view, false),
        );
    }

    public function testUnsafeSkipsTheSettledClusterChecks(): void
    {
        $view = $this->view(
            nodes: [
                7002 => $this->node(7002, 'primary', reachable: false),
                7005 => $this->node(7005, 'replica', primaryPort: 7000, handshake: true),
            ],
        );

        self::assertSame([], (new PrimaryFailoverEligibility())->blockers($view, true));
    }

    public function testKilledReplicasDoNotBlockFailover(): void
    {
        $view = $this->view(
            nodes: [7003 => $this->node(7003, 'replica', primaryPort: 7000, reachable: false, failed: true)],
            degradedPrimaryPorts: [7000],
        );

        self::assertSame([], (new PrimaryFailoverEligibility())->blockers($view, false));
    }

    public function testUnknownManagedNodesAreIgnored(): void
    {
        $view = $this->view(
            nodes: [7009 => $this->node(7009, 'primary', reachable: false, failed: true, knownByCluster: false)],
        );

        self::assertSame([], (new PrimaryFailoverEligibility())->blockers($view, false));
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

    private function primary(int $port, SlotRange $range, bool $reachable = true): ChaosPrimaryState
    {
        return new ChaosPrimaryState(
            port: $port,
            nodeId: sprintf('node-%d', $port),
            reachable: $reachable,
            slotRanges: [$range],
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
        bool $knownByCluster = true,
    ): ChaosNodeState {
        return new ChaosNodeState(
            port: $port,
            nodeId: sprintf('node-%d', $port),
            role: $role,
            primaryPort: $primaryPort,
            knownByCluster: $knownByCluster,
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
