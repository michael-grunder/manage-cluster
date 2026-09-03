<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ManagedClusterSummaryTuiRenderer;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint\LengthConstraint;
use PhpTui\Tui\Layout\Constraint\MinConstraint;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ManagedClusterSummaryTuiRendererTest extends TestCase
{
    public function testBuildRootWidgetUsesFullscreenWatchTitlesAndSelection(): void
    {
        $renderer = new ManagedClusterSummaryTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleClusters(), 1, false);

        self::assertCount(2, $widget->titles);
        self::assertSame(' Managed Cluster Summary [watch] ', $widget->titles[0]->title->spans[0]->content);
        self::assertSame(HorizontalAlignment::Left, $widget->titles[0]->horizontalAlignment);
        self::assertMatchesRegularExpression('/ \d{2}:\d{2}:\d{2} /', $widget->titles[1]->title->spans[0]->content);
        self::assertSame(HorizontalAlignment::Right, $widget->titles[1]->horizontalAlignment);

        $table = $this->tableWidget($widget);
        self::assertSame(1, $table->state->selected);
        self::assertSame('partial', $this->cellContent($this->tableRow($table, 1), 0));
        self::assertSame('8000', $this->cellContent($this->tableRow($table, 1), 1));
    }

    public function testNormalizeSelectedIndexSkipsDownClusters(): void
    {
        $renderer = new ManagedClusterSummaryTuiRenderer();

        $selectedIndex = $this->invokeNormalizeSelectedIndex($renderer, $this->sampleClusters(), null, null);

        self::assertSame(1, $selectedIndex);
    }

    public function testBuildRootWidgetUsesContentAwareColumnWidths(): void
    {
        $renderer = new ManagedClusterSummaryTuiRenderer();

        $widget = $this->invokeBuildRootWidget($renderer, $this->sampleClusters(), 1, false);
        $table = $this->tableWidget($widget);

        self::assertSame(1, $table->columnSpacing);
        self::assertCount(7, $table->widths);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[0]);
        self::assertSame(8, $table->widths[0]->length);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[1]);
        self::assertSame(6, $table->widths[1]->length);
        self::assertInstanceOf(LengthConstraint::class, $table->widths[2]);
        self::assertSame(9, $table->widths[2]->length);
        self::assertInstanceOf(MinConstraint::class, $table->widths[6]);
        self::assertSame(10, $table->widths[6]->min);
    }

    /**
     * @param list<array{
     *   id: string,
     *   seed_port: int,
     *   port_range: string,
     *   total_nodes: int,
     *   listening_nodes: int,
     *   replicas: int,
     *   tls: bool
     * }> $clusters
     */
    private function invokeBuildRootWidget(
        ManagedClusterSummaryTuiRenderer $renderer,
        array $clusters,
        ?int $selectedIndex,
        bool $runningOnly,
    ): BlockWidget {
        $method = new ReflectionMethod($renderer, 'buildRootWidget');

        $widget = $method->invoke($renderer, $clusters, $selectedIndex, $runningOnly);
        self::assertInstanceOf(BlockWidget::class, $widget);

        return $widget;
    }

    /**
     * @param list<array{
     *   id: string,
     *   seed_port: int,
     *   port_range: string,
     *   total_nodes: int,
     *   listening_nodes: int,
     *   replicas: int,
     *   tls: bool
     * }> $clusters
     */
    private function invokeNormalizeSelectedIndex(
        ManagedClusterSummaryTuiRenderer $renderer,
        array $clusters,
        ?int $selectedSeedPort,
        ?int $selectedIndex,
    ): int {
        $method = new ReflectionMethod($renderer, 'normalizeSelectedIndex');

        $normalized = $method->invoke($renderer, $clusters, $selectedSeedPort, $selectedIndex);
        self::assertIsInt($normalized);

        return $normalized;
    }

    private function tableWidget(BlockWidget $widget): TableWidget
    {
        self::assertInstanceOf(GridWidget::class, $widget->widget);
        $table = $widget->widget->widgets[2] ?? null;
        self::assertInstanceOf(TableWidget::class, $table);

        return $table;
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
     * @return list<array{
     *   id: string,
     *   seed_port: int,
     *   port_range: string,
     *   total_nodes: int,
     *   listening_nodes: int,
     *   replicas: int,
     *   tls: bool
     * }>
     */
    private function sampleClusters(): array
    {
        return [
            [
                'id' => 'cluster-down',
                'seed_port' => 7000,
                'port_range' => '7000-7008',
                'total_nodes' => 9,
                'listening_nodes' => 0,
                'replicas' => 2,
                'tls' => false,
            ],
            [
                'id' => 'cluster-partial',
                'seed_port' => 8000,
                'port_range' => '8000-8008',
                'total_nodes' => 9,
                'listening_nodes' => 4,
                'replicas' => 2,
                'tls' => true,
            ],
        ];
    }
}
