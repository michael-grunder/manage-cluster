<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosEventRecord;
use Mgrunder\CreateCluster\ChaosNodeState;
use Mgrunder\CreateCluster\ChaosOptions;
use Mgrunder\CreateCluster\ChaosPrimaryState;
use Mgrunder\CreateCluster\ChaosRuntimeState;
use Mgrunder\CreateCluster\ChaosWatchLogLevel;
use Mgrunder\CreateCluster\ChaosWatchState;
use Mgrunder\CreateCluster\ChaosWatchTuiRenderer;
use Mgrunder\CreateCluster\SlotMigrationStrategy;
use Mgrunder\CreateCluster\SlotRange;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Layout\Constraint\LengthConstraint;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\HorizontalAlignment;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ChaosWatchTuiRendererTest extends TestCase
{
    public function testRootWidgetTitlesNameTheSeedAndClock(): void
    {
        $widget = $this->buildRootWidget($this->state(), 30);

        self::assertCount(2, $widget->titles);
        self::assertSame(' Chaos [watch] seed 7000 ', $widget->titles[0]->title->spans[0]->content);
        self::assertSame(HorizontalAlignment::Left, $widget->titles[0]->horizontalAlignment);
        self::assertMatchesRegularExpression('/ \d{2}:\d{2}:\d{2} /', $widget->titles[1]->title->spans[0]->content);
        self::assertSame(HorizontalAlignment::Right, $widget->titles[1]->horizontalAlignment);
    }

    public function testTopologyRowsListEachPrimaryFollowedByItsReplicas(): void
    {
        $table = $this->topologyTable($this->buildRootWidget($this->state(), 30));

        self::assertSame(['7000', 'aaaaaaaa', 'primary', '0-5460', '5461', '1 ok, 0 sync, 0 failed'], $this->cells($table, 0));
        self::assertSame(['↳ 7003', 'dddddddd', 'replica', '-', '-', 'ok'], $this->cells($table, 1));
        self::assertSame('7001', $this->cells($table, 2)[0]);
        self::assertSame('no replica (0 ok, 1 sync, 0 failed)', $this->cells($table, 2)[5]);
        self::assertSame(['↳ 7004', 'eeeeeeee', 'replica', '-', '-', 'syncing'], $this->cells($table, 3));
    }

    public function testTopologyPaneReportsPendingBeforeTheFirstDiscovery(): void
    {
        $runtime = $this->runtime();
        $state = new ChaosWatchState($runtime, $this->options());

        $pane = $this->topologyPane($this->buildRootWidget($state, 30));

        self::assertSame(' Topology (pending) ', $pane->titles[0]->title->spans[0]->content);
        self::assertSame('Waiting for cluster topology.', $this->cells($this->tableWidget($pane), 0)[0]);
    }

    public function testTopologyPaneIsSizedToItsContentSoTheLogTakesTheRest(): void
    {
        $panes = $this->panes($this->buildRootWidget($this->state(), 30));

        // Border, header, two primaries and their two replicas.
        self::assertInstanceOf(LengthConstraint::class, $panes->constraints[0]);
        self::assertSame(7, $panes->constraints[0]->length);
    }

    public function testShortViewportGivesTheEventLogItsMinimumHeight(): void
    {
        $panes = $this->panes($this->buildRootWidget($this->state(), 12));

        self::assertInstanceOf(LengthConstraint::class, $panes->constraints[0]);
        self::assertSame(3, $panes->constraints[0]->length);
    }

    public function testEventLogRendersTheNewestEntriesThatFit(): void
    {
        $state = $this->state();
        foreach (range(1, 20) as $index) {
            $state->log(ChaosWatchLogLevel::Waiting, sprintf('poll %d', $index));
        }

        // 30 rows less the root chrome leaves a 26 line body; the topology
        // pane takes seven of them, so 17 log lines fit inside the borders.
        $items = $this->eventList($this->buildRootWidget($state, 30))->items;

        self::assertCount(17, $items);
        self::assertStringEndsWith('poll 4', $this->lineText($items[0]->content->lines[0]));
        self::assertStringEndsWith('poll 20', $this->lineText($items[16]->content->lines[0]));
        self::assertStringContainsString('wait ', $this->lineText($items[16]->content->lines[0]));
    }

    public function testEventLogInvitesTheFirstEventWhenEmpty(): void
    {
        $items = $this->eventList($this->buildRootWidget($this->state(), 30))->items;

        self::assertCount(1, $items);
        self::assertSame('Waiting for the first chaos event.', $this->lineText($items[0]->content->lines[0]));
    }

    public function testEventPaneTitleTracksTheInflightEvent(): void
    {
        $runtime = $this->runtime();
        $state = new ChaosWatchState($runtime, $this->options());

        self::assertSame(' idle ', $this->eventPane($this->buildRootWidget($state, 30))->titles[1]->title->spans[0]->content);

        $runtime->inflightEvent = new ChaosEventRecord(
            id: 9,
            category: ChaosOptions::CATEGORY_REPLICA_KILL,
            status: 'waiting',
            targetPort: 7004,
            targetPrimaryPort: 7001,
            startedAt: 100.0,
            completedAt: null,
            summary: 'replica-kill target=7004 primary=7001',
            postcondition: 'replica 7004 is gone',
        );

        self::assertSame(' event#9 waiting ', $this->eventPane($this->buildRootWidget($state, 30))->titles[1]->title->spans[0]->content);
    }

    public function testSummaryLineReportsProgressAndClusterHealth(): void
    {
        $runtime = $this->runtime();
        $state = new ChaosWatchState($runtime, $this->options());
        $state->updateView($this->view());
        $runtime->rememberHistory(new ChaosEventRecord(
            id: 1,
            category: ChaosOptions::CATEGORY_REPLICA_KILL,
            status: 'failed',
            targetPort: 7004,
            targetPrimaryPort: 7001,
            startedAt: 100.0,
            completedAt: 101.0,
            summary: 'replica-kill target=7004 primary=7001',
            postcondition: 'replica 7004 is gone',
        ));

        $summary = (string) $this->summaryLine($this->buildRootWidget($state, 30));

        self::assertStringContainsString('events 0 done/20, 1 failed', $summary);
        self::assertStringContainsString('cluster degraded (7001)', $summary);
        self::assertStringContainsString('categories replica-kill,slot-migration', $summary);
    }

    public function testQuitKeysAskTheRunToStop(): void
    {
        $renderer = new ChaosWatchTuiRenderer();
        $state = $this->state();

        self::assertTrue($this->handleCharKey($renderer, $state, CharKeyEvent::new('q')));
        self::assertTrue($state->quitRequested());

        $ctrlC = $this->state();
        self::assertTrue($this->handleCharKey($renderer, $ctrlC, CharKeyEvent::new('c', KeyModifiers::CONTROL)));
        self::assertTrue($ctrlC->quitRequested());

        $plainC = $this->state();
        self::assertFalse($this->handleCharKey($renderer, $plainC, CharKeyEvent::new('c')));
        self::assertFalse($plainC->quitRequested());

        $escape = $this->state();
        self::assertTrue($this->handleCodedKey($renderer, $escape, KeyCode::Esc));
        self::assertTrue($escape->quitRequested());
    }

    public function testScrollKeysMoveThroughTheEventLog(): void
    {
        $renderer = new ChaosWatchTuiRenderer();
        $state = $this->state();
        foreach (range(1, 40) as $index) {
            $state->log(ChaosWatchLogLevel::Waiting, sprintf('poll %d', $index));
        }

        self::assertTrue($this->handleCharKey($renderer, $state, CharKeyEvent::new('k')));
        self::assertSame(1, $state->scrollBack());

        self::assertTrue($this->handleCodedKey($renderer, $state, KeyCode::PageUp));
        self::assertSame(11, $state->scrollBack());

        self::assertTrue($this->handleCodedKey($renderer, $state, KeyCode::PageDown));
        self::assertSame(1, $state->scrollBack());

        self::assertTrue($this->handleCodedKey($renderer, $state, KeyCode::Up));
        self::assertSame(2, $state->scrollBack());

        self::assertTrue($this->handleCharKey($renderer, $state, CharKeyEvent::new('f')));
        self::assertTrue($state->followingLatest());

        self::assertFalse($this->handleCharKey($renderer, $state, CharKeyEvent::new('z')));
        self::assertFalse($this->handleCodedKey($renderer, $state, KeyCode::Tab));
    }

    private function buildRootWidget(ChaosWatchState $state, int $viewportHeight): BlockWidget
    {
        $method = new ReflectionMethod(ChaosWatchTuiRenderer::class, 'buildRootWidget');
        $widget = $method->invoke(new ChaosWatchTuiRenderer(), $state, $viewportHeight);
        self::assertInstanceOf(BlockWidget::class, $widget);

        return $widget;
    }

    private function handleCharKey(ChaosWatchTuiRenderer $renderer, ChaosWatchState $state, CharKeyEvent $event): bool
    {
        $method = new ReflectionMethod(ChaosWatchTuiRenderer::class, 'handleCharKey');

        return (bool) $method->invoke($renderer, $state, $event);
    }

    private function handleCodedKey(ChaosWatchTuiRenderer $renderer, ChaosWatchState $state, KeyCode $code): bool
    {
        $method = new ReflectionMethod(ChaosWatchTuiRenderer::class, 'handleCodedKey');

        return (bool) $method->invoke($renderer, $state, $code);
    }

    private function body(BlockWidget $root): GridWidget
    {
        $body = $root->widget;
        self::assertInstanceOf(GridWidget::class, $body);

        return $body;
    }

    private function summaryLine(BlockWidget $root): string
    {
        $summary = $this->body($root)->widgets[0];
        self::assertInstanceOf(ParagraphWidget::class, $summary);

        return implode('', array_map($this->lineText(...), $summary->text->lines));
    }

    /**
     * Span stringification is a debug format, so read the rendered content.
     */
    private function lineText(Line $line): string
    {
        return implode('', array_map(static fn (Span $span): string => $span->content, $line->spans));
    }

    private function panes(BlockWidget $root): GridWidget
    {
        $panes = $this->body($root)->widgets[1];
        self::assertInstanceOf(GridWidget::class, $panes);

        return $panes;
    }

    private function topologyPane(BlockWidget $root): BlockWidget
    {
        $pane = $this->panes($root)->widgets[0];
        self::assertInstanceOf(BlockWidget::class, $pane);

        return $pane;
    }

    private function eventPane(BlockWidget $root): BlockWidget
    {
        $pane = $this->panes($root)->widgets[1];
        self::assertInstanceOf(BlockWidget::class, $pane);

        return $pane;
    }

    private function topologyTable(BlockWidget $root): TableWidget
    {
        return $this->tableWidget($this->topologyPane($root));
    }

    private function tableWidget(BlockWidget $pane): TableWidget
    {
        $table = $pane->widget;
        self::assertInstanceOf(TableWidget::class, $table);

        return $table;
    }

    private function eventList(BlockWidget $root): ListWidget
    {
        $list = $this->eventPane($root)->widget;
        self::assertInstanceOf(ListWidget::class, $list);

        return $list;
    }

    /**
     * @return list<string>
     */
    private function cells(TableWidget $table, int $rowIndex): array
    {
        $row = $table->rows[$rowIndex];
        self::assertInstanceOf(TableRow::class, $row);

        return array_map(
            fn (TableCell $cell): string => implode('', array_map($this->lineText(...), $cell->content->lines)),
            $row->cells,
        );
    }

    private function state(): ChaosWatchState
    {
        $state = new ChaosWatchState($this->runtime(), $this->options());
        $state->updateView($this->view());

        return $state;
    }

    private function runtime(): ChaosRuntimeState
    {
        return new ChaosRuntimeState('cluster-1', 7000, microtime(true), ['replica-kill', 'slot-migration']);
    }

    private function options(): ChaosOptions
    {
        return new ChaosOptions(
            categories: ['replica-kill', 'slot-migration'],
            intervalSeconds: 5,
            maxEvents: 20,
            maxFailures: 3,
            dryRun: false,
            watch: true,
            seed: null,
            waitTimeoutSeconds: 60,
            cooldownSeconds: 2,
            allowSlotMigration: true,
            allowPrimaryFailover: false,
            allowReplicaReparent: false,
            allowPrimaryAdd: false,
            allowPrimaryRemove: false,
            unsafe: false,
            slotMigrationStrategy: SlotMigrationStrategy::Balanced,
        );
    }

    private function view(): ChaosClusterView
    {
        $nodes = [
            7000 => $this->node(7000, 'primary', null, [new SlotRange(0, 5460)], str_repeat('a', 40)),
            7001 => $this->node(7001, 'primary', null, [new SlotRange(5461, 16383)], str_repeat('b', 40)),
            7003 => $this->node(7003, 'replica', 7000, [], str_repeat('d', 40)),
            7004 => $this->node(7004, 'replica', 7001, [], str_repeat('e', 40), syncing: true),
        ];

        return new ChaosClusterView(
            clusterId: 'cluster-1',
            seedPort: 7000,
            topologyHash: 'hash',
            clusterDown: false,
            broadlyHealthy: true,
            nodeStateByPort: $nodes,
            primaryStateByPort: [
                7000 => new ChaosPrimaryState(7000, str_repeat('a', 40), true, [new SlotRange(0, 5460)], [7003], 1, 0, 0),
                7001 => new ChaosPrimaryState(7001, str_repeat('b', 40), true, [new SlotRange(5461, 16383)], [7004], 0, 1, 0),
            ],
            replicaStateByPort: [7003 => $nodes[7003], 7004 => $nodes[7004]],
            degradedPrimaryPorts: [7001],
        );
    }

    /**
     * @param list<SlotRange> $slotRanges
     */
    private function node(
        int $port,
        string $role,
        ?int $primaryPort,
        array $slotRanges,
        string $nodeId,
        bool $syncing = false,
    ): ChaosNodeState {
        return new ChaosNodeState(
            port: $port,
            nodeId: $nodeId,
            role: $role,
            primaryPort: $primaryPort,
            knownByCluster: true,
            reachable: true,
            isFailed: false,
            isHandshake: false,
            isLoading: false,
            isSyncing: $syncing,
            linkStatus: 'up',
            slotRanges: $slotRanges,
            pid: 1000 + $port,
            managed: true,
            health: $syncing ? 'syncing' : 'ok',
            replicationOffset: 4211,
        );
    }
}
