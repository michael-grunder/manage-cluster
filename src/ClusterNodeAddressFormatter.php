<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ClusterNodeAddressFormatter
{
    /**
     * @param list<ClusterShardStatus> $shards
     */
    public static function shouldCollapseHosts(array $shards): bool
    {
        if ($shards === []) {
            return false;
        }

        foreach ($shards as $shard) {
            if (!$shard->master->isLoopbackHost()) {
                return false;
            }

            foreach ($shard->replicas as $replica) {
                if (!$replica->isLoopbackHost()) {
                    return false;
                }
            }
        }

        return true;
    }
}
