<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\Table\TableRow;
use PhpTui\Tui\Extension\Core\Widget\TableWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\HorizontalAlignment;
use Throwable;

final class ClusterStatusTuiRenderer
{
    private const REPLICA_PREFIX = '↳ ';
    private const NODE_ID_LENGTH = 8;
    private const COLUMN_SPACING = 1;
    private const ID_COLUMN_WIDTH = self::NODE_ID_LENGTH + 1;
    private const HEALTH_COLUMN_MIN_WIDTH = 8;

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

            $this->display->draw($this->buildRootWidget($shards, $watchMode));
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

        // Block border/title + padding + table rows.
        return max(7, 5 + $rows);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function buildRootWidget(array $shards, bool $watchMode): BlockWidget
    {
        $collapseHosts = ClusterNodeAddressFormatter::shouldCollapseHosts($shards);
        $table = TableWidget::default()
            ->header($this->buildTableHeader())
            ->rows(...$this->buildTableRows($shards, $collapseHosts))
            ->widths(...$this->buildTableWidths($shards, $collapseHosts));
        $table->columnSpacing = self::COLUMN_SPACING;

        $title = Title::fromString(sprintf(' Cluster Status%s ', $watchMode ? ' [watch]' : ''));
        $clock = Title::fromString(sprintf(' %s ', date('H:i:s')))
            ->horizontalAlignment(HorizontalAlignment::Right);

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Plain)
            ->padding(Padding::fromScalars(left: 1, right: 1, top: 1, bottom: 1))
            ->titleStyle(Style::default()->addModifier(Modifier::BOLD))
            ->titles($title, $clock)
            ->widget($table);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     * @return list<Constraint>
     */
    private function buildTableWidths(array $shards, bool $collapseHosts): array
    {
        return [
            Constraint::length($this->maxNodeColumnWidth($shards, $collapseHosts)),
            Constraint::length(self::ID_COLUMN_WIDTH),
            Constraint::length($this->maxSlotsColumnWidth($shards)),
            Constraint::length($this->maxOffsetColumnWidth($shards)),
            Constraint::length($this->maxMemoryColumnWidth($shards)),
            Constraint::min(self::HEALTH_COLUMN_MIN_WIDTH),
        ];
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
    private function buildTableRows(array $shards, bool $collapseHosts): array
    {
        if ($shards === []) {
            return [TableRow::fromStrings('No shard information returned.', '-', '-', '-', '-', '-')];
        }

        $rows = [];
        foreach ($shards as $shard) {
            $rows[] = $this->buildNodeRow($shard->master, $shard->slotRange(), false, $collapseHosts);
            foreach ($shard->replicas as $replica) {
                $rows[] = $this->buildNodeRow($replica, '-', true, $collapseHosts);
            }
        }

        return $rows;
    }

    private function buildNodeRow(ClusterNodeStatus $node, string $slots, bool $isReplica, bool $collapseHosts): TableRow
    {
        $address = $isReplica
            ? self::REPLICA_PREFIX . $node->displayAddress($collapseHosts)
            : $node->displayAddress($collapseHosts);

        return TableRow::fromStrings(
            $address,
            $node->shortId(self::NODE_ID_LENGTH),
            $slots,
            (string) $node->replicationOffset,
            MemoryUsageFormatter::format($node->usedMemoryBytes),
            $node->health,
        );
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function maxNodeColumnWidth(array $shards, bool $collapseHosts): int
    {
        $width = $this->stringWidth('Node');

        foreach ($shards as $shard) {
            $width = max($width, $this->stringWidth($shard->master->displayAddress($collapseHosts)));

            foreach ($shard->replicas as $replica) {
                $width = max($width, $this->stringWidth(self::REPLICA_PREFIX . $replica->displayAddress($collapseHosts)));
            }
        }

        return $width;
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function maxSlotsColumnWidth(array $shards): int
    {
        $width = $this->stringWidth('Slots');

        foreach ($shards as $shard) {
            $width = max($width, $this->stringWidth($shard->slotRange()));
        }

        return $width;
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function maxOffsetColumnWidth(array $shards): int
    {
        $width = $this->stringWidth('Offset');

        foreach ($shards as $shard) {
            $width = max($width, $this->stringWidth((string) $shard->master->replicationOffset));
            foreach ($shard->replicas as $replica) {
                $width = max($width, $this->stringWidth((string) $replica->replicationOffset));
            }
        }

        return $width;
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function maxMemoryColumnWidth(array $shards): int
    {
        $width = $this->stringWidth('Memory');

        foreach ($shards as $shard) {
            $width = max($width, $this->stringWidth(MemoryUsageFormatter::format($shard->master->usedMemoryBytes)));

            foreach ($shard->replicas as $replica) {
                $width = max($width, $this->stringWidth(MemoryUsageFormatter::format($replica->usedMemoryBytes)));
            }
        }

        return $width;
    }

    private function stringWidth(string $value): int
    {
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($value, 'UTF-8');
        }

        return strlen($value);
    }
}
