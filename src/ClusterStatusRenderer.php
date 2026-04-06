<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ClusterStatusRenderer
{
    /**
     * @param list<ClusterShardStatus> $shards
     */
    public function render(array $shards, int $width, int $seedPort, bool $watchMode): string
    {
        $width = max(40, $width);
        $lines = [];

        $lines[] = sprintf('Cluster status (seed 127.0.0.1:%d)%s', $seedPort, $watchMode ? ' [watch]' : '');
        $lines[] = sprintf('Updated: %s', date('Y-m-d H:i:s'));
        $lines[] = str_repeat('-', min($width, 120));

        if ($shards === []) {
            $lines[] = 'No shard information returned.';

            return implode(PHP_EOL, $lines) . PHP_EOL;
        }

        foreach ($shards as $shard) {
            $lines[] = $this->renderNodeLine($shard->master, $shard->slotRange(), false, $width);
            foreach ($shard->replicas as $replica) {
                $lines[] = $this->renderNodeLine($replica, null, true, $width);
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function renderNodeLine(ClusterNodeStatus $node, ?string $slotRange, bool $replica, int $width): string
    {
        $address = $node->address();
        $prefix = $replica ? ' - ' : '   ';

        if ($width >= 95) {
            $slots = $slotRange !== null ? sprintf('[%s]', $slotRange) : '-';

            return sprintf(
                '%s%-21s %-8s %-14s %-9d %-10s %s',
                $prefix,
                $this->trim($address, 21),
                $node->shortId(8),
                $slots,
                $node->replicationOffset,
                MemoryUsageFormatter::format($node->usedMemoryBytes),
                $node->health,
            );
        }

        if ($width >= 75) {
            $slots = $slotRange !== null ? sprintf('[%s]', $slotRange) : '-';

            return sprintf(
                '%s%-21s %-8s %-14s %-10s %s',
                $prefix,
                $this->trim($address, 21),
                $node->shortId(8),
                $slots,
                MemoryUsageFormatter::format($node->usedMemoryBytes),
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

        return sprintf('%s%s %s', $prefix, $this->trim($address, max(20, $width - 16)), $node->health);
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
