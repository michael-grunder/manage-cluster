<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ManagedClusterSummaryRenderer;
use PHPUnit\Framework\TestCase;

final class ManagedClusterSummaryRendererTest extends TestCase
{
    public function testRenderShowsManagedClusterRows(): void
    {
        $renderer = new ManagedClusterSummaryRenderer();

        $rendered = $renderer->render([
            [
                'id' => 'cluster-a',
                'seed_port' => 7000,
                'port_range' => '7000-7008',
                'total_nodes' => 9,
                'listening_nodes' => 9,
                'replicas' => 2,
                'tls' => false,
            ],
            [
                'id' => 'cluster-b',
                'seed_port' => 8000,
                'port_range' => '8000-8008',
                'total_nodes' => 9,
                'listening_nodes' => 4,
                'replicas' => 2,
                'tls' => true,
            ],
        ], width: 120, watchMode: false, runningOnly: false);

        self::assertStringContainsString('Managed cluster summary', $rendered);
        self::assertStringContainsString('up      7000  7000-7008     9/9', $rendered);
        self::assertStringContainsString('partial 8000  8000-8008     4/9', $rendered);
        self::assertStringContainsString('yes  cluster-b', $rendered);
    }

    public function testRenderShowsEmptyRunningListMessage(): void
    {
        $renderer = new ManagedClusterSummaryRenderer();

        $rendered = $renderer->render([], width: 120, watchMode: false, runningOnly: true);

        self::assertStringContainsString('Managed clusters that appear to be running', $rendered);
        self::assertStringContainsString('No managed clusters appear to be running.', $rendered);
    }
}
