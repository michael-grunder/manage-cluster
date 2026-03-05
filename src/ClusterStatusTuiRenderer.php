<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

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
use Throwable;

final class ClusterStatusTuiRenderer
{
    private const REPLICA_PREFIX = '↳ ';
    private const NODE_ID_LENGTH = 8;
    private const ROLE_COLUMN_WIDTH = 9;
    private const ID_COLUMN_WIDTH = self::NODE_ID_LENGTH + 1;
    private const SLOTS_COLUMN_WIDTH = 14;
    private const OFFSET_COLUMN_WIDTH = 11;

    private ?Display $display = null;
    private ?int $viewportHeight = null;

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
            if ($this->display === null || $this->viewportHeight !== $height) {
                $this->display = DisplayBuilder::default()
                    ->inline($height)
                    ->build();
                $this->viewportHeight = $height;
            }

            $this->display->draw($this->buildRootWidget($shards, $seedPort, $watchMode));
        } catch (Throwable) {
            $this->display = null;
            $this->viewportHeight = null;

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

        // 2 lines metadata, 1 spacer, then table rows + header.
        return max(6, 3 + $rows);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function buildRootWidget(array $shards, int $seedPort, bool $watchMode): GridWidget
    {
        $metadata = ParagraphWidget::fromString(implode("\n", [
            sprintf('Cluster status (seed 127.0.0.1:%d)%s', $seedPort, $watchMode ? ' [watch]' : ''),
            sprintf('Updated: %s', date('Y-m-d H:i:s')),
        ]));

        $table = TableWidget::default()
            ->header($this->buildTableHeader())
            ->rows(...$this->buildTableRows($shards))
            ->widths(
                Constraint::percentage(30),
                Constraint::length(self::ID_COLUMN_WIDTH),
                Constraint::length(self::ROLE_COLUMN_WIDTH),
                Constraint::length(self::SLOTS_COLUMN_WIDTH),
                Constraint::length(self::OFFSET_COLUMN_WIDTH),
                Constraint::min(8),
            );

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(2),
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
        $header = TableRow::fromStrings('Node', 'ID', 'Role', 'Slots', 'Offset', 'Health');
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
            $rows[] = $this->buildNodeRow($shard->master, 'master', $shard->slotRange(), false);
            foreach ($shard->replicas as $replica) {
                $rows[] = $this->buildNodeRow($replica, 'replica', '-', true);
            }
        }

        return $rows;
    }

    private function buildNodeRow(ClusterNodeStatus $node, string $role, string $slots, bool $isReplica): TableRow
    {
        $address = $isReplica
            ? self::REPLICA_PREFIX . $node->address()
            : $node->address();

        return TableRow::fromStrings(
            $address,
            $node->shortId(self::NODE_ID_LENGTH),
            $role,
            $slots,
            (string) $node->replicationOffset,
            $node->health,
        );
    }
}
