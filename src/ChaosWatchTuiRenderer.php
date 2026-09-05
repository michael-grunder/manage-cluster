<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableCell;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use RuntimeException;
use Throwable;

/**
 * Fullscreen `chaos --watch` view: live cluster topology on top, the sequence
 * of chaos events underneath. Unlike the other interactive renderers the chaos
 * loop owns the clock, so this class exposes a frame at a time plus a pump
 * that keeps input responsive while the loop waits.
 */
final class ChaosWatchTuiRenderer
{
    private const int COLUMN_SPACING = 1;
    private const int NODE_ID_LENGTH = 8;
    private const string REPLICA_PREFIX = '↳ ';
    private const int PAGE_SCROLL_LINES = 10;
    private const int ROOT_CHROME_LINES = 4;
    private const float FRAME_INTERVAL_SECONDS = 0.25;
    private const int POLL_INTERVAL_MICROSECONDS = 25_000;

    /**
     * Rows a pane spends on its border and header before any content.
     */
    private const int TOPOLOGY_PANE_CHROME_LINES = 3;
    private const int LOG_PANE_CHROME_LINES = 2;
    private const int MIN_LOG_PANE_LINES = 5;

    /**
     * Fallback viewport used when the terminal size cannot be read.
     */
    private const int FALLBACK_VIEWPORT_HEIGHT = 24;

    private ?Terminal $terminal = null;
    private ?Display $display = null;

    public function supportsInteractiveWatch(): bool
    {
        if (!function_exists('stream_isatty')) {
            return false;
        }

        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    public function start(): void
    {
        if (!$this->supportsInteractiveWatch()) {
            throw new RuntimeException('Interactive chaos watch requires a TTY on stdin/stdout.');
        }

        try {
            $terminal = Terminal::new();
            $display = DisplayBuilder::default(PhpTermBackend::new($terminal))
                ->fullscreen()
                ->build();

            $terminal->enableRawMode();
            $display->clear();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Interactive chaos watch failed to start: %s', $exception->getMessage()),
                previous: $exception,
            );
        }

        $this->terminal = $terminal;
        $this->display = $display;
    }

    /**
     * Restore the terminal. Teardown runs on the error path too, so every
     * step is best effort and never masks the failure that triggered it.
     */
    public function stop(): void
    {
        $display = $this->display;
        $terminal = $this->terminal;
        $this->display = null;
        $this->terminal = null;

        if ($display instanceof Display) {
            try {
                $display->clear();
                $display->flush();
            } catch (Throwable) {
            }
        }

        if ($terminal instanceof Terminal) {
            try {
                $terminal->disableRawMode();
            } catch (Throwable) {
            }
        }
    }

    public function render(ChaosWatchState $state): void
    {
        $display = $this->display;
        if (!$display instanceof Display) {
            return;
        }

        try {
            $display->draw($this->buildRootWidget($state, $this->viewportHeight($display)));
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Interactive chaos watch failed: %s', $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * Hold for $seconds while keeping keys and the clock alive. Returns early
     * once the viewer asks to quit so the chaos loop can wind down.
     */
    public function pump(ChaosWatchState $state, float $seconds): void
    {
        $deadline = microtime(true) + max(0.0, $seconds);
        $nextFrameAt = 0.0;

        while (true) {
            $now = microtime(true);
            if ($this->drainInput($state) || $now >= $nextFrameAt) {
                $this->render($state);
                $nextFrameAt = $now + self::FRAME_INTERVAL_SECONDS;
            }

            if ($state->quitRequested() || microtime(true) >= $deadline) {
                return;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * @return bool whether anything happened that needs a redraw
     */
    private function drainInput(ChaosWatchState $state): bool
    {
        $terminal = $this->terminal;
        if (!$terminal instanceof Terminal) {
            return false;
        }

        $dirty = false;
        while (null !== $event = $terminal->events()->next()) {
            if ($event instanceof TerminalResizedEvent) {
                $dirty = true;
                continue;
            }

            if ($event instanceof CodedKeyEvent) {
                $dirty = $this->handleCodedKey($state, $event->code) || $dirty;
                continue;
            }

            if ($event instanceof CharKeyEvent) {
                $dirty = $this->handleCharKey($state, $event) || $dirty;
            }
        }

        return $dirty;
    }

    private function handleCodedKey(ChaosWatchState $state, KeyCode $code): bool
    {
        switch ($code) {
            case KeyCode::Esc:
                $state->requestQuit();

                return true;
            case KeyCode::Up:
                $state->scrollOlder(1);

                return true;
            case KeyCode::Down:
                $state->scrollNewer(1);

                return true;
            case KeyCode::PageUp:
                $state->scrollOlder(self::PAGE_SCROLL_LINES);

                return true;
            case KeyCode::PageDown:
                $state->scrollNewer(self::PAGE_SCROLL_LINES);

                return true;
            case KeyCode::Home:
                $state->scrollToOldest();

                return true;
            case KeyCode::End:
                $state->scrollToLatest();

                return true;
            default:
                return false;
        }
    }

    private function handleCharKey(ChaosWatchState $state, CharKeyEvent $event): bool
    {
        $char = strtolower($event->char);

        // Raw mode swallows the interrupt, so Ctrl-C has to be handled here or
        // the only way out would be killing the process from another shell.
        if ($char === 'c' && ($event->modifiers & KeyModifiers::CONTROL) !== 0) {
            $state->requestQuit();

            return true;
        }

        switch ($char) {
            case 'q':
                $state->requestQuit();

                return true;
            case 'k':
                $state->scrollOlder(1);

                return true;
            case 'j':
                $state->scrollNewer(1);

                return true;
            case 'f':
                $state->scrollToLatest();

                return true;
            default:
                return false;
        }
    }

    private function viewportHeight(Display $display): int
    {
        try {
            return max(1, $display->viewportArea()->height);
        } catch (Throwable) {
            return self::FALLBACK_VIEWPORT_HEIGHT;
        }
    }

    private function buildRootWidget(ChaosWatchState $state, int $viewportHeight): BlockWidget
    {
        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(1),
                Constraint::min(1),
                Constraint::length(1),
            )
            ->widgets(
                $this->buildSummaryLine($state),
                $this->buildPanes($state, max(0, $viewportHeight - self::ROOT_CHROME_LINES)),
                $this->buildControlsLine($state),
            );

        $title = Title::fromString(sprintf(' Chaos [watch] seed %d ', $state->seedPort()));
        $clock = Title::fromString(sprintf(' %s ', date('H:i:s')))
            ->horizontalAlignment(HorizontalAlignment::Right);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Plain)
            ->padding(Padding::fromScalars(left: 1, right: 1, top: 0, bottom: 0))
            ->titleStyle(Style::default()->addModifier(Modifier::BOLD))
            ->titles($title, $clock)
            ->widget($body);
    }

    /**
     * Split the body so the topology pane is only as tall as it needs to be
     * and the event log keeps the rest. The log has to know its exact height:
     * the list widget clips whatever runs past the bottom, so handing it more
     * entries than fit would hide the newest events rather than the oldest.
     */
    private function buildPanes(ChaosWatchState $state, int $bodyHeight): GridWidget
    {
        $topologyHeight = $this->topologyPaneHeight($state->view(), $bodyHeight);
        $logHeight = max(0, $bodyHeight - $topologyHeight);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length($topologyHeight),
                Constraint::min(1),
            )
            ->widgets(
                $this->buildTopologyPane($state),
                $this->buildEventPane($state, max(0, $logHeight - self::LOG_PANE_CHROME_LINES)),
            );
    }

    private function topologyPaneHeight(?ChaosClusterView $view, int $bodyHeight): int
    {
        $contentHeight = self::TOPOLOGY_PANE_CHROME_LINES + $this->topologyRowCount($view);
        $available = max(self::TOPOLOGY_PANE_CHROME_LINES, $bodyHeight - self::MIN_LOG_PANE_LINES);

        return max(0, min($contentHeight, $available));
    }

    private function topologyRowCount(?ChaosClusterView $view): int
    {
        if (!$view instanceof ChaosClusterView || $view->primaryStateByPort === []) {
            return 1;
        }

        $rows = count($view->primaryStateByPort);
        foreach ($view->primaryStateByPort as $primary) {
            $rows += count($primary->replicaPorts);
        }

        return $rows;
    }

    private function buildSummaryLine(ChaosWatchState $state): ParagraphWidget
    {
        $options = $state->options();
        $view = $state->view();

        $spans = [
            Span::styled('up ', Style::default()->fg(AnsiColor::DarkGray)),
            Span::fromString($this->formatDuration($state->elapsedSeconds(microtime(true)))),
            Span::styled('  events ', Style::default()->fg(AnsiColor::DarkGray)),
            Span::fromString(sprintf(
                '%d done%s',
                $state->completedEventCount(),
                $options->maxEvents === null ? '' : sprintf('/%d', $options->maxEvents),
            )),
        ];

        if ($state->failedEventCount() > 0) {
            $spans[] = Span::fromString(', ');
            $spans[] = Span::styled(
                sprintf('%d failed', $state->failedEventCount()),
                Style::default()->fg(AnsiColor::LightRed),
            );
        }

        $spans[] = Span::styled('  cluster ', Style::default()->fg(AnsiColor::DarkGray));
        $spans[] = $view instanceof ChaosClusterView
            ? Span::styled(...$this->describeClusterHealth($view))
            : Span::styled('discovering', Style::default()->fg(AnsiColor::DarkGray));

        if ($options->dryRun) {
            $spans[] = Span::styled('  dry-run', Style::default()->fg(AnsiColor::LightYellow));
        }

        $spans[] = Span::styled('  categories ', Style::default()->fg(AnsiColor::DarkGray));
        $spans[] = Span::fromString(implode(',', $options->categories));

        return ParagraphWidget::fromLines(Line::fromSpans(...$spans));
    }

    /**
     * @return array{string, Style}
     */
    private function describeClusterHealth(ChaosClusterView $view): array
    {
        if ($view->clusterDown) {
            return ['down', Style::default()->fg(AnsiColor::LightRed)->addModifier(Modifier::BOLD)];
        }

        if ($view->degradedPrimaryPorts !== []) {
            return [
                sprintf('degraded (%s)', implode(',', $view->degradedPrimaryPorts)),
                Style::default()->fg(AnsiColor::LightYellow),
            ];
        }

        if (!$view->broadlyHealthy) {
            return ['settling', Style::default()->fg(AnsiColor::LightYellow)];
        }

        return ['healthy', Style::default()->fg(AnsiColor::LightGreen)];
    }

    private function buildControlsLine(ChaosWatchState $state): ParagraphWidget
    {
        $followSpan = $state->followingLatest()
            ? Span::styled('following', Style::default()->fg(AnsiColor::LightGreen))
            : Span::styled(
                sprintf('scrolled back %d', $state->scrollBack()),
                Style::default()->fg(AnsiColor::LightYellow),
            );

        return ParagraphWidget::fromLines(Line::fromSpans(
            Span::styled(
                'j/k or Up/Down scroll, PgUp/PgDn page, f follow, q quits  ',
                Style::default()->fg(AnsiColor::DarkGray),
            ),
            $followSpan,
        ));
    }

    private function buildTopologyPane(ChaosWatchState $state): BlockWidget
    {
        $view = $state->view();
        $inflightPort = $state->inflightEvent()?->targetPort;

        $table = TableWidget::default()
            ->header($this->buildTopologyHeader())
            ->rows(...$this->buildTopologyRows($view, $inflightPort))
            ->widths(
                Constraint::length($this->maxNodeColumnWidth($view)),
                Constraint::length(self::NODE_ID_LENGTH + 1),
                Constraint::length(8),
                Constraint::length($this->maxSlotColumnWidth($view)),
                Constraint::length(9),
                Constraint::min(12),
            );
        $table->columnSpacing = self::COLUMN_SPACING;

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Plain)
            ->padding(Padding::fromScalars(left: 1, right: 1, top: 0, bottom: 0))
            ->titles(Title::fromString(sprintf(' Topology (%s) ', $this->describeTopologySize($view))))
            ->widget($table);
    }

    private function describeTopologySize(?ChaosClusterView $view): string
    {
        if (!$view instanceof ChaosClusterView) {
            return 'pending';
        }

        $replicaCount = count($view->replicaStateByPort);

        return sprintf(
            '%d primaries, %d %s',
            count($view->primaryStateByPort),
            $replicaCount,
            $replicaCount === 1 ? 'replica' : 'replicas',
        );
    }

    private function buildTopologyHeader(): TableRow
    {
        $header = TableRow::fromStrings('Node', 'ID', 'Role', 'Slots', 'Slot#', 'State');
        $header->style = Style::default()->addModifier(Modifier::BOLD);

        return $header;
    }

    /**
     * @return list<TableRow>
     */
    private function buildTopologyRows(?ChaosClusterView $view, ?int $inflightPort): array
    {
        if (!$view instanceof ChaosClusterView || $view->primaryStateByPort === []) {
            return [TableRow::fromStrings('Waiting for cluster topology.', '-', '-', '-', '-', '-')];
        }

        $primaryPorts = array_keys($view->primaryStateByPort);
        sort($primaryPorts);

        $rows = [];
        foreach ($primaryPorts as $primaryPort) {
            $primary = $view->primaryStateByPort[$primaryPort];
            $rows[] = $this->buildPrimaryRow($view, $primary, $inflightPort);

            $replicaPorts = $primary->replicaPorts;
            sort($replicaPorts);
            foreach ($replicaPorts as $replicaPort) {
                $rows[] = $this->buildReplicaRow($view, $replicaPort, $inflightPort);
            }
        }

        return $rows;
    }

    private function buildPrimaryRow(ChaosClusterView $view, ChaosPrimaryState $primary, ?int $inflightPort): TableRow
    {
        $node = $view->nodeStateByPort[$primary->port] ?? null;
        $slotCount = $primary->slotCount();

        $row = TableRow::fromCells(
            TableCell::fromString((string) $primary->port),
            TableCell::fromString($this->shortNodeId($primary->nodeId)),
            TableCell::fromString('primary'),
            TableCell::fromString(SlotRange::format($primary->slotRanges)),
            TableCell::fromString($slotCount === 0 ? '-' : (string) $slotCount),
            $this->stateCell($this->describePrimaryState($primary, $node)),
        );
        $row->style = $this->rowStyle($primary->port === $inflightPort);

        return $row;
    }

    private function buildReplicaRow(ChaosClusterView $view, int $replicaPort, ?int $inflightPort): TableRow
    {
        $node = $view->nodeStateByPort[$replicaPort] ?? null;

        $row = TableRow::fromCells(
            TableCell::fromString(self::REPLICA_PREFIX . $replicaPort),
            TableCell::fromString($this->shortNodeId($node instanceof ChaosNodeState ? $node->nodeId : '')),
            TableCell::fromString('replica'),
            TableCell::fromString('-'),
            TableCell::fromString('-'),
            $this->stateCell($this->describeReplicaState($node)),
        );
        $row->style = $this->rowStyle($replicaPort === $inflightPort);

        return $row;
    }

    private function rowStyle(bool $isEventTarget): Style
    {
        return $isEventTarget
            ? Style::default()->fg(AnsiColor::LightCyan)->addModifier(Modifier::BOLD)
            : Style::default();
    }

    /**
     * @param array{string, Style} $state
     */
    private function stateCell(array $state): TableCell
    {
        return TableCell::fromLine(Line::fromSpans(Span::styled($state[0], $state[1])));
    }

    /**
     * @return array{string, Style}
     */
    private function describePrimaryState(ChaosPrimaryState $primary, ?ChaosNodeState $node): array
    {
        if (!$primary->reachable) {
            return ['unreachable', Style::default()->fg(AnsiColor::LightRed)];
        }

        if ($node instanceof ChaosNodeState && $node->failoverInProgress) {
            return ['failing over', Style::default()->fg(AnsiColor::LightYellow)];
        }

        $replicaSummary = sprintf(
            '%d ok, %d sync, %d failed',
            $primary->healthyReplicaCount,
            $primary->syncingReplicaCount,
            $primary->failedReplicaCount,
        );

        if ($primary->isDegraded()) {
            return [sprintf('no replica (%s)', $replicaSummary), Style::default()->fg(AnsiColor::LightYellow)];
        }

        return [$replicaSummary, Style::default()->fg(AnsiColor::LightGreen)];
    }

    /**
     * @return array{string, Style}
     */
    private function describeReplicaState(?ChaosNodeState $node): array
    {
        if (!$node instanceof ChaosNodeState) {
            return ['unknown', Style::default()->fg(AnsiColor::DarkGray)];
        }

        if (!$node->reachable) {
            return ['down', Style::default()->fg(AnsiColor::LightRed)];
        }

        if ($node->isFailed) {
            return ['failed', Style::default()->fg(AnsiColor::LightRed)];
        }

        foreach ([
            [$node->isHandshake, 'handshake'],
            [$node->isLoading, 'loading'],
            [$node->isSyncing, 'syncing'],
            [$node->failoverInProgress, 'failing over'],
        ] as [$active, $label]) {
            if ($active === true) {
                return [$label, Style::default()->fg(AnsiColor::LightYellow)];
            }
        }

        if ($node->linkStatus !== '' && $node->linkStatus !== 'up') {
            return [sprintf('link %s', $node->linkStatus), Style::default()->fg(AnsiColor::LightYellow)];
        }

        return ['ok', Style::default()->fg(AnsiColor::LightGreen)];
    }

    private function buildEventPane(ChaosWatchState $state, int $logHeight): BlockWidget
    {
        $list = ListWidget::default()->items(...$this->buildEventItems($state, $logHeight));

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Plain)
            ->padding(Padding::fromScalars(left: 1, right: 1, top: 0, bottom: 0))
            ->titles(
                Title::fromString(' Chaos Events '),
                Title::fromString(sprintf(' %s ', $this->describeInflight($state)))
                    ->horizontalAlignment(HorizontalAlignment::Right),
            )
            ->widget($list);
    }

    private function describeInflight(ChaosWatchState $state): string
    {
        $event = $state->inflightEvent();
        if (!$event instanceof ChaosEventRecord) {
            return 'idle';
        }

        return sprintf('event#%d %s', $event->id, $event->status);
    }

    /**
     * @return list<ListItem>
     */
    private function buildEventItems(ChaosWatchState $state, int $logHeight): array
    {
        $entries = $state->visibleEntries($logHeight);
        if ($entries === []) {
            return [ListItem::new(Text::fromLine(Line::fromSpans(
                Span::styled('Waiting for the first chaos event.', Style::default()->fg(AnsiColor::DarkGray)),
            )))];
        }

        $items = [];
        foreach ($entries as $entry) {
            $items[] = ListItem::new(Text::fromLine(Line::fromSpans(
                Span::styled($entry->timestamp() . ' ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled(sprintf('%-5s ', $entry->level->value), $this->logLevelStyle($entry->level)),
                Span::fromString($entry->message),
            )));
        }

        return $items;
    }

    private function logLevelStyle(ChaosWatchLogLevel $level): Style
    {
        return match ($level) {
            ChaosWatchLogLevel::Event => Style::default()->fg(AnsiColor::LightCyan)->addModifier(Modifier::BOLD),
            ChaosWatchLogLevel::Plan => Style::default()->fg(AnsiColor::LightBlue),
            ChaosWatchLogLevel::Done => Style::default()->fg(AnsiColor::LightGreen),
            ChaosWatchLogLevel::Warning => Style::default()->fg(AnsiColor::LightYellow),
            ChaosWatchLogLevel::Failure => Style::default()->fg(AnsiColor::LightRed),
            default => Style::default()->fg(AnsiColor::DarkGray),
        };
    }

    private function shortNodeId(string $nodeId): string
    {
        if ($nodeId === '') {
            return '-';
        }

        return substr($nodeId, 0, self::NODE_ID_LENGTH);
    }

    private function maxNodeColumnWidth(?ChaosClusterView $view): int
    {
        $width = $this->stringWidth('Node');
        if (!$view instanceof ChaosClusterView) {
            return $width;
        }

        foreach ($view->nodeStateByPort as $port => $node) {
            $label = $node->role === 'replica' ? self::REPLICA_PREFIX . $port : (string) $port;
            $width = max($width, $this->stringWidth($label));
        }

        return $width;
    }

    private function maxSlotColumnWidth(?ChaosClusterView $view): int
    {
        $width = $this->stringWidth('Slots');
        if (!$view instanceof ChaosClusterView) {
            return $width;
        }

        foreach ($view->primaryStateByPort as $primary) {
            $width = max($width, $this->stringWidth(SlotRange::format($primary->slotRanges)));
        }

        return $width;
    }

    private function formatDuration(float $seconds): string
    {
        $whole = (int) $seconds;

        return sprintf('%02d:%02d:%02d', intdiv($whole, 3600), intdiv($whole % 3600, 60), $whole % 60);
    }

    private function stringWidth(string $value): int
    {
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($value, 'UTF-8');
        }

        return strlen($value);
    }
}
