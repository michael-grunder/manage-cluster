<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class PrimarySlotAssignment
{
    /**
     * @param list<SlotRange> $ranges
     */
    public function __construct(
        public int $port,
        public string $nodeId,
        public array $ranges,
    ) {
    }

    public function slotCount(): int
    {
        return SlotRange::countSlots($this->ranges);
    }

    public function ownsSlots(): bool
    {
        return $this->ranges !== [];
    }

    /**
     * @return list<int>
     */
    public function slots(): array
    {
        return SlotRange::expand($this->ranges);
    }
}
