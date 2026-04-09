<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ClusterTreeViewBuilder
{
    /**
     * @param list<ClusterShardStatus> $shards
     * @return list<ClusterTreeViewEntry>
     */
    public function build(array $shards, ClusterTreeViewMode $mode): array
    {
        $entries = [];
        $collapseHosts = ClusterNodeAddressFormatter::shouldCollapseHosts($shards);

        foreach ($shards as $shard) {
            if ($mode === ClusterTreeViewMode::FailedReplicasOnly) {
                $failedReplicas = array_values(array_filter(
                    $shard->replicas,
                    static fn (ClusterNodeStatus $replica): bool => $replica->health === 'fail',
                ));

                if ($failedReplicas === []) {
                    continue;
                }

                $entries[] = new ClusterTreeViewEntry(
                    node: $shard->master,
                    slotRange: $shard->slotRange(),
                    depth: 0,
                    selectable: false,
                    collapseHost: $collapseHosts,
                );

                foreach ($failedReplicas as $replica) {
                    $entries[] = new ClusterTreeViewEntry(
                        node: $replica,
                        slotRange: null,
                        depth: 1,
                        selectable: true,
                        collapseHost: $collapseHosts,
                    );
                }

                continue;
            }

            $entries[] = new ClusterTreeViewEntry(
                node: $shard->master,
                slotRange: $shard->slotRange(),
                depth: 0,
                selectable: true,
                collapseHost: $collapseHosts,
            );

            if ($mode === ClusterTreeViewMode::PrimariesOnly) {
                continue;
            }

            foreach ($shard->replicas as $replica) {
                $entries[] = new ClusterTreeViewEntry(
                    node: $replica,
                    slotRange: null,
                    depth: 1,
                    selectable: true,
                    collapseHost: $collapseHosts,
                );
            }
        }

        return $entries;
    }
}
