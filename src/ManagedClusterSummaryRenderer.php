<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ManagedClusterSummaryRenderer
{
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
    public function render(array $clusters, int $width, bool $watchMode, bool $runningOnly): string
    {
        $width = max(60, $width);
        $lines = [];

        $lines[] = $runningOnly ? 'Managed clusters that appear to be running' : 'Managed cluster summary';
        $lines[] = sprintf('Updated: %s%s', date('Y-m-d H:i:s'), $watchMode ? ' [watch]' : '');
        $lines[] = str_repeat('-', min($width, 120));

        if ($clusters === []) {
            $lines[] = $runningOnly
                ? 'No managed clusters appear to be running.'
                : 'No managed clusters found.';

            return implode(PHP_EOL, $lines) . PHP_EOL;
        }

        $lines[] = sprintf(
            '%-7s %-5s %-13s %-7s %-8s %-4s %s',
            'State',
            'Seed',
            'Ports',
            'Nodes',
            'Replica',
            'TLS',
            'Cluster',
        );

        foreach ($clusters as $cluster) {
            $lines[] = sprintf(
                '%-7s %-5d %-13s %-7s %-8d %-4s %s',
                $this->stateLabel($cluster['listening_nodes'], $cluster['total_nodes']),
                $cluster['seed_port'],
                $this->trim($cluster['port_range'], 13),
                sprintf('%d/%d', $cluster['listening_nodes'], $cluster['total_nodes']),
                $cluster['replicas'],
                $cluster['tls'] ? 'yes' : 'no',
                $cluster['id'],
            );
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function stateLabel(int $listeningNodes, int $totalNodes): string
    {
        if ($listeningNodes <= 0) {
            return 'down';
        }

        if ($listeningNodes >= $totalNodes) {
            return 'up';
        }

        return 'partial';
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
