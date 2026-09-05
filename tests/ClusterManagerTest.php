<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosCandidateEvent;
use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosEventRecord;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosCategorySelection;
use Mgrunder\CreateCluster\ChaosOptions;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\ChaosRuntimeState;
use Mgrunder\CreateCluster\ClusterManager;
use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\PrimaryAddPlan;
use Mgrunder\CreateCluster\PrimaryAddPlanner;
use Mgrunder\CreateCluster\PrimaryFailoverEligibility;
use Mgrunder\CreateCluster\PrimaryMembershipEligibility;
use Mgrunder\CreateCluster\PrimaryRemovePlan;
use Mgrunder\CreateCluster\PrimaryRemovePlanner;
use Mgrunder\CreateCluster\SlotMigrationPlan;
use Mgrunder\CreateCluster\PrimaryFailoverPlan;
use Mgrunder\CreateCluster\PrimaryFailoverPlanner;
use Mgrunder\CreateCluster\ReplicaReparentEligibility;
use Mgrunder\CreateCluster\ReplicaReparentPlan;
use Mgrunder\CreateCluster\ReplicaReparentPlanner;
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

        // One unrelated event is not enough: a two-shard cluster could
        // otherwise alternate shards and fail back on every other event.
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

        self::assertSame([7005 => 3, 7006 => 3, 7000 => 0], $this->invokePrimaryFailoverCandidateScores($view, $runtime));

        // Once the promotion falls out of the window the shard is fair game
        // again, and restoring the node chaos demoted is the preferred move.
        $this->ageOutRecencyWindow($runtime);

        self::assertSame([7005 => 3, 7006 => 3, 7000 => 5], $this->invokePrimaryFailoverCandidateScores($view, $runtime));

        // Once chaos has promoted 7000 again, it is no longer a node chaos
        // demoted, so the role-reversal bonus stops applying instead of
        // marking the pair permanently interesting.
        $runtime->rememberHistory($this->completedFailoverEvent(promotedPort: 7000, demotedPort: 7003));

        self::assertSame([7005 => 3, 7006 => 3, 7000 => 3], $this->invokePrimaryFailoverCandidateScores($view, $runtime));
    }

    private function completedFailoverEvent(int $promotedPort, int $demotedPort): ChaosEventRecord
    {
        return new ChaosEventRecord(
            id: 99,
            category: ChaosOptions::CATEGORY_PRIMARY_FAILOVER,
            status: 'completed',
            targetPort: $promotedPort,
            targetPrimaryPort: $demotedPort,
            startedAt: 0.0,
            completedAt: 1.0,
            summary: sprintf('primary-failover promote=%d demote=%d', $promotedPort, $demotedPort),
            postcondition: 'promoted',
        );
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

    private function chaosOptions(?ChaosCategorySelection $categories = null): ChaosOptions
    {
        return new ChaosOptions(
            categories: $categories ?? ChaosCategorySelection::fromCategories([ChaosOptions::CATEGORY_PRIMARY_FAILOVER]),
            intervalSeconds: 8,
            maxEvents: null,
            maxFailures: 5,
            abortOnFailure: false,
            dryRun: false,
            watch: false,
            seed: null,
            waitTimeoutSeconds: 60,
            cooldownSeconds: 2,
            allowSlotMigration: false,
            allowPrimaryFailover: true,
            allowReplicaReparent: false,
            allowPrimaryAdd: false,
            allowPrimaryRemove: false,
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
        ?string $linkStatus = null,
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
            linkStatus: $role === 'replica' ? ($linkStatus ?? 'up') : '',
            slotRanges: $slotRanges,
            pid: null,
            managed: true,
            health: 'online',
            replicationOffset: 1_000,
        );
    }


    public function testReplicaReparentConvergesWhenTheSameNodeMovesShards(): void
    {
        $view = $this->reparentView(followsPort: 7001, sourceReplicaPorts: [7004], targetReplicaPorts: [7003, 7005]);

        self::assertTrue($this->invokeIsReplicaReparentSatisfied($this->replicaReparentEvent(), $view));
    }

    /**
     * @param array{followsPort?: int|null, sourceReplicaPorts?: list<int>, targetReplicaPorts?: list<int>, replicaNodeId?: string, replicaReachable?: bool, replicaSyncing?: bool, linkStatus?: string, clusterDown?: bool, dropTargetPrimary?: bool} $overrides
     */
    #[DataProvider('unfinishedReplicaReparentProvider')]
    public function testReplicaReparentIsUnfinishedUntilBothShardsAgree(array $overrides): void
    {
        $view = $this->reparentView(
            followsPort: array_key_exists('followsPort', $overrides) ? $overrides['followsPort'] : 7001,
            sourceReplicaPorts: $overrides['sourceReplicaPorts'] ?? [7004],
            targetReplicaPorts: $overrides['targetReplicaPorts'] ?? [7003, 7005],
            replicaNodeId: $overrides['replicaNodeId'] ?? 'node-7003',
            replicaReachable: $overrides['replicaReachable'] ?? true,
            replicaSyncing: $overrides['replicaSyncing'] ?? false,
            linkStatus: $overrides['linkStatus'] ?? 'up',
            clusterDown: $overrides['clusterDown'] ?? false,
            dropTargetPrimary: $overrides['dropTargetPrimary'] ?? false,
        );

        self::assertFalse($this->invokeIsReplicaReparentSatisfied($this->replicaReparentEvent(), $view));
    }

    /**
     * @return iterable<string, array{overrides: array<string, mixed>}>
     */
    public static function unfinishedReplicaReparentProvider(): iterable
    {
        yield 'replica still follows the donor' => ['overrides' => [
            'followsPort' => 7000,
            'sourceReplicaPorts' => [7003, 7004],
            'targetReplicaPorts' => [7005],
        ]];
        yield 'donor still lists the replica' => ['overrides' => ['sourceReplicaPorts' => [7003, 7004]]];
        yield 'recipient does not list the replica yet' => ['overrides' => ['targetReplicaPorts' => [7005]]];
        yield 'recipient is not a primary yet' => ['overrides' => ['dropTargetPrimary' => true]];
        yield 'replica is still resynchronizing' => ['overrides' => ['replicaSyncing' => true]];
        yield 'replication link is not up' => ['overrides' => ['linkStatus' => 'down']];
        yield 'replica is unreachable' => ['overrides' => ['replicaReachable' => false]];
        yield 'port came back with a new node id' => ['overrides' => ['replicaNodeId' => 'node-7003-replaced']];
        yield 'cluster is down' => ['overrides' => ['clusterDown' => true]];
    }

    public function testReplicaReparentPrefersDegradedRecipients(): void
    {
        $runtime = $this->chaosRuntime();

        self::assertSame(
            [[7003, 7001, 2], [7003, 7002, 5], [7004, 7001, 2], [7004, 7002, 5]],
            $this->invokeReplicaReparentCandidateScores($this->chaosViewForReparent(), $runtime),
        );
    }

    public function testReplicaReparentDeprioritizesMovingTheSameReplicaTwice(): void
    {
        $runtime = $this->chaosRuntime();
        $runtime->rememberHistory($this->completedReparentEvent(replicaPort: 7003, sourcePort: 7001, targetPort: 7000));

        // 7003 was just moved, so moving it again right away is the least
        // attractive option even though it could return to 7001.
        self::assertSame(
            [[7003, 7001, -1], [7003, 7002, 2], [7004, 7001, 2], [7004, 7002, 5]],
            $this->invokeReplicaReparentCandidateScores($this->chaosViewForReparent(), $runtime),
        );
    }

    public function testReplicaReparentPrefersRestoringAnEarlierLayout(): void
    {
        $runtime = $this->chaosRuntime();
        $runtime->rememberHistory($this->completedReparentEvent(replicaPort: 7003, sourcePort: 7001, targetPort: 7000));
        $this->ageOutRecencyWindow($runtime);

        self::assertSame(
            [[7003, 7001, 3], [7003, 7002, 5], [7004, 7001, 2], [7004, 7002, 5]],
            $this->invokeReplicaReparentCandidateScores($this->chaosViewForReparent(), $runtime),
        );
    }

    public function testReplicaReparentCandidatesAreEmptyWhileTheClusterBlocksIt(): void
    {
        self::assertSame(
            [],
            $this->invokeReplicaReparentCandidateScores($this->chaosViewForReparent(clusterDown: true), $this->chaosRuntime()),
        );
    }

    /**
     * Push enough unrelated events to move everything already in the history
     * out of the repetition window, so the damping stops applying.
     */
    private function ageOutRecencyWindow(ChaosRuntimeState $runtime): void
    {
        for ($index = 0; $index < ChaosRuntimeState::RECENCY_WINDOW; $index++) {
            $runtime->rememberHistory(new ChaosEventRecord(
                id: count($runtime->history) + 1,
                category: ChaosOptions::CATEGORY_SLOT_MIGRATION,
                status: 'completed',
                targetPort: 7002,
                targetPrimaryPort: 7001,
                startedAt: (float) $index,
                completedAt: $index + 1.0,
                summary: 'slot-migration',
                postcondition: 'moved',
            ));
        }
    }

    private function chaosRuntime(): ChaosRuntimeState
    {
        return new ChaosRuntimeState(
            clusterId: 'test-cluster',
            seedPort: 7000,
            startedAt: 0.0,
            allowedCategories: [ChaosOptions::CATEGORY_REPLICA_REPARENT],
        );
    }

    private function completedReparentEvent(int $replicaPort, int $sourcePort, int $targetPort): ChaosEventRecord
    {
        $plan = new ReplicaReparentPlan(
            replicaPort: $replicaPort,
            replicaNodeId: sprintf('node-%d', $replicaPort),
            sourcePrimaryPort: $sourcePort,
            sourcePrimaryNodeId: sprintf('node-%d', $sourcePort),
            targetPrimaryPort: $targetPort,
            targetPrimaryNodeId: sprintf('node-%d', $targetPort),
        );

        return new ChaosEventRecord(
            id: 1,
            category: ChaosOptions::CATEGORY_REPLICA_REPARENT,
            status: 'completed',
            targetPort: $replicaPort,
            targetPrimaryPort: $targetPort,
            startedAt: 0.0,
            completedAt: 1.0,
            summary: $plan->summary(),
            postcondition: $plan->postcondition(),
            replicaReparentPlan: $plan,
        );
    }

    private function replicaReparentEvent(): ChaosEventRecord
    {
        $plan = new ReplicaReparentPlan(
            replicaPort: 7003,
            replicaNodeId: 'node-7003',
            sourcePrimaryPort: 7000,
            sourcePrimaryNodeId: 'node-7000',
            targetPrimaryPort: 7001,
            targetPrimaryNodeId: 'node-7001',
        );

        return new ChaosEventRecord(
            id: 1,
            category: ChaosOptions::CATEGORY_REPLICA_REPARENT,
            status: 'waiting',
            targetPort: $plan->replicaPort,
            targetPrimaryPort: $plan->targetPrimaryPort,
            startedAt: 0.0,
            completedAt: null,
            summary: $plan->summary(),
            postcondition: $plan->postcondition(),
            replicaReparentPlan: $plan,
        );
    }

    private function invokeIsReplicaReparentSatisfied(ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $method = new ReflectionClass($manager)->getMethod('isReplicaReparentSatisfied');

        $satisfied = $method->invoke($manager, $event, $view);
        self::assertIsBool($satisfied);

        return $satisfied;
    }

    /**
     * @return list<array{0:int,1:int,2:int}> replica port, recipient primary port, and score
     */
    private function invokeReplicaReparentCandidateScores(ChaosClusterView $view, ChaosRuntimeState $runtime): array
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $reflection->getProperty('replicaReparentEligibility')->setValue($manager, new ReplicaReparentEligibility());
        $reflection->getProperty('replicaReparentPlanner')->setValue($manager, new ReplicaReparentPlanner());

        $candidates = $reflection->getMethod('buildReplicaReparentCandidates')->invoke(
            $manager,
            $view,
            $runtime,
            $this->chaosOptions(),
        );

        self::assertIsArray($candidates);

        $scores = [];
        foreach ($candidates as $candidate) {
            self::assertInstanceOf(ChaosCandidateEvent::class, $candidate);
            $plan = $candidate->replicaReparentPlan;
            self::assertInstanceOf(ReplicaReparentPlan::class, $plan);
            $scores[] = [$plan->replicaPort, $plan->targetPrimaryPort, $candidate->score];
        }

        return $scores;
    }

    /**
     * A donor with two healthy replicas, a shard with one, and a degraded
     * primary that would welcome either of the donor's replicas.
     */
    private function chaosViewForReparent(bool $clusterDown = false): ChaosClusterView
    {
        $shards = [
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003, 7004]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7005]],
            7002 => ['range' => new SlotRange(10923, 16383), 'replicas' => []],
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
            degradedPrimaryPorts: $clusterDown ? [] : [7002],
        );
    }

    /**
     * @param list<int> $sourceReplicaPorts
     * @param list<int> $targetReplicaPorts
     */
    private function reparentView(
        ?int $followsPort,
        array $sourceReplicaPorts,
        array $targetReplicaPorts,
        string $replicaNodeId = 'node-7003',
        bool $replicaReachable = true,
        bool $replicaSyncing = false,
        string $linkStatus = 'up',
        bool $clusterDown = false,
        bool $dropTargetPrimary = false,
    ): ChaosClusterView {
        $primaryStateByPort = [
            7000 => new ChaosPrimaryState(
                port: 7000,
                nodeId: 'node-7000',
                reachable: true,
                slotRanges: [new SlotRange(0, 8191)],
                replicaPorts: $sourceReplicaPorts,
                healthyReplicaCount: count($sourceReplicaPorts),
                syncingReplicaCount: 0,
                failedReplicaCount: 0,
            ),
        ];

        if (!$dropTargetPrimary) {
            $primaryStateByPort[7001] = new ChaosPrimaryState(
                port: 7001,
                nodeId: 'node-7001',
                reachable: true,
                slotRanges: [new SlotRange(8192, 16383)],
                replicaPorts: $targetReplicaPorts,
                healthyReplicaCount: count($targetReplicaPorts),
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
            nodeStateByPort: [
                7000 => $this->chaosNode(7000, 'primary', slotRanges: [new SlotRange(0, 8191)]),
                7001 => $this->chaosNode(7001, 'primary', slotRanges: [new SlotRange(8192, 16383)]),
                7003 => $this->chaosNode(
                    7003,
                    'replica',
                    primaryPort: $followsPort,
                    reachable: $replicaReachable,
                    syncing: $replicaSyncing,
                    nodeId: $replicaNodeId,
                    linkStatus: $linkStatus,
                ),
            ],
            primaryStateByPort: $primaryStateByPort,
            replicaStateByPort: [],
            degradedPrimaryPorts: [],
        );
    }


    public function testPrimaryAddConvergesWhenTheNewPortServesTheDonatedSlots(): void
    {
        $view = $this->membershipView([
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16367), 'replicas' => [7005]],
            7010 => ['range' => new SlotRange(16368, 16383), 'replicas' => []],
        ]);

        self::assertTrue($this->invokeIsPrimaryAddSatisfied($this->primaryAddEvent(), $view));
    }

    /**
     * @param array<int, array{range: SlotRange|null, replicas: list<int>}> $shards
     */
    #[DataProvider('unfinishedPrimaryAddProvider')]
    public function testPrimaryAddIsUnfinishedUntilOwnershipMoves(array $shards, bool $clusterDown = false): void
    {
        $view = $this->membershipView($shards, clusterDown: $clusterDown);

        self::assertFalse($this->invokeIsPrimaryAddSatisfied($this->primaryAddEvent(), $view));
    }

    /**
     * @return iterable<string, array{shards: array<int, array{range: SlotRange|null, replicas: list<int>}>, clusterDown?: bool}>
     */
    public static function unfinishedPrimaryAddProvider(): iterable
    {
        yield 'new primary has not joined yet' => ['shards' => [
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16383), 'replicas' => [7005]],
        ]];

        yield 'new primary joined but owns nothing' => ['shards' => [
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16383), 'replicas' => [7005]],
            7010 => ['range' => null, 'replicas' => []],
        ]];

        yield 'donor still owns part of the range' => ['shards' => [
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16375), 'replicas' => [7005]],
            7010 => ['range' => new SlotRange(16376, 16383), 'replicas' => []],
        ]];

        yield 'cluster is down' => [
            'shards' => [
                7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
                7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
                7002 => ['range' => new SlotRange(10923, 16367), 'replicas' => [7005]],
                7010 => ['range' => new SlotRange(16368, 16383), 'replicas' => []],
            ],
            'clusterDown' => true,
        ];
    }

    public function testPrimaryRemoveConvergesWhenTheNodeIsGoneAndItsSlotsAreServed(): void
    {
        $view = $this->membershipView([
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003, 7013]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16383), 'replicas' => [7005]],
        ]);

        self::assertTrue($this->invokeIsPrimaryRemoveSatisfied($this->primaryRemoveEvent(), $view));
    }

    /**
     * @param array<int, array{range: SlotRange|null, replicas: list<int>, orphan?: bool}> $shards
     */
    #[DataProvider('unfinishedPrimaryRemoveProvider')]
    public function testPrimaryRemoveIsUnfinishedWhileAnyTraceRemains(array $shards, bool $clusterDown = false): void
    {
        $view = $this->membershipView($shards, clusterDown: $clusterDown);

        self::assertFalse($this->invokeIsPrimaryRemoveSatisfied($this->primaryRemoveEvent(), $view));
    }

    /**
     * @return iterable<string, array{shards: array<int, array{range: SlotRange|null, replicas: list<int>, orphan?: bool}>, clusterDown?: bool}>
     */
    public static function unfinishedPrimaryRemoveProvider(): iterable
    {
        yield 'removed primary is still a primary' => ['shards' => [
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16367), 'replicas' => [7005]],
            7010 => ['range' => new SlotRange(16368, 16383), 'replicas' => []],
        ]];

        yield 'drained slots are not served yet' => ['shards' => [
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003, 7013]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16367), 'replicas' => [7005]],
        ]];

        yield 'a replica still follows the removed primary' => ['shards' => [
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16383), 'replicas' => [7005]],
            7010 => ['range' => null, 'replicas' => [7013], 'orphan' => true],
        ]];

        yield 'cluster is down' => [
            'shards' => [
                7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003, 7013]],
                7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
                7002 => ['range' => new SlotRange(10923, 16383), 'replicas' => [7005]],
            ],
            'clusterDown' => true,
        ];
    }

    public function testPrimaryAddScoresRestoringInventoryAndAvoidsGrowingTwice(): void
    {
        $view = $this->membershipView([
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16383), 'replicas' => [7005]],
        ]);

        $runtime = $this->chaosRuntime();
        $candidate = $this->invokePrimaryAddCandidate($view, $runtime);

        self::assertInstanceOf(ChaosCandidateEvent::class, $candidate);
        self::assertSame(2, $candidate->score);
        self::assertSame(7010, $candidate->targetPort);
        // 7000 owns one slot more than the others, so it seeds the new shard.
        self::assertSame(7000, $candidate->targetPrimaryPort);

        $runtime->rememberHistory($this->membershipEvent(ChaosOptions::CATEGORY_PRIMARY_REMOVE, 7011));
        $restoring = $this->invokePrimaryAddCandidate($view, $runtime);
        self::assertInstanceOf(ChaosCandidateEvent::class, $restoring);
        self::assertSame(4, $restoring->score);

        $runtime->rememberHistory($this->membershipEvent(ChaosOptions::CATEGORY_PRIMARY_ADD, 7012));
        $repeated = $this->invokePrimaryAddCandidate($view, $runtime);
        self::assertInstanceOf(ChaosCandidateEvent::class, $repeated);
        self::assertSame(1, $repeated->score);
    }

    public function testPrimaryAddIsNotOfferedWhileTheClusterBlocksMembershipChurn(): void
    {
        $view = $this->membershipView([
            7000 => ['range' => new SlotRange(0, 8191), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(8192, 16383), 'replicas' => [7004]],
        ]);

        self::assertNull($this->invokePrimaryAddCandidate($view, $this->chaosRuntime(), withPort: false));
    }

    public function testPrimaryRemovePrefersFinishingAnAddAndCheapDrains(): void
    {
        $view = $this->membershipView([
            7000 => ['range' => new SlotRange(0, 5461), 'replicas' => [7003]],
            7001 => ['range' => new SlotRange(5462, 10922), 'replicas' => [7004]],
            7002 => ['range' => new SlotRange(10923, 16367), 'replicas' => [7005]],
            7010 => ['range' => new SlotRange(16368, 16383), 'replicas' => []],
        ]);

        $runtime = $this->chaosRuntime();

        // 7010 owns 16 slots, so its drain fits the batch; the others do not.
        self::assertSame(
            [7001 => 2, 7002 => 2, 7010 => 3],
            $this->invokePrimaryRemoveCandidateScores($view, $runtime),
        );

        $runtime->rememberHistory($this->membershipEvent(ChaosOptions::CATEGORY_PRIMARY_ADD, 7010));
        // The add is the previous event, so removing what it created waits.
        self::assertSame(
            [7001 => 2, 7002 => 2, 7010 => 2],
            $this->invokePrimaryRemoveCandidateScores($view, $runtime),
        );

        // A single unrelated event does not clear the add; the whole window has
        // to pass before the node chaos created becomes a preferred target.
        $runtime->rememberHistory($this->membershipEvent(ChaosOptions::CATEGORY_SLOT_MIGRATION, 7001));
        self::assertSame(
            [7001 => 2, 7002 => 2, 7010 => 2],
            $this->invokePrimaryRemoveCandidateScores($view, $runtime),
        );

        $this->ageOutRecencyWindow($runtime);
        self::assertSame(
            [7001 => 2, 7002 => 2, 7010 => 5],
            $this->invokePrimaryRemoveCandidateScores($view, $runtime),
        );
    }

    private function primaryAddEvent(): ChaosEventRecord
    {
        $plan = new PrimaryAddPlan(
            newPrimaryPort: 7010,
            donorPort: 7002,
            donorNodeId: 'node-7002',
            ranges: [new SlotRange(16368, 16383)],
        );

        return new ChaosEventRecord(
            id: 1,
            category: ChaosOptions::CATEGORY_PRIMARY_ADD,
            status: 'waiting',
            targetPort: $plan->newPrimaryPort,
            targetPrimaryPort: $plan->donorPort,
            startedAt: 0.0,
            completedAt: null,
            summary: $plan->summary(),
            postcondition: $plan->postcondition(),
            primaryAddPlan: $plan,
        );
    }

    private function primaryRemoveEvent(): ChaosEventRecord
    {
        $plan = new PrimaryRemovePlan(
            port: 7010,
            nodeId: 'node-7010',
            drainPlans: [
                new SlotMigrationPlan(
                    sourcePort: 7010,
                    sourceNodeId: 'node-7010',
                    destinationPort: 7002,
                    destinationNodeId: 'node-7002',
                    ranges: [new SlotRange(16368, 16383)],
                ),
            ],
            replicaPorts: [7013],
            replicaRecipientPort: 7000,
            replicaRecipientNodeId: 'node-7000',
        );

        return new ChaosEventRecord(
            id: 1,
            category: ChaosOptions::CATEGORY_PRIMARY_REMOVE,
            status: 'waiting',
            targetPort: $plan->port,
            targetPrimaryPort: $plan->replicaRecipientPort,
            startedAt: 0.0,
            completedAt: null,
            summary: $plan->summary(),
            postcondition: $plan->postcondition(),
            primaryRemovePlan: $plan,
        );
    }

    private function membershipEvent(string $category, int $port): ChaosEventRecord
    {
        $addPlan = $category === ChaosOptions::CATEGORY_PRIMARY_ADD
            ? new PrimaryAddPlan(
                newPrimaryPort: $port,
                donorPort: 7002,
                donorNodeId: 'node-7002',
                ranges: [new SlotRange(16368, 16383)],
            )
            : null;

        return new ChaosEventRecord(
            id: count($this->chaosRuntime()->history) + 1,
            category: $category,
            status: 'completed',
            targetPort: $port,
            targetPrimaryPort: null,
            startedAt: 0.0,
            completedAt: 1.0,
            summary: sprintf('%s target=%d', $category, $port),
            postcondition: 'done',
            primaryAddPlan: $addPlan,
        );
    }

    private function invokeIsPrimaryAddSatisfied(ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        return $this->invokeChaosPostcondition('isPrimaryAddSatisfied', $event, $view);
    }

    private function invokeIsPrimaryRemoveSatisfied(ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        return $this->invokeChaosPostcondition('isPrimaryRemoveSatisfied', $event, $view);
    }

    private function invokeChaosPostcondition(string $method, ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $satisfied = new ReflectionClass($manager)->getMethod($method)->invoke($manager, $event, $view);
        self::assertIsBool($satisfied);

        return $satisfied;
    }

    private function invokePrimaryAddCandidate(
        ChaosClusterView $view,
        ChaosRuntimeState $runtime,
        bool $withPort = true,
    ): ?ChaosCandidateEvent {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $reflection->getProperty('primaryMembershipEligibility')->setValue($manager, new PrimaryMembershipEligibility());
        $reflection->getProperty('primaryAddPlanner')->setValue($manager, new PrimaryAddPlanner());

        // Port selection needs a live host, so the scoring seam takes the port
        // the outer builder would have picked.
        $candidate = $withPort
            ? $reflection->getMethod('buildPrimaryAddCandidateForPort')->invoke($manager, $view, $runtime, $this->chaosOptions(), 7010)
            : $reflection->getMethod('buildPrimaryAddCandidate')->invoke($manager, $view, $runtime, $this->chaosOptions());

        self::assertTrue($candidate === null || $candidate instanceof ChaosCandidateEvent);

        return $candidate;
    }

    /**
     * @return array<int, int> candidate score keyed by the primary chaos would remove
     */
    private function invokePrimaryRemoveCandidateScores(ChaosClusterView $view, ChaosRuntimeState $runtime): array
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $reflection->getProperty('primaryMembershipEligibility')->setValue($manager, new PrimaryMembershipEligibility());
        $reflection->getProperty('primaryRemovePlanner')->setValue($manager, new PrimaryRemovePlanner());

        $candidates = $reflection->getMethod('buildPrimaryRemoveCandidates')->invoke(
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

    /**
     * @param array<int, array{range: SlotRange|null, replicas: list<int>, orphan?: bool}> $shards
     */
    private function membershipView(array $shards, bool $clusterDown = false): ChaosClusterView
    {
        $nodeStateByPort = [];
        $primaryStateByPort = [];

        foreach ($shards as $primaryPort => $shard) {
            $ranges = $shard['range'] instanceof SlotRange ? [$shard['range']] : [];
            $nodeStateByPort[$primaryPort] = $this->chaosNode($primaryPort, 'primary', slotRanges: $ranges);

            foreach ($shard['replicas'] as $replicaPort) {
                $nodeStateByPort[$replicaPort] = $this->chaosNode($replicaPort, 'replica', primaryPort: $primaryPort);
            }

            if ($shard['orphan'] ?? false) {
                // A shard that only exists because a replica still points at it.
                continue;
            }

            $primaryStateByPort[$primaryPort] = new ChaosPrimaryState(
                port: $primaryPort,
                nodeId: sprintf('node-%d', $primaryPort),
                reachable: true,
                slotRanges: $ranges,
                replicaPorts: $shard['replicas'],
                healthyReplicaCount: count($shard['replicas']),
                syncingReplicaCount: 0,
                failedReplicaCount: 0,
            );
        }

        ksort($nodeStateByPort, SORT_NUMERIC);

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

    #[DataProvider('chaosCategoryStartupLines')]
    public function testChaosStartupLineShowsCategoriesAndNonNeutralWeights(
        ChaosCategorySelection $categories,
        string $expected,
    ): void {
        $manager = $this->newClusterManagerWithoutConstructor();
        $method = new ReflectionClass($manager)->getMethod('formatChaosCategoriesLine');

        self::assertSame($expected, $method->invoke($manager, $this->chaosOptions($categories)));
    }

    /**
     * @return iterable<string, array{ChaosCategorySelection, string}>
     */
    public static function chaosCategoryStartupLines(): iterable
    {
        yield 'defaults omit neutral weights' => [
            ChaosCategorySelection::fromCategories(ChaosOptions::DEFAULT_CATEGORIES),
            'Chaos categories: replica-kill,replica-restart,replica-add',
        ];
        yield 'weights are shown in --categories syntax' => [
            new ChaosCategorySelection([
                ChaosOptions::CATEGORY_REPLICA_KILL => 0.5,
                ChaosOptions::CATEGORY_SLOT_MIGRATION => 3.0,
                ChaosOptions::CATEGORY_REPLICA_ADD => 1.0,
            ]),
            'Chaos categories: replica-kill:0.5,slot-migration:3,replica-add',
        ];
    }
}
