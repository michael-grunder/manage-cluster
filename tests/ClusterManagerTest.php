<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterManager;
use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\PortRangeFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ClusterManagerTest extends TestCase
{
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
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveReplicaTarget');

        $replica = $method->invoke($manager, $this->clusterShardsFixture(), 7002, false);

        self::assertInstanceOf(ClusterNodeStatus::class, $replica);
        self::assertSame(7002, $replica->port);
    }

    public function testResolveReplicaTargetReturnsReplicaWhenPrimaryMatches(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveReplicaTarget');

        $replica = $method->invoke($manager, $this->clusterShardsFixture(), 7002, false, 7000);

        self::assertInstanceOf(ClusterNodeStatus::class, $replica);
        self::assertSame(7002, $replica->port);
    }

    public function testResolveReplicaTargetRejectsReplicaOnDifferentPrimary(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveReplicaTarget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(<<<'MESSAGE'
Replica 7004 belongs to primary 7001, not primary 7000.
Valid replicas by primary:
  7000: 7002 (fail), 7003 (online)
MESSAGE);

        $method->invoke($manager, $this->clusterShardsFixture(), 7004, false, 7000);
    }

    public function testResolveReplicaTargetRejectsPrimaryWithTopologyMessage(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveReplicaTarget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(<<<'MESSAGE'
Port 7000 is a primary, not a replica.
Valid replicas by primary:
  7000: 7002 (fail), 7003 (online)
  7001: 7004 (online)
MESSAGE);

        $method->invoke($manager, $this->clusterShardsFixture(), 7000, false);
    }

    public function testResolveReplicaTargetRejectsHealthyReplicaForRestart(): void
    {
        $manager = $this->newClusterManagerWithoutConstructor();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('resolveReplicaTarget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(<<<'MESSAGE'
Replica 7003 belongs to primary 7000 but is not in fail state.
Restartable failed replicas by primary:
  7000: 7002 (fail)
  7001: none
MESSAGE);

        $method->invoke($manager, $this->clusterShardsFixture(), 7003, true);
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

    private function node(int $port, string $role, string $health): ClusterNodeStatus
    {
        return new ClusterNodeStatus(
            id: str_pad((string) $port, 40, '0'),
            ip: '127.0.0.1',
            port: $port,
            endpoint: '',
            role: $role,
            replicationOffset: 0,
            health: $health,
        );
    }
}
