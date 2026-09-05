<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosNodeState
{
    /**
     * @param list<SlotRange> $slotRanges
     */
    public function __construct(
        public int $port,
        public string $nodeId,
        public string $role,
        public ?int $primaryPort,
        public bool $knownByCluster,
        public bool $reachable,
        public bool $isFailed,
        public bool $isHandshake,
        public bool $isLoading,
        public bool $isSyncing,
        public string $linkStatus,
        public array $slotRanges,
        public ?int $pid,
        public bool $managed,
        public string $health,
        public ?int $replicationOffset = null,
        public bool $failoverInProgress = false,
    ) {
    }

    /**
     * A promotion target for a coordinated failover: a managed replica that is
     * attached, caught up enough to be visible, and not already in the middle
     * of a failover, sync, or load.
     */
    public function isPromotableReplica(): bool
    {
        return $this->role === 'replica'
            && $this->managed
            && $this->knownByCluster
            && $this->reachable
            && !$this->isFailed
            && !$this->isHandshake
            && !$this->isLoading
            && !$this->isSyncing
            && !$this->failoverInProgress
            && $this->linkStatus === 'up';
    }

    public function isHealthyReplica(): bool
    {
        return $this->role === 'replica'
            && $this->reachable
            && !$this->isFailed
            && !$this->isHandshake
            && !$this->isLoading;
    }
}
