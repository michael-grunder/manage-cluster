<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosNodeState
{
    /**
     * @param list<string> $slotRanges
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
    ) {
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
