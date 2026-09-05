<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

/**
 * One primary-add event: start a new managed node, join it with `CLUSTER MEET`,
 * then hand it a bounded slice of the donor's slots.
 *
 * The plan is fixed before the node exists, so it names the donor and the slots
 * that must end up on the new port. The new node's ID is only known once it has
 * started, which is why the plan does not carry one.
 */
final readonly class PrimaryAddPlan
{
    /**
     * @param list<SlotRange> $ranges
     */
    public function __construct(
        public int $newPrimaryPort,
        public int $donorPort,
        public string $donorNodeId,
        public array $ranges,
    ) {
        if ($ranges === []) {
            throw new InvalidArgumentException('A primary add plan must hand the new primary at least one slot.');
        }

        if ($newPrimaryPort === $donorPort) {
            throw new InvalidArgumentException(sprintf('Primary add donor and new primary are both port %d.', $donorPort));
        }

        if ($donorNodeId === '') {
            throw new InvalidArgumentException(sprintf('Primary add needs the node id of donor primary %d.', $donorPort));
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
            'primary-add port=%d donor=%d slots=%s (%d)',
            $this->newPrimaryPort,
            $this->donorPort,
            $this->describeRanges(),
            $this->slotCount(),
        );
    }

    public function postcondition(): string
    {
        return sprintf(
            'new primary %d is known by the cluster and owns slots %s',
            $this->newPrimaryPort,
            $this->describeRanges(),
        );
    }
}
