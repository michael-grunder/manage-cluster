<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ClusterShardStatus
{
    /**
     * @param list<SlotRange> $slots ascending, non-overlapping ranges owned by the master
     * @param list<ClusterNodeStatus> $replicas
     */
    public function __construct(
        public array $slots,
        public ClusterNodeStatus $master,
        public array $replicas,
    ) {
    }

    public function ownsSlots(): bool
    {
        return $this->slots !== [];
    }

    public function ownsSlot(int $slot): bool
    {
        return SlotRange::containsSlot($this->slots, $slot);
    }

    public function slotCount(): int
    {
        return SlotRange::countSlots($this->slots);
    }

    /**
     * Lowest owned slot, used to order shards; null when the master owns none.
     */
    public function firstSlot(): ?int
    {
        return $this->slots === [] ? null : $this->slots[0]->start;
    }

    public function slotRange(): string
    {
        return SlotRange::format($this->slots);
    }
}
