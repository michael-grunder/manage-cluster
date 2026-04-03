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
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Widget\Direction;
use RuntimeException;
use Throwable;

final class ClusterTreeSelector
{
    public function __construct(
        private readonly ClusterTreeViewBuilder $viewBuilder,
    ) {
    }

    public function supportsInteractiveSelection(): bool
    {
        if (!function_exists('stream_isatty')) {
            return false;
        }

        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    public function select(array $shards, int $seedPort, ClusterTreeViewMode $mode, string $title): ?ClusterNodeStatus
    {
        if (!$this->supportsInteractiveSelection()) {
            throw new RuntimeException('Interactive cluster selection requires a TTY on stdin/stdout.');
        }

        $entries = $this->viewBuilder->build($shards, $mode);
        if ($entries === []) {
            throw new RuntimeException(sprintf('No selectable cluster nodes were discovered from seed port %d.', $seedPort));
        }

        $selectedIndex = $this->findFirstSelectableIndex($entries);
        $terminal = Terminal::new();
        $display = $this->createDisplay($terminal, $entries);

        $terminal->enableRawMode();

        try {
            while (true) {
                $display->draw($this->buildRootWidget(
                    entries: $entries,
                    selectedIndex: $selectedIndex,
                    seedPort: $seedPort,
                    title: $title,
                ));

                $event = $terminal->events()->next();
                if ($event instanceof TerminalResizedEvent) {
                    continue;
                }

                if ($event instanceof CodedKeyEvent) {
                    if ($event->code === KeyCode::Esc) {
                        return null;
                    }

                    $selectedIndex = match ($event->code) {
                        KeyCode::Up => $this->moveSelection($entries, $selectedIndex, -1),
                        KeyCode::Down => $this->moveSelection($entries, $selectedIndex, 1),
                        KeyCode::Home => $this->findFirstSelectableIndex($entries),
                        KeyCode::End => $this->findLastSelectableIndex($entries),
                        KeyCode::Enter => $selectedIndex,
                        default => $selectedIndex,
                    };

                    if ($event->code === KeyCode::Enter) {
                        if (!$entries[$selectedIndex]->selectable) {
                            continue;
                        }

                        return $entries[$selectedIndex]->node;
                    }

                    continue;
                }

                if ($event instanceof CharKeyEvent) {
                    $char = strtolower($event->char);
                    if ($char === 'q') {
                        return null;
                    }

                    $selectedIndex = match ($char) {
                        'k' => $this->moveSelection($entries, $selectedIndex, -1),
                        'j' => $this->moveSelection($entries, $selectedIndex, 1),
                        default => $selectedIndex,
                    };
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf('Interactive cluster selector failed: %s', $exception->getMessage()), previous: $exception);
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

    /**
     * @param list<ClusterTreeViewEntry> $entries
     */
    private function createDisplay(Terminal $terminal, array $entries): Display
    {
        $height = max(8, 4 + count($entries));
        $backend = PhpTermBackend::new($terminal);

        return DisplayBuilder::default($backend)
            ->inline($height)
            ->build();
    }

    /**
     * @param list<ClusterTreeViewEntry> $entries
     */
    private function buildRootWidget(array $entries, int $selectedIndex, int $seedPort, string $title): GridWidget
    {
        $metadata = ParagraphWidget::fromString(implode("\n", [
            $title,
            sprintf('Seed: 127.0.0.1:%d', $seedPort),
            'Controls: ↑/↓ or j/k to move, Enter to confirm, q/Esc to cancel',
        ]));

        $table = TableWidget::default()
            ->header($this->buildTableHeader())
            ->rows(...$this->buildRows($entries))
            ->select($selectedIndex)
            ->highlightSymbol('› ')
            ->highlightStyle(
                Style::default()
                    ->fg(AnsiColor::Black)
                    ->bg(AnsiColor::LightCyan)
                    ->addModifier(Modifier::BOLD)
            )
            ->widths(
                Constraint::percentage(40),
                Constraint::length(10),
                Constraint::length(14),
                Constraint::length(11),
                Constraint::min(8),
            );

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(3),
                Constraint::length(1),
                Constraint::min(1),
            )
            ->widgets(
                $metadata,
                ParagraphWidget::fromString(''),
                $table,
            );
    }

    private function buildTableHeader(): TableRow
    {
        $header = TableRow::fromStrings('Node', 'Role', 'Slots', 'Offset', 'Health');
        $header->style = Style::default()->addModifier(Modifier::BOLD);

        return $header;
    }

    /**
     * @param list<ClusterTreeViewEntry> $entries
     * @return list<TableRow>
     */
    private function buildRows(array $entries): array
    {
        $rows = [];

        foreach ($entries as $entry) {
            $row = TableRow::fromStrings(
                $entry->nodeLabel(),
                $entry->roleLabel(),
                $entry->slotRange ?? '-',
                (string) $entry->node->replicationOffset,
                $entry->node->health,
            );

            if ($entry->depth === 1) {
                $row->style = Style::default()->fg(AnsiColor::Gray);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<ClusterTreeViewEntry> $entries
     */
    private function findFirstSelectableIndex(array $entries): int
    {
        foreach ($entries as $index => $entry) {
            if ($entry->selectable) {
                return $index;
            }
        }

        throw new RuntimeException('Interactive cluster selector requires at least one selectable entry.');
    }

    /**
     * @param list<ClusterTreeViewEntry> $entries
     */
    private function findLastSelectableIndex(array $entries): int
    {
        for ($index = count($entries) - 1; $index >= 0; $index--) {
            if ($entries[$index]->selectable) {
                return $index;
            }
        }

        throw new RuntimeException('Interactive cluster selector requires at least one selectable entry.');
    }

    /**
     * @param list<ClusterTreeViewEntry> $entries
     */
    private function moveSelection(array $entries, int $selectedIndex, int $delta): int
    {
        if ($delta === 0) {
            return $selectedIndex;
        }

        $candidate = $selectedIndex;
        while (true) {
            $candidate += $delta;
            if ($candidate < 0 || $candidate >= count($entries)) {
                return $selectedIndex;
            }

            if ($entries[$candidate]->selectable) {
                return $candidate;
            }
        }
    }
}
