<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterNodeStatus;
use PHPUnit\Framework\TestCase;

final class ClusterNodeStatusTest extends TestCase
{
    public function testDisplayAddressCanCollapseLoopbackHostsToPortOnly(): void
    {
        $node = new ClusterNodeStatus('node-7000', '127.0.0.1', 7000, '', 'master', 100, 'online');

        self::assertSame('7000', $node->displayAddress(true));
    }

    public function testIsLoopbackHostAcceptsIpv4Ipv6AndLocalhostSpellings(): void
    {
        $nodes = [
            new ClusterNodeStatus('node-1', '127.0.0.1', 7000, '', 'master', 100, 'online'),
            new ClusterNodeStatus('node-2', '::1', 7001, '', 'master', 100, 'online'),
            new ClusterNodeStatus('node-3', '', 7002, 'localhost', 'master', 100, 'online'),
            new ClusterNodeStatus('node-4', '', 7003, '[0:0:0:0:0:0:0:1]', 'master', 100, 'online'),
        ];

        foreach ($nodes as $node) {
            self::assertTrue($node->isLoopbackHost());
        }
    }

    public function testIsLoopbackHostRejectsNonLoopbackHosts(): void
    {
        $node = new ClusterNodeStatus('node-7000', '10.0.0.8', 7000, '', 'master', 100, 'online');

        self::assertFalse($node->isLoopbackHost());
    }
}
