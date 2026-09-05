<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosCandidateEvent;
use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosEventRecord;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosOptions;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\ChaosRuntimeState;
use Mgrunder\CreateCluster\ClusterManager;
use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\PrimaryFailoverEligibility;
use Mgrunder\CreateCluster\PrimaryFailoverPlan;
use Mgrunder\CreateCluster\PrimaryFailoverPlanner;
use Mgrunder\CreateCluster\SlotRange;
use Mgrunder\CreateCluster\PortRangeFormatter;
use Mgrunder\CreateCluster\ReplicaTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ClusterManagerTest extends TestCase
{
    /**
     * @param list<int> $ports
     */
    #[DataProvider('compactPortListProvider')]
    public function testFormatCompactPortList(array $ports, string $expected): void
    {
        self::assertSame($expected, PortRangeFormatter::formatCompactList($ports));
    }

    /**
     * @return iterable<string, array{ports: list<int>, expected: string}>
     */
    public static function compactPortListProvider(): iterable
    {
        yield 'empty list' => [
            'ports' => [],
            'expected' => '-',
        ];

        yield 'single port' => [
            'ports' => [7000],
            'expected' => '7000',
        ];

        yield 'pair stays expanded' => [
            'ports' => [7000, 7001],
            'expected' => '7000 7001',
        ];

        yield 'long run becomes range' => [
            'ports' => [7000, 7001, 7002, 7003, 7004, 7005],
            'expected' => '7000-7005',
        ];

        yield 'mixed runs stay compact' => [
            'ports' => [7000, 7001, 7002, 7003, 7004, 7005, 7008, 7009, 7012, 7014, 7015],
            'expected' => '7000-7005 7008 7009 7012 7014 7015',
        ];
    }

    public function testFormatShutdownFailureMessageUsesGroupedPorts(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('formatShutdownFailureMessage');

        $message = $method->invoke(
            $manager,
            [7001, 7003],
            1,
            'Could not connect to Valkey at 127.0.0.1:7001: Connection refused',
        );

        self::assertSame(
            'SHUTDOWN processes for ports 7001 7003 exited with status 1: Could not connect to Valkey at 127.0.0.1:7001: Connection refused',
            $message,
        );
    }

    public function testResolveReplicaTargetReturnsMatchingReplica(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();

        $replica = $this->invokeResolveReplicaTarget($manager, 7002, false);

        self::assertSame(7002, $replica->port);
    }

    public function testResolveReplicaTargetReturnsReplicaWhenPrimaryMatches(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();

        $replica = $this->invokeResolveReplicaTarget($manager, 7002, false, 7000);

        self::assertSame(7002, $replica->port);
    }

    public function testResolveReplicaTargetRejectsReplicaOnDifferentPrimary(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(<<<'MESSAGE'
Replica 7004 belongs to primary 7001, not primary 7000.
Valid replicas by primary:
  7000: 7002 (fail), 7003 (online)
MESSAGE);

        $this->invokeResolveReplicaTarget($manager, 7004, false, 7000);
    }

    public function testResolveReplicaTargetRejectsPrimaryWithTopologyMessage(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(<<<'MESSAGE'
Port 7000 is a primary, not a replica.
Valid replicas by primary:
  7000: 7002 (fail), 7003 (online)
  7001: 7004 (online)
MESSAGE);

        $this->invokeResolveReplicaTarget($manager, 7000, false);
    }

    public function testResolveReplicaTargetRejectsHealthyReplicaForRestart(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(<<<'MESSAGE'
Replica 7003 belongs to primary 7000 but is not in fail state.
Restartable failed replicas by primary:
  7000: 7002 (fail)
  7001: none
MESSAGE);

        $this->invokeResolveReplicaTarget($manager, 7003, true);
    }

    public function testResolveReplicaTargetsReturnsAllReplicasGroupedByTopologyOrder(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $targets = $this->invokeResolveReplicaTargets($manager, false, null);

        self::assertSame([7002, 7003, 7004], array_map(
            static fn (ReplicaTarget $target): int => $target->replica->port,
            $targets,
        ));
        self::assertSame([7000, 7000, 7001], array_map(
            static fn (ReplicaTarget $target): int => $target->primaryPort,
            $targets,
        ));
    }

    public function testResolveReplicaTargetsCanBeScopedToPrimary(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $targets = $this->invokeResolveReplicaTargets($manager, false, 7000);

        self::assertSame([7002, 7003], array_map(
            static fn (ReplicaTarget $target): int => $target->replica->port,
            $targets,
        ));
    }

    public function testResolveReplicaTargetsCanSelectOnlyFailedReplicas(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $targets = $this->invokeResolveReplicaTargets($manager, true, null);

        self::assertSame([7002], array_map(
            static fn (ReplicaTarget $target): int => $target->replica->port,
            $targets,
        ));
    }

    public function testResolveReplicaTargetsRejectsPrimaryWithoutMatchingFailedReplicas(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveReplicaTargets');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(<<<'MESSAGE'
No failed replicas found for primary 7001.
Restartable failed replicas by primary:
  7001: none
MESSAGE);

        $method->invoke($manager, $this->clusterShardsFixture(), true, 7001);
    }

    public function testReplicaTargetStateMatchesDownAndUpClusterState(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('replicaTargetMatchesDesiredClusterState');

        $target = new ReplicaTarget($this->node(7002, 'replica', 'fail'), 7000);

        self::assertTrue($method->invoke($manager, $this->clusterShardsFixture(), $target, 'down'));
        self::assertFalse($method->invoke($manager, $this->clusterShardsFixture(), $target, 'up'));

        $healthyTarget = new ReplicaTarget($this->node(7003, 'replica', 'online'), 7000);

        self::assertTrue($method->invoke($manager, $this->clusterShardsFixture(), $healthyTarget, 'up'));
        self::assertFalse($method->invoke($manager, $this->clusterShardsFixture(), $healthyTarget, 'down'));
    }

    public function testReplicaStateWaitProgressSortsPortsAndShowsOffsetsForRestart(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('formatReplicaStateWaitProgress');

        $pending = [
            7005 => new ReplicaTarget($this->node(7005, 'replica', 'fail'), 7000),
            7003 => new ReplicaTarget($this->node(7003, 'replica', 'fail'), 7000),
            7004 => new ReplicaTarget($this->node(7004, 'replica', 'fail'), 7000),
        ];
        $shards = [
            new ClusterShardStatus(
                slots: [new SlotRange(0, 16383)],
                master: $this->node(7000, 'master', 'online', 1_000),
                replicas: [
                    $this->node(7005, 'replica', 'online', 250),
                    $this->node(7003, 'replica', 'online', 500),
                    $this->node(7004, 'replica', 'online', 1_000),
                ],
            ),
        ];

        self::assertSame(
            'Waiting for replicas 7003-7005 to be reported up | offsets 7003 50% (500/1000), 7004 100% (1000/1000), 7005 25% (250/1000)',
            $method->invoke($manager, $pending, $shards, 'up'),
        );
    }

    public function testReplicaStateWaitProgressOmitsOffsetsForKillWait(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('formatReplicaStateWaitProgress');

        $pending = [
            7005 => new ReplicaTarget($this->node(7005, 'replica', 'online'), 7000),
            7003 => new ReplicaTarget($this->node(7003, 'replica', 'online'), 7000),
            7004 => new ReplicaTarget($this->node(7004, 'replica', 'online'), 7000),
        ];

        self::assertSame(
            'Waiting for replicas 7003-7005 to be reported down',
            $method->invoke($manager, $pending, $this->clusterShardsFixture(), 'down'),
        );
    }

    public function testWriteNodeConfigurationPersistsStartConfigDirectives(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('writeNodeConfiguration');

        $clusterDir = sprintf('%s/manage-cluster-test-%s', sys_get_temp_dir(), bin2hex(random_bytes(4)));
        self::assertTrue(mkdir($clusterDir));

        try {
            $configPath = $method->invoke(
                $manager,
                $clusterDir,
                7000,
                null,
                false,
                null,
                [
                    ['replica-serve-stale-data', 'no'],
                    ['save', ''],
                ],
            );

            self::assertIsString($configPath);
            $config = file_get_contents($configPath);
            self::assertIsString($config);
            self::assertStringContainsString("cluster-allow-replica-migration no\n", $config);
            self::assertStringContainsString("replica-serve-stale-data no\n", $config);
            self::assertStringContainsString("save \"\"\n", $config);
        } finally {
            $this->removeDirectory($clusterDir);
        }
    }

    private function newClusterManagerWithoutConstructor(): ClusterManager
    {
        $reflection = new ReflectionClass(ClusterManager::class);

        /** @var ClusterManager $manager */
        $manager = $reflection->newInstanceWithoutConstructor();

        return $manager;
    }

    private function invokeResolveReplicaTarget(
        ClusterManager $manager,
        int $replicaPort,
        bool $failedOnly,
        ?int $primaryPort = null,
    ): ClusterNodeStatus {
        $target = $this->invokeResolveReplicaTargetWithPrimary($manager, $replicaPort, $failedOnly, $primaryPort);

        return $target->replica;
    }

    private function invokeResolveReplicaTargetWithPrimary(
        ClusterManager $manager,
        int $replicaPort,
        bool $failedOnly,
        ?int $primaryPort,
    ): ReplicaTarget {
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveReplicaTargetWithPrimary');
        $target = $method->invoke($manager, $this->clusterShardsFixture(), $replicaPort, $failedOnly, $primaryPort);

        self::assertInstanceOf(ReplicaTarget::class, $target);

        return $target;
    }

    /**
     * @return list<ReplicaTarget>
     */
    private function invokeResolveReplicaTargets(ClusterManager $manager, bool $failedOnly, ?int $primaryPort): array
    {
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveReplicaTargets');
        $targets = $method->invoke($manager, $this->clusterShardsFixture(), $failedOnly, $primaryPort);

        self::assertIsArray($targets);

        $typedTargets = [];
        foreach ($targets as $target) {
            self::assertInstanceOf(ReplicaTarget::class, $target);
            $typedTargets[] = $target;
        }

        return $typedTargets;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        self::assertNotFalse($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = sprintf('%s/%s', $dir, $entry);
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                self::assertTrue(unlink($path));
            }
        }

        self::assertTrue(rmdir($dir));
    }


    public function testPrimaryFailoverConvergesOnlyWhenTheShardFullyReversesRoles(): void
    {
        $event = $this->primaryFailoverEvent();

        $view = $this->failoverView(
            promotedRanges: [new SlotRange(0, 5461)],
            demotedRole: 'replica',
            demotedPrimaryPort: 7003,
        );

        self::assertTrue($this->invokeIsPrimaryFailoverSatisfied($event, $view));
    }

    /**
     * @param array{promotedRanges?: list<SlotRange>, promotedNodeId?: string, promotedReachable?: bool, demotedRole?: string, demotedPrimaryPort?: int|null, demotedSyncing?: bool, demotedReachable?: bool, clusterDown?: bool, dropPromotedPrimary?: bool} $overrides
     */
    #[DataProvider('unfinishedPrimaryFailoverProvider')]
    public function testPrimaryFailoverIsUnfinishedUntilTheWholeShardAgrees(array $overrides): void
    {
        $view = $this->failoverView(
            promotedRanges: $overrides['promotedRanges'] ?? [new SlotRange(0, 5461)],
            demotedRole: $overrides['demotedRole'] ?? 'replica',
            demotedPrimaryPort: array_key_exists('demotedPrimaryPort', $overrides) ? $overrides['demotedPrimaryPort'] : 7003,
            promotedNodeId: $overrides['promotedNodeId'] ?? 'node-7003',
            promotedReachable: $overrides['promotedReachable'] ?? true,
            demotedSyncing: $overrides['demotedSyncing'] ?? false,
            demotedReachable: $overrides['demotedReachable'] ?? true,
            clusterDown: $overrides['clusterDown'] ?? false,
            dropPromotedPrimary: $overrides['dropPromotedPrimary'] ?? false,
        );

        self::assertFalse($this->invokeIsPrimaryFailoverSatisfied($this->primaryFailoverEvent(), $view));
    }

    /**
     * @return iterable<string, array{overrides: array<string, mixed>}>
     */
    public static function unfinishedPrimaryFailoverProvider(): iterable
    {
        yield 'promotion has not happened yet' => ['overrides' => ['dropPromotedPrimary' => true]];
        yield 'promoted node is unreachable' => ['overrides' => ['promotedReachable' => false]];
        yield 'old primary still serves its role' => ['overrides' => ['demotedRole' => 'primary', 'demotedPrimaryPort' => null]];
        yield 'old primary follows someone else' => ['overrides' => ['demotedPrimaryPort' => 7004]];
        yield 'old primary is still resynchronizing' => ['overrides' => ['demotedSyncing' => true]];
        yield 'old primary is unreachable' => ['overrides' => ['demotedReachable' => false]];
        yield 'port came back with a new node id' => ['overrides' => ['promotedNodeId' => 'node-7003-restarted']];
        yield 'promoted node owns only part of the slots' => ['overrides' => ['promotedRanges' => [new SlotRange(0, 2730)]]];
        yield 'cluster is down' => ['overrides' => ['clusterDown' => true]];
    }

    public function testPrimaryFailoverPrefersRoleReversalAndDeprioritizesImmediateFailback(): void
    {
        $view = $this->chaosViewWithThreeShards();
        $runtime = new ChaosRuntimeState(
            clusterId: 'test-cluster',
            seedPort: 7000,
            startedAt: 0.0,
            allowedCategories: [ChaosOptions::CATEGORY_PRIMARY_FAILOVER],
        );

        // An earlier completed failover promoted 7003 over 7000, so 7000 is now
        // a replica of 7003 and promoting it back is the role-reversal case.
        $runtime->rememberHistory(new ChaosEventRecord(
            id: 1,
            category: ChaosOptions::CATEGORY_PRIMARY_FAILOVER,
            status: 'completed',
            targetPort: 7003,
            targetPrimaryPort: 7000,
            startedAt: 0.0,
            completedAt: 1.0,
            summary: 'primary-failover promote=7003 demote=7000',
            postcondition: 'promoted',
        ));

        $scores = $this->invokePrimaryFailoverCandidateScores($view, $runtime);

        // 7000 was demoted by chaos, but promoting it back right now would undo
        // the previous event, so the failback is the least attractive option.
        self::assertSame([7005 => 3, 7006 => 3, 7000 => 0], $scores);

        $runtime->rememberHistory(new ChaosEventRecord(
            id: 2,
            category: ChaosOptions::CATEGORY_SLOT_MIGRATION,
            status: 'completed',
            targetPort: 7002,
            targetPrimaryPort: 7001,
            startedAt: 1.0,
            completedAt: 2.0,
            summary: 'slot-migration',
            postcondition: 'moved',
        ));

        self::assertSame([7005 => 3, 7006 => 3, 7000 => 5], $this->invokePrimaryFailoverCandidateScores($view, $runtime));
    }

    public function testPrimaryFailoverCandidatesAreEmptyWhileTheClusterBlocksFailover(): void
    {
        $view = $this->chaosViewWithThreeShards(clusterDown: true);
        $runtime = new ChaosRuntimeState(
            clusterId: 'test-cluster',
            seedPort: 7000,
            startedAt: 0.0,
            allowedCategories: [ChaosOptions::CATEGORY_PRIMARY_FAILOVER],
        );

        self::assertSame([], $this->invokePrimaryFailoverCandidateScores($view, $runtime));
    }

    private function primaryFailoverEvent(): ChaosEventRecord
    {
        $plan = new PrimaryFailoverPlan(
            primaryPort: 7000,
            primaryNodeId: 'node-7000',
            replicaPort: 7003,
            replicaNodeId: 'node-7003',
            ranges: [new SlotRange(0, 5461)],
            replicationLagBytes: 0,
        );

        return new ChaosEventRecord(
            id: 1,
            category: ChaosOptions::CATEGORY_PRIMARY_FAILOVER,
            status: 'waiting',
            targetPort: $plan->replicaPort,
            targetPrimaryPort: $plan->primaryPort,
            startedAt: 0.0,
            completedAt: null,
            summary: $plan->summary(),
            postcondition: $plan->postcondition(),
            primaryFailoverPlan: $plan,
        );
    }

    private function invokeIsPrimaryFailoverSatisfied(ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $method = new ReflectionClass($manager)->getMethod('isPrimaryFailoverSatisfied');

        $satisfied = $method->invoke($manager, $event, $view);
        self::assertIsBool($satisfied);

        return $satisfied;
    }

    /**
     * @return array<int, int> candidate score keyed by the replica chaos would promote
     */
    private function invokePrimaryFailoverCandidateScores(ChaosClusterView $view, ChaosRuntimeState $runtime): array
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $reflection->getProperty('primaryFailoverEligibility')->setValue($manager, new PrimaryFailoverEligibility());
        $reflection->getProperty('primaryFailoverPlanner')->setValue($manager, new PrimaryFailoverPlanner());

        $candidates = $reflection->getMethod('buildPrimaryFailoverCandidates')->invoke(
            $manager,
            $view,
            $runtime,
            $this->chaosOptions(),
        );

        self::assertIsArray($candidates);

        $scores = [];
        foreach ($candidates as $candidate) {
            self::assertInstanceOf(ChaosCandidateEvent::class, $candidate);
            self::assertNotNull($candidate->targetPort);
            $scores[$candidate->targetPort] = $candidate->score;
        }

        return $scores;
    }

    private function chaosOptions(): ChaosOptions
    {
        return new ChaosOptions(
            categories: [ChaosOptions::CATEGORY_PRIMARY_FAILOVER],
            intervalSeconds: 8,
            maxEvents: null,
            maxFailures: 5,
            dryRun: false,
            watch: false,
            seed: null,
            waitTimeoutSeconds: 60,
            cooldownSeconds: 2,
            allowSlotMigration: false,
            allowPrimaryFailover: true,
            unsafe: false,
        );
    }

    /**
     * Three settled shards where 7003 already leads the first shard with the
     * demoted 7000 following it, so both a failback and untouched shards are
     * available to the planner.
     */
    private function chaosViewWithThreeShards(bool $clusterDown = false): ChaosClusterView
    {
        $shards = [
            7003 => ['range' => new SlotRange(0, 5461), 'replicas' => [7000]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7005]],
            7002 => ['range' => new SlotRange(10923, 16383), 'replicas' => [7006]],
        ];

        $nodeStateByPort = [];
        $primaryStateByPort = [];
        foreach ($shards as $primaryPort => $shard) {
            $nodeStateByPort[$primaryPort] = $this->chaosNode($primaryPort, 'primary', slotRanges: [$shard['range']]);
            foreach ($shard['replicas'] as $replicaPort) {
                $nodeStateByPort[$replicaPort] = $this->chaosNode($replicaPort, 'replica', primaryPort: $primaryPort);
            }

            $primaryStateByPort[$primaryPort] = new ChaosPrimaryState(
                port: $primaryPort,
                nodeId: sprintf('node-%d', $primaryPort),
                reachable: true,
                slotRanges: [$shard['range']],
                replicaPorts: $shard['replicas'],
                healthyReplicaCount: count($shard['replicas']),
                syncingReplicaCount: 0,
                failedReplicaCount: 0,
            );
        }

        return new ChaosClusterView(
            clusterId: 'test-cluster',
            seedPort: 7000,
            topologyHash: 'hash',
            clusterDown: $clusterDown,
            broadlyHealthy: !$clusterDown,
            nodeStateByPort: $nodeStateByPort,
            primaryStateByPort: $primaryStateByPort,
            replicaStateByPort: [],
            degradedPrimaryPorts: [],
        );
    }

    /**
     * @param list<SlotRange> $promotedRanges
     */
    private function failoverView(
        array $promotedRanges,
        string $demotedRole,
        ?int $demotedPrimaryPort,
        string $promotedNodeId = 'node-7003',
        bool $promotedReachable = true,
        bool $demotedSyncing = false,
        bool $demotedReachable = true,
        bool $clusterDown = false,
        bool $dropPromotedPrimary = false,
    ): ChaosClusterView {
        $promotedPrimary = new ChaosPrimaryState(
            port: 7003,
            nodeId: $promotedNodeId,
            reachable: $promotedReachable,
            slotRanges: $promotedRanges,
            replicaPorts: [7000],
            healthyReplicaCount: 1,
            syncingReplicaCount: 0,
            failedReplicaCount: 0,
        );

        return new ChaosClusterView(
            clusterId: 'test-cluster',
            seedPort: 7000,
            topologyHash: 'hash',
            clusterDown: $clusterDown,
            broadlyHealthy: !$clusterDown,
            nodeStateByPort: [
                7000 => $this->chaosNode(
                    7000,
                    $demotedRole,
                    primaryPort: $demotedPrimaryPort,
                    reachable: $demotedReachable,
                    syncing: $demotedSyncing,
                    slotRanges: $demotedRole === 'primary' ? $promotedRanges : [],
                ),
                7003 => $this->chaosNode(7003, 'primary', slotRanges: $promotedRanges, nodeId: $promotedNodeId),
            ],
            primaryStateByPort: $dropPromotedPrimary ? [] : [7003 => $promotedPrimary],
            replicaStateByPort: [],
            degradedPrimaryPorts: [],
        );
    }

    /**
     * @param list<SlotRange> $slotRanges
     */
    private function chaosNode(
        int $port,
        string $role,
        ?int $primaryPort = null,
        bool $reachable = true,
        bool $syncing = false,
        array $slotRanges = [],
        ?string $nodeId = null,
    ): ChaosNodeState {
        return new ChaosNodeState(
            port: $port,
            nodeId: $nodeId ?? sprintf('node-%d', $port),
            role: $role,
            primaryPort: $primaryPort,
            knownByCluster: true,
            reachable: $reachable,
            isFailed: false,
            isHandshake: false,
            isLoading: false,
            isSyncing: $syncing,
            linkStatus: $role === 'replica' ? 'up' : '',
            slotRanges: $slotRanges,
            pid: null,
            managed: true,
            health: 'online',
            replicationOffset: 1_000,
        );
    }

    /**
     * @return list<ClusterShardStatus>
     */
    private function clusterShardsFixture(): array
    {
        return [
            new ClusterShardStatus(
                slots: [new SlotRange(0, 8191)],
                master: $this->node(7000, 'master', 'online'),
                replicas: [
                    $this->node(7002, 'replica', 'fail'),
                    $this->node(7003, 'replica', 'online'),
                ],
            ),
            new ClusterShardStatus(
                slots: [new SlotRange(8192, 16383)],
                master: $this->node(7001, 'master', 'online'),
                replicas: [
                    $this->node(7004, 'replica', 'online'),
                ],
            ),
        ];
    }

    private function node(int $port, string $role, string $health, int $replicationOffset = 0): ClusterNodeStatus
    {
        return new ClusterNodeStatus(
            id: str_pad((string) $port, 40, '0'),
            ip: '127.0.0.1',
            port: $port,
            endpoint: '',
            role: $role,
            replicationOffset: $replicationOffset,
            health: $health,
        );
    }
}
