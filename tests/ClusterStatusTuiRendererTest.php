<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ClusterNodeStatus;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\ClusterStatusTuiRenderer;
use Mgrunder\CreateCluster\NodeLatencySnapshot;
use Mgrunder\CreateCluster\NodeLatencyState;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
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
        $this->tableWidget($widget);
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

        $table = $this->tableWidget($widget);

        self::assertSame('7000', $this->cellContent($this->tableRow($table, 0), 0));
        self::assertSame('↳ 7005', $this->cellContent($this->tableRow($table, 1), 0));
    }

    public function testBuildRootWidgetUsesContentAwareColumnWidths(): void
    {
        $renderer = new ClusterStatusTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleShards(), true, $this->sampleLatencies());

        $table = $this->tableWidget($widget);

        self::assertSame(1, $table->columnSpacing);
        self::assertCount(7, $table->widths);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[0]);
        self::assertSame(6, $table->widths[0]->length);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[1]);
        self::assertSame(9, $table->widths[1]->length);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[2]);
        self::assertSame(6, $table->widths[2]->length);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[3]);
        self::assertSame(6, $table->widths[3]->length);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[4]);
        self::assertSame(7, $table->widths[4]->length);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[5]);
        self::assertSame(8, $table->widths[5]->length);
        self::assertInstanceOf(MinConstraint::class, $table->widths[6]);
        self::assertSame(8, $table->widths[6]->min);
        self::assertSame('Latency', $this->cellContent($this->tableHeader($table), 5));
        self::assertSame('1.23 ms', $this->cellContent($this->tableRow($table, 0), 5));
        self::assertSame('timeout', $this->cellContent($this->tableRow($table, 1), 5));
        self::assertSame('pending', $this->cellContent($this->tableRow($table, 2), 5));
    }

    public function testBuildRootWidgetOmitsLatencyColumnOutsideWatchMode(): void
    {
        $renderer = new ClusterStatusTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleShards(), false, $this->sampleLatencies());

        $table = $this->tableWidget($widget);

        self::assertCount(6, $table->widths);
        self::assertSame('Health', $this->cellContent($this->tableHeader($table), 5));
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
    ): BlockWidget
    {
        $method = new ReflectionMethod($renderer, 'buildRootWidget');

        $widget = $method->invoke($renderer, $shards, $watchMode, $latenciesByPort);
        self::assertInstanceOf(BlockWidget::class, $widget);

        return $widget;
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function invokeCalculateViewportHeight(ClusterStatusTuiRenderer $renderer, array $shards): int
    {
        $method = new ReflectionMethod($renderer, 'calculateViewportHeight');

        $height = $method->invoke($renderer, $shards);
        self::assertIsInt($height);

        return $height;
    }

    private function tableWidget(BlockWidget $widget): TableWidget
    {
        self::assertInstanceOf(TableWidget::class, $widget->widget);

        return $widget->widget;
    }

    private function tableHeader(TableWidget $table): TableRow
    {
        self::assertInstanceOf(TableRow::class, $table->header);

        return $table->header;
    }

    private function tableRow(TableWidget $table, int $index): TableRow
    {
        $row = $table->rows[$index] ?? null;
        self::assertInstanceOf(TableRow::class, $row);

        return $row;
    }

    private function cellContent(TableRow $row, int $index): string
    {
        $cell = $row->cells[$index] ?? null;
        self::assertNotNull($cell);
        $line = $cell->content->lines[0] ?? null;
        self::assertNotNull($line);
        $span = $line->spans[0] ?? null;
        self::assertNotNull($span);

        return $span->content;
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
