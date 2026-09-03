<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosPrimaryState
{
    /**
     * @param list<SlotRange> $slotRanges
     * @param list<int> $replicaPorts
     */
    public function __construct(
        public int $port,
        public string $nodeId,
        public bool $reachable,
        public array $slotRanges,
        public array $replicaPorts,
        public int $healthyReplicaCount,
        public int $syncingReplicaCount,
        public int $failedReplicaCount,
    ) {
    }

    public function ownsSlots(): bool
    {
        return $this->slotRanges !== [];
    }

    public function ownsSlot(int $slot): bool
    {
        return SlotRange::containsSlot($this->slotRanges, $slot);
    }

    public function slotCount(): int
    {
        return SlotRange::countSlots($this->slotRanges);
    }

    public function isDegraded(): bool
    {
        return $this->healthyReplicaCount === 0;
    }
}
