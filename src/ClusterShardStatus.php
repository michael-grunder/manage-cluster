<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ClusterShardStatus
{
    /**
     * @param list<ClusterNodeStatus> $replicas
     */
    public function __construct(
        public int $slotStart,
        public int $slotEnd,
        public ClusterNodeStatus $master,
        public array $replicas,
    ) {
    }

    public function slotRange(): string
    {
        return sprintf('%d-%d', $this->slotStart, $this->slotEnd);
    }
}
