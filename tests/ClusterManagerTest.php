<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterManager;
use Mgrunder\CreateCluster\PortRangeFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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

    private function newClusterManagerWithoutConstructor(): ClusterManager
    {
        $reflection = new ReflectionClass(ClusterManager::class);

        /** @var ClusterManager $manager */
        $manager = $reflection->newInstanceWithoutConstructor();

        return $manager;
    }
}
