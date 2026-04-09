<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosClusterView
{
    /**
     * @param array<int, ChaosNodeState> $nodeStateByPort
     * @param array<int, ChaosPrimaryState> $primaryStateByPort
     * @param array<int, ChaosNodeState> $replicaStateByPort
     * @param list<int> $degradedPrimaryPorts
     */
    public function __construct(
        public string $clusterId,
        public int $seedPort,
        public string $topologyHash,
        public bool $clusterDown,
        public bool $broadlyHealthy,
        public array $nodeStateByPort,
        public array $primaryStateByPort,
        public array $replicaStateByPort,
        public array $degradedPrimaryPorts,
    ) {
    }
}
