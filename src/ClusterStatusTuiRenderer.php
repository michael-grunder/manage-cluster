<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

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
use Throwable;

final class ClusterStatusTuiRenderer
{
    private const REPLICA_PREFIX = '↳ ';
    private const NODE_ID_LENGTH = 8;
    private const ID_COLUMN_WIDTH = self::NODE_ID_LENGTH + 1;
    private const SLOTS_COLUMN_WIDTH = 14;
    private const OFFSET_COLUMN_WIDTH = 11;
    private const MEMORY_COLUMN_WIDTH = 10;

    private ?Display $display = null;
    private ?int $viewportHeight = null;
    private ?bool $fullscreenMode = null;

    /**
     * @param list<ClusterShardStatus> $shards
     */
    public function render(array $shards, int $seedPort, bool $watchMode): bool
    {
        if (!$this->supportsCurrentOutput()) {
            return false;
        }
        try {
            $height = $this->calculateViewportHeight($shards);
            $fullscreenMode = $watchMode;

            if (
                $this->display === null
                || $this->fullscreenMode !== $fullscreenMode
                || (!$fullscreenMode && $this->viewportHeight !== $height)
            ) {
                $builder = DisplayBuilder::default();
                if ($fullscreenMode) {
                    $builder->fullscreen();
                } else {
                    $builder->inline($height);
                }

                $this->display = $builder->build();
                $this->viewportHeight = $height;
                $this->fullscreenMode = $fullscreenMode;
            }

            $this->display->draw($this->buildRootWidget($shards, $seedPort, $watchMode));
        } catch (Throwable) {
            $this->display = null;
            $this->viewportHeight = null;
            $this->fullscreenMode = null;

            return false;
        }

        return true;
    }

    private function supportsCurrentOutput(): bool
    {
        if (!function_exists('stream_isatty')) {
            return false;
        }

        return stream_isatty(STDOUT);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function calculateViewportHeight(array $shards): int
    {
        $rows = 1;
        foreach ($shards as $shard) {
            $rows += 1 + count($shard->replicas);
        }

        // Block border/title + padding + 3 metadata lines + spacer + table rows.
        return max(10, 7 + $rows);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function buildRootWidget(array $shards, int $seedPort, bool $watchMode): BlockWidget
    {
        $metadata = ParagraphWidget::fromString(implode("\n", [
            sprintf('Updated: %s', date('Y-m-d H:i:s')),
            sprintf('Seed: 127.0.0.1:%d', $seedPort),
            $watchMode ? 'Mode: live watch' : 'Mode: snapshot',
        ]));

        $table = TableWidget::default()
            ->header($this->buildTableHeader())
            ->rows(...$this->buildTableRows($shards))
            ->widths(
                Constraint::percentage(34),
                Constraint::length(self::ID_COLUMN_WIDTH),
                Constraint::length(self::SLOTS_COLUMN_WIDTH),
                Constraint::length(self::OFFSET_COLUMN_WIDTH),
                Constraint::length(self::MEMORY_COLUMN_WIDTH),
                Constraint::min(8),
            );

        $content = GridWidget::default()
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

        $title = Title::fromString(sprintf(' Cluster Status%s ', $watchMode ? ' [watch]' : ''))
            ->horizontalAlignment(HorizontalAlignment::Center);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Plain)
            ->padding(Padding::fromScalars(left: 1, right: 1, top: 1, bottom: 1))
            ->titleStyle(Style::default()->addModifier(Modifier::BOLD))
            ->titles($title)
            ->widget($content);
    }

    private function buildTableHeader(): TableRow
    {
        $header = TableRow::fromStrings('Node', 'ID', 'Slots', 'Offset', 'Memory', 'Health');
        $header->style = Style::default()->addModifier(Modifier::BOLD);

        return $header;
    }

    /**
     * @param list<ClusterShardStatus> $shards
     * @return list<TableRow>
     */
    private function buildTableRows(array $shards): array
    {
        if ($shards === []) {
            return [TableRow::fromStrings('No shard information returned.', '-', '-', '-', '-', '-')];
        }

        $rows = [];
        foreach ($shards as $shard) {
            $rows[] = $this->buildNodeRow($shard->master, $shard->slotRange(), false);
            foreach ($shard->replicas as $replica) {
                $rows[] = $this->buildNodeRow($replica, '-', true);
            }
        }

        return $rows;
    }

    private function buildNodeRow(ClusterNodeStatus $node, string $slots, bool $isReplica): TableRow
    {
        $address = $isReplica
            ? self::REPLICA_PREFIX . $node->address()
            : $node->address();

        return TableRow::fromStrings(
            $address,
            $node->shortId(self::NODE_ID_LENGTH),
            $slots,
            (string) $node->replicationOffset,
            MemoryUsageFormatter::format($node->usedMemoryBytes),
            $node->health,
        );
    }
}
