<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterManager;
use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
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
                slotStart: 0,
                slotEnd: 16383,
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

    /**
     * @return list<ClusterShardStatus>
     */
    private function clusterShardsFixture(): array
    {
        return [
            new ClusterShardStatus(
                slotStart: 0,
                slotEnd: 8191,
                master: $this->node(7000, 'master', 'online'),
                replicas: [
                    $this->node(7002, 'replica', 'fail'),
                    $this->node(7003, 'replica', 'online'),
                ],
            ),
            new ClusterShardStatus(
                slotStart: 8192,
                slotEnd: 16383,
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
