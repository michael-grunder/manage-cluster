<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final readonly class SlotMigrationPlan
{
    /**
     * @param list<SlotRange> $ranges
     */
    public function __construct(
        public SlotMigrationStrategy $strategy,
        public int $sourcePort,
        public string $sourceNodeId,
        public int $destinationPort,
        public string $destinationNodeId,
        public array $ranges,
    ) {
        if ($ranges === []) {
            throw new InvalidArgumentException('A slot migration plan must move at least one slot.');
        }

        if ($sourcePort === $destinationPort) {
            throw new InvalidArgumentException(sprintf('Slot migration source and destination are both port %d.', $sourcePort));
        }
    }

    public function slotCount(): int
    {
        return SlotRange::countSlots($this->ranges);
    }

    /**
     * @return list<int>
     */
    public function slots(): array
    {
        return SlotRange::expand($this->ranges);
    }

    public function describeRanges(): string
    {
        return SlotRange::format($this->ranges);
    }

    public function summary(): string
    {
        return sprintf(
            'slot-migration slots=%s (%d) source=%d destination=%d strategy=%s',
            $this->describeRanges(),
            $this->slotCount(),
            $this->sourcePort,
            $this->destinationPort,
            $this->strategy->value,
        );
    }

    public function postcondition(): string
    {
        return sprintf(
            'primary %d owns slots %s with no open migration state',
            $this->destinationPort,
            $this->describeRanges(),
        );
    }
}
