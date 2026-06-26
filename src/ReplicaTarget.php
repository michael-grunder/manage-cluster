<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ReplicaTarget
{
    public function __construct(
        public ClusterNodeStatus $replica,
        public int $primaryPort,
    ) {
    }
}
