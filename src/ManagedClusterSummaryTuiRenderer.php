<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\TerminalResizedEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\HorizontalAlignment;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type ManagedClusterSummary array{
 *   id: string,
 *   seed_port: int,
 *   port_range: string,
 *   total_nodes: int,
 *   listening_nodes: int,
 *   replicas: int,
 *   tls: bool
 * }
 */
final class ManagedClusterSummaryTuiRenderer
{
    private const COLUMN_SPACING = 1;

    public function supportsInteractiveWatch(): bool
    {
        if (!function_exists('stream_isatty')) {
            return false;
        }

        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    /**
     * @param callable(): list<ManagedClusterSummary> $clusterProvider
     */
    public function watch(callable $clusterProvider, bool $runningOnly): ?int
    {
        if (!$this->supportsInteractiveWatch()) {
            throw new RuntimeException('Interactive managed cluster status requires a TTY on stdin/stdout.');
        }

        $clusters = [];
        $selectedIndex = null;
        $terminal = Terminal::new();
        $display = $this->createDisplay($terminal);
        $nextRefreshAt = 0.0;
        $dirty = true;

        $terminal->enableRawMode();

        try {
            $display->clear();

            while (true) {
                $now = microtime(true);
                if ($now >= $nextRefreshAt) {
                    $selectedSeedPort = $selectedIndex === null ? null : ($clusters[$selectedIndex]['seed_port'] ?? null);
                    $clusters = $clusterProvider();
                    $selectedIndex = $this->normalizeSelectedIndex($clusters, $selectedSeedPort, $selectedIndex);
                    $nextRefreshAt = $now + 1.0;
                    $dirty = true;
                }

                while (null !== $event = $terminal->events()->next()) {
                    if ($event instanceof TerminalResizedEvent) {
                        $dirty = true;
                        continue;
                    }

                    if ($event instanceof CodedKeyEvent) {
                        if ($event->code === KeyCode::Esc) {
                            return null;
                        }

                        if ($event->code === KeyCode::Enter) {
                            if ($selectedIndex !== null && $this->isSelectable($clusters[$selectedIndex])) {
                                return $clusters[$selectedIndex]['seed_port'];
                            }

                            continue;
                        }

                        $nextIndex = match ($event->code) {
                            KeyCode::Up => $this->moveSelection($clusters, $selectedIndex, -1),
                            KeyCode::Down => $this->moveSelection($clusters, $selectedIndex, 1),
                            KeyCode::Home => $this->findFirstSelectableIndex($clusters),
                            KeyCode::End => $this->findLastSelectableIndex($clusters),
                            default => $selectedIndex,
                        };

                        if ($nextIndex !== $selectedIndex) {
                            $selectedIndex = $nextIndex;
                            $dirty = true;
                        }

                        continue;
                    }

                    if ($event instanceof CharKeyEvent) {
                        $char = strtolower($event->char);
                        if ($char === 'q') {
                            return null;
                        }

                        $nextIndex = match ($char) {
                            'k' => $this->moveSelection($clusters, $selectedIndex, -1),
                            'j' => $this->moveSelection($clusters, $selectedIndex, 1),
                            default => $selectedIndex,
                        };

                        if ($nextIndex !== $selectedIndex) {
                            $selectedIndex = $nextIndex;
                            $dirty = true;
                        }
                    }
                }

                if ($dirty) {
                    $display->draw($this->buildRootWidget($clusters, $selectedIndex, $runningOnly));
                    $dirty = false;
                }

                usleep(25_000);
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf('Interactive managed cluster status failed: %s', $exception->getMessage()), previous: $exception);
        } finally {
            try {
                $display->clear();
            } catch (Throwable) {
            }

            try {
                $terminal->disableRawMode();
            } catch (Throwable) {
            }
        }
    }

    private function createDisplay(Terminal $terminal): Display
    {
        return DisplayBuilder::default(PhpTermBackend::new($terminal))
            ->fullscreen()
            ->build();
    }

    /**
     * @param list<ManagedClusterSummary> $clusters
     */
    private function buildRootWidget(array $clusters, ?int $selectedIndex, bool $runningOnly): BlockWidget
    {
        $table = TableWidget::default()
            ->header($this->buildTableHeader())
            ->rows(...$this->buildTableRows($clusters, $runningOnly))
            ->highlightSymbol('> ')
            ->highlightStyle(
                Style::default()
                    ->fg(AnsiColor::Black)
                    ->bg(AnsiColor::LightCyan)
                    ->addModifier(Modifier::BOLD)
            )
            ->widths(
                Constraint::length(8),
                Constraint::length(6),
                Constraint::length($this->maxPortRangeWidth($clusters)),
                Constraint::length(7),
                Constraint::length(8),
                Constraint::length(4),
                Constraint::min(10),
            );
        $table->columnSpacing = self::COLUMN_SPACING;

        if ($selectedIndex !== null) {
            $table->select($selectedIndex);
        }

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(1),
                Constraint::length(1),
                Constraint::min(1),
            )
            ->widgets(
                ParagraphWidget::fromString('Controls: Up/Down or j/k select, Enter opens cluster watch, q/Esc exits'),
                ParagraphWidget::fromString(''),
                $table,
            );

        $title = Title::fromString(sprintf(' %s [watch] ', $runningOnly ? 'Running Managed Clusters' : 'Managed Cluster Summary'));
        $clock = Title::fromString(sprintf(' %s ', date('H:i:s')))
            ->horizontalAlignment(HorizontalAlignment::Right);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Plain)
            ->padding(Padding::fromScalars(left: 1, right: 1, top: 1, bottom: 1))
            ->titleStyle(Style::default()->addModifier(Modifier::BOLD))
            ->titles($title, $clock)
            ->widget($body);
    }

    private function buildTableHeader(): TableRow
    {
        $header = TableRow::fromStrings('State', 'Seed', 'Ports', 'Nodes', 'Replica', 'TLS', 'Cluster');
        $header->style = Style::default()->addModifier(Modifier::BOLD);

        return $header;
    }

    /**
     * @param list<ManagedClusterSummary> $clusters
     * @return list<TableRow>
     */
    private function buildTableRows(array $clusters, bool $runningOnly): array
    {
        if ($clusters === []) {
            return [TableRow::fromStrings(
                '-',
                '-',
                '-',
                '-',
                '-',
                '-',
                $runningOnly ? 'No managed clusters appear to be running.' : 'No managed clusters found.',
            )];
        }

        $rows = [];
        foreach ($clusters as $cluster) {
            $row = TableRow::fromStrings(
                $this->stateLabel($cluster),
                (string) $cluster['seed_port'],
                $cluster['port_range'],
                sprintf('%d/%d', $cluster['listening_nodes'], $cluster['total_nodes']),
                (string) $cluster['replicas'],
                $cluster['tls'] ? 'yes' : 'no',
                $cluster['id'],
            );

            if (!$this->isSelectable($cluster)) {
                $row->style = Style::default()->fg(AnsiColor::DarkGray);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<ManagedClusterSummary> $clusters
     */
    private function normalizeSelectedIndex(array $clusters, ?int $selectedSeedPort, ?int $selectedIndex): ?int
    {
        if ($clusters === []) {
            return null;
        }

        if ($selectedSeedPort !== null) {
            foreach ($clusters as $index => $cluster) {
                if ($cluster['seed_port'] === $selectedSeedPort && $this->isSelectable($cluster)) {
                    return $index;
                }
            }
        }

        if (
            $selectedIndex !== null
            && isset($clusters[$selectedIndex])
            && $this->isSelectable($clusters[$selectedIndex])
        ) {
            return $selectedIndex;
        }

        return $this->findFirstSelectableIndex($clusters);
    }

    /**
     * @param list<ManagedClusterSummary> $clusters
     */
    private function moveSelection(array $clusters, ?int $selectedIndex, int $delta): ?int
    {
        if ($delta === 0 || $clusters === []) {
            return $selectedIndex;
        }

        if ($selectedIndex === null) {
            return $delta > 0 ? $this->findFirstSelectableIndex($clusters) : $this->findLastSelectableIndex($clusters);
        }

        $candidate = $selectedIndex;
        while (true) {
            $candidate += $delta;
            if ($candidate < 0 || $candidate >= count($clusters)) {
                return $selectedIndex;
            }

            if ($this->isSelectable($clusters[$candidate])) {
                return $candidate;
            }
        }
    }

    /**
     * @param list<ManagedClusterSummary> $clusters
     */
    private function findFirstSelectableIndex(array $clusters): ?int
    {
        foreach ($clusters as $index => $cluster) {
            if ($this->isSelectable($cluster)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<ManagedClusterSummary> $clusters
     */
    private function findLastSelectableIndex(array $clusters): ?int
    {
        for ($index = count($clusters) - 1; $index >= 0; $index--) {
            if ($this->isSelectable($clusters[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param ManagedClusterSummary $cluster
     */
    private function isSelectable(array $cluster): bool
    {
        return $cluster['listening_nodes'] > 0;
    }

    /**
     * @param ManagedClusterSummary $cluster
     */
    private function stateLabel(array $cluster): string
    {
        if ($cluster['listening_nodes'] <= 0) {
            return 'down';
        }

        if ($cluster['listening_nodes'] >= $cluster['total_nodes']) {
            return 'up';
        }

        return 'partial';
    }

    /**
     * @param list<ManagedClusterSummary> $clusters
     */
    private function maxPortRangeWidth(array $clusters): int
    {
        $width = strlen('Ports');
        foreach ($clusters as $cluster) {
            $width = max($width, min(24, strlen($cluster['port_range'])));
        }

        return $width;
    }
}
