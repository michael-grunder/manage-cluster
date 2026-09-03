<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ClusterStatusRenderer
{
    private const int MIN_SLOT_COLUMN_WIDTH = 14;
    private const int MAX_SLOT_COLUMN_WIDTH = 40;

    /**
     * @param list<ClusterShardStatus> $shards
     * @param array<int, NodeLatencySnapshot> $latenciesByPort
     */
    public function render(array $shards, int $width, int $seedPort, bool $watchMode, array $latenciesByPort = []): string
    {
        $width = max(40, $width);
        $lines = [];
        $collapseHosts = ClusterNodeAddressFormatter::shouldCollapseHosts($shards);
        $slotColumnWidth = $this->slotColumnWidth($shards);

        $lines[] = sprintf('Cluster status (seed 127.0.0.1:%d)%s', $seedPort, $watchMode ? ' [watch]' : '');
        $lines[] = sprintf('Updated: %s', date('Y-m-d H:i:s'));
        $lines[] = str_repeat('-', min($width, 120));

        if ($shards === []) {
            $lines[] = 'No shard information returned.';

            return implode(PHP_EOL, $lines) . PHP_EOL;
        }

        foreach ($shards as $shard) {
            $lines[] = $this->renderNodeLine($shard->master, $shard->slotRange(), false, $width, $slotColumnWidth, $collapseHosts, $watchMode, $latenciesByPort);
            foreach ($shard->replicas as $replica) {
                $lines[] = $this->renderNodeLine($replica, null, true, $width, $slotColumnWidth, $collapseHosts, $watchMode, $latenciesByPort);
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<int, NodeLatencySnapshot> $latenciesByPort
     */
    private function renderNodeLine(
        ClusterNodeStatus $node,
        ?string $slotRange,
        bool $replica,
        int $width,
        int $slotColumnWidth,
        bool $collapseHosts,
        bool $watchMode,
        array $latenciesByPort,
    ): string {
        $address = $node->displayAddress($collapseHosts);
        $prefix = $replica ? ' - ' : '   ';
        $latency = ($latenciesByPort[$node->port] ?? new NodeLatencySnapshot(NodeLatencyState::Pending))->displayValue();

        if ($watchMode && $width >= 110) {
            $slots = $this->formatSlotCell($slotRange, $slotColumnWidth);

            return sprintf(
                '%s%-21s %-8s %s %-9d %-10s %-8s %s',
                $prefix,
                $this->trim($address, 21),
                $node->shortId(8),
                $slots,
                $node->replicationOffset,
                MemoryUsageFormatter::format($node->usedMemoryBytes),
                $latency,
                $node->health,
            );
        }

        if ($width >= 95) {
            $slots = $this->formatSlotCell($slotRange, $slotColumnWidth);

            return sprintf(
                '%s%-21s %-8s %s %-9d %-10s %s',
                $prefix,
                $this->trim($address, 21),
                $node->shortId(8),
                $slots,
                $node->replicationOffset,
                MemoryUsageFormatter::format($node->usedMemoryBytes),
                $node->health,
            );
        }

        if ($watchMode && $width >= 85) {
            $slots = $this->formatSlotCell($slotRange, $slotColumnWidth);

            return sprintf(
                '%s%-21s %-8s %s %-8s %s',
                $prefix,
                $this->trim($address, 21),
                $node->shortId(8),
                $slots,
                $latency,
                $node->health,
            );
        }

        if ($width >= 75) {
            $slots = $this->formatSlotCell($slotRange, $slotColumnWidth);

            return sprintf(
                '%s%-21s %-8s %s %-10s %s',
                $prefix,
                $this->trim($address, 21),
                $node->shortId(8),
                $slots,
                MemoryUsageFormatter::format($node->usedMemoryBytes),
                $node->health,
            );
        }

        if ($watchMode && $width >= 55) {
            return sprintf(
                '%s%-21s %-8s %-8s %s',
                $prefix,
                $this->trim($address, 21),
                $node->shortId(8),
                $latency,
                $node->health,
            );
        }

        if ($width >= 55) {
            return sprintf(
                '%s%-21s %-8s %s',
                $prefix,
                $this->trim($address, 21),
                $node->shortId(8),
                $node->health,
            );
        }

        if ($watchMode) {
            return sprintf('%s%s %s %s', $prefix, $this->trim($address, max(16, $width - 24)), $latency, $node->health);
        }

        return sprintf('%s%s %s', $prefix, $this->trim($address, max(20, $width - 16)), $node->health);
    }

    /**
     * Slot ownership fragments as slots migrate, so the column grows to fit the
     * widest range list instead of silently pushing later columns out of line.
     *
     * @param list<ClusterShardStatus> $shards
     */
    private function slotColumnWidth(array $shards): int
    {
        $width = self::MIN_SLOT_COLUMN_WIDTH;

        foreach ($shards as $shard) {
            $width = max($width, strlen($shard->slotRange()) + 2);
        }

        return min($width, self::MAX_SLOT_COLUMN_WIDTH);
    }

    private function formatSlotCell(?string $slotRange, int $slotColumnWidth): string
    {
        $slots = $slotRange !== null ? sprintf('[%s]', $slotRange) : '-';

        return str_pad($this->trim($slots, $slotColumnWidth), $slotColumnWidth);
    }

    private function trim(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        if ($length <= 3) {
            return substr($value, 0, $length);
        }

        return substr($value, 0, $length - 3) . '...';
    }
}
