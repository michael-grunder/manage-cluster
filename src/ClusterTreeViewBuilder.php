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

        foreach ($shards as $shard) {
            $entries[] = new ClusterTreeViewEntry(
                node: $shard->master,
                slotRange: $shard->slotRange(),
                depth: 0,
                selectable: true,
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
                );
            }
        }

        return $entries;
    }
}
