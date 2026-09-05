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
     * A managed replica that is attached and idle: reachable, not failed,
     * handshaking, loading, syncing, or already failing over, with its
     * replication link up. Promotion and reparenting both need this baseline
     * before they touch the node.
     */
    public function isStableManagedReplica(): bool
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
