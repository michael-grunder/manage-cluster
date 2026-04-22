<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\ClusterStatusTuiRenderer;
use Mgrunder\CreateCluster\NodeLatencySnapshot;
use Mgrunder\CreateCluster\NodeLatencyState;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint\LengthConstraint;
use PhpTui\Tui\Layout\Constraint\MinConstraint;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ClusterStatusTuiRendererTest extends TestCase
{
    public function testBuildRootWidgetUsesConciseWatchTitles(): void
    {
        $renderer = new ClusterStatusTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleShards(), true, $this->sampleLatencies());

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

    public function testBuildRootWidgetCollapsesLoopbackHostsToPorts(): void
    {
        $renderer = new ClusterStatusTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleShards(), false, []);

        self::assertInstanceOf(TableWidget::class, $widget->widget);
        self::assertSame('7000', $widget->widget->rows[0]->cells[0]->content->lines[0]->spans[0]->content);
        self::assertSame('↳ 7005', $widget->widget->rows[1]->cells[0]->content->lines[0]->spans[0]->content);
    }

    public function testBuildRootWidgetUsesContentAwareColumnWidths(): void
    {
        $renderer = new ClusterStatusTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleShards(), true, $this->sampleLatencies());

        self::assertInstanceOf(TableWidget::class, $widget->widget);
        self::assertSame(1, $widget->widget->columnSpacing);
        self::assertCount(7, $widget->widget->widths);
        self::assertInstanceOf(LengthConstraint::class, $widget->widget->widths[0]);
        self::assertSame(6, $widget->widget->widths[0]->length);
        self::assertInstanceOf(LengthConstraint::class, $widget->widget->widths[1]);
        self::assertSame(9, $widget->widget->widths[1]->length);
        self::assertInstanceOf(LengthConstraint::class, $widget->widget->widths[2]);
        self::assertSame(6, $widget->widget->widths[2]->length);
        self::assertInstanceOf(LengthConstraint::class, $widget->widget->widths[3]);
        self::assertSame(6, $widget->widget->widths[3]->length);
        self::assertInstanceOf(LengthConstraint::class, $widget->widget->widths[4]);
        self::assertSame(7, $widget->widget->widths[4]->length);
        self::assertInstanceOf(LengthConstraint::class, $widget->widget->widths[5]);
        self::assertSame(8, $widget->widget->widths[5]->length);
        self::assertInstanceOf(MinConstraint::class, $widget->widget->widths[6]);
        self::assertSame(8, $widget->widget->widths[6]->min);
        self::assertSame('Latency', $widget->widget->header->cells[5]->content->lines[0]->spans[0]->content);
        self::assertSame('1.23 ms', $widget->widget->rows[0]->cells[5]->content->lines[0]->spans[0]->content);
        self::assertSame('timeout', $widget->widget->rows[1]->cells[5]->content->lines[0]->spans[0]->content);
        self::assertSame('pending', $widget->widget->rows[2]->cells[5]->content->lines[0]->spans[0]->content);
    }

    public function testBuildRootWidgetOmitsLatencyColumnOutsideWatchMode(): void
    {
        $renderer = new ClusterStatusTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleShards(), false, $this->sampleLatencies());

        self::assertInstanceOf(TableWidget::class, $widget->widget);
        self::assertCount(6, $widget->widget->widths);
        self::assertSame('Health', $widget->widget->header->cells[5]->content->lines[0]->spans[0]->content);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     * @param array<int, NodeLatencySnapshot> $latenciesByPort
     */
    private function invokeBuildRootWidget(
        ClusterStatusTuiRenderer $renderer,
        array $shards,
        bool $watchMode,
        array $latenciesByPort,
    ): object
    {
        $method = new ReflectionMethod($renderer, 'buildRootWidget');

        return $method->invoke($renderer, $shards, $watchMode, $latenciesByPort);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function invokeCalculateViewportHeight(ClusterStatusTuiRenderer $renderer, array $shards): int
    {
        $method = new ReflectionMethod($renderer, 'calculateViewportHeight');

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
                master: new ClusterNodeStatus('master-7000', '127.0.0.1', 7000, '', 'master', 100, 'online', 1024),
                replicas: [
                    new ClusterNodeStatus('replica-7005', '127.0.0.1', 7005, '', 'replica', 99, 'online', 1024),
                    new ClusterNodeStatus('replica-7008', '127.0.0.1', 7008, '', 'replica', 98, 'online', 1024),
                ],
            ),
        ];
    }

    /**
     * @return array<int, NodeLatencySnapshot>
     */
    private function sampleLatencies(): array
    {
        return [
            7000 => new NodeLatencySnapshot(NodeLatencyState::Ok, 1.234),
            7005 => new NodeLatencySnapshot(NodeLatencyState::Timeout),
            7008 => new NodeLatencySnapshot(NodeLatencyState::Pending),
        ];
    }
}
