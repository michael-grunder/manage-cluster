<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterNodeAddressFormatter;
use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use PHPUnit\Framework\TestCase;

final class ClusterNodeAddressFormatterTest extends TestCase
{
    public function testShouldCollapseHostsWhenEveryNodeUsesLoopback(): void
    {
        $shards = [
            new ClusterShardStatus(
                slotStart: 0,
                slotEnd: 100,
                master: new ClusterNodeStatus('master', '127.0.0.1', 7000, '', 'master', 100, 'online'),
                replicas: [
                    new ClusterNodeStatus('replica', '::1', 7001, '', 'replica', 99, 'online'),
                    new ClusterNodeStatus('replica-2', '', 7002, 'localhost', 'replica', 98, 'online'),
                ],
            ),
        ];

        self::assertTrue(ClusterNodeAddressFormatter::shouldCollapseHosts($shards));
    }

    public function testShouldNotCollapseHostsWhenAnyNodeIsRemote(): void
    {
        $shards = [
            new ClusterShardStatus(
                slotStart: 0,
                slotEnd: 100,
                master: new ClusterNodeStatus('master', '127.0.0.1', 7000, '', 'master', 100, 'online'),
                replicas: [
                    new ClusterNodeStatus('replica', '10.0.0.8', 7001, '', 'replica', 99, 'online'),
                ],
            ),
        ];

        self::assertFalse(ClusterNodeAddressFormatter::shouldCollapseHosts($shards));
    }
}
