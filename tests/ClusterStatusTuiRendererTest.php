<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\ClusterStatusTuiRenderer;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ClusterStatusTuiRendererTest extends TestCase
{
    public function testBuildRootWidgetUsesConciseWatchTitles(): void
    {
        $renderer = new ClusterStatusTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleShards(), true);

        self::assertCount(2, $widget->titles);
        self::assertSame(' Cluster Status [watch] ', $widget->titles[0]->title->spans[0]->content);
        self::assertSame(HorizontalAlignment::Left, $widget->titles[0]->horizontalAlignment);
        self::assertMatchesRegularExpression('/ \d{2}:\d{2}:\d{2} /', $widget->titles[1]->title->spans[0]->content);
        self::assertSame(HorizontalAlignment::Right, $widget->titles[1]->horizontalAlignment);
        self::assertInstanceOf(TableWidget::class, $widget->widget);
    }

    public function testCalculateViewportHeightDoesNotReserveMetadataRows(): void
    {
        $renderer = new ClusterStatusTuiRenderer();

        $height = $this->invokeCalculateViewportHeight($renderer, $this->sampleShards());

        self::assertSame(9, $height);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function invokeBuildRootWidget(ClusterStatusTuiRenderer $renderer, array $shards, bool $watchMode): object
    {
        $method = new ReflectionMethod($renderer, 'buildRootWidget');
        $method->setAccessible(true);

        return $method->invoke($renderer, $shards, $watchMode);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function invokeCalculateViewportHeight(ClusterStatusTuiRenderer $renderer, array $shards): int
    {
        $method = new ReflectionMethod($renderer, 'calculateViewportHeight');
        $method->setAccessible(true);

        return $method->invoke($renderer, $shards);
    }

    /**
     * @return list<ClusterShardStatus>
     */
    private function sampleShards(): array
    {
        return [
            new ClusterShardStatus(
                slotStart: 0,
                slotEnd: 5460,
                master: new ClusterNodeStatus('master-7000', '127.0.0.1', 7000, '', 'master', 100, 'online'),
                replicas: [
                    new ClusterNodeStatus('replica-7005', '127.0.0.1', 7005, '', 'replica', 99, 'online'),
                    new ClusterNodeStatus('replica-7008', '127.0.0.1', 7008, '', 'replica', 98, 'online'),
                ],
            ),
        ];
    }
}
