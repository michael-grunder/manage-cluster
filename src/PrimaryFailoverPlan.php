<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

/**
 * One coordinated primary failover: promote `replicaPort` over `primaryPort`.
 *
 * The plan records the shard as it looked when the event was chosen, so the
 * convergence check can assert that the promoted node took over exactly the
 * slots the demoted primary used to own.
 */
final readonly class PrimaryFailoverPlan
{
    /**
     * @param list<SlotRange> $ranges
     */
    public function __construct(
        public int $primaryPort,
        public string $primaryNodeId,
        public int $replicaPort,
        public string $replicaNodeId,
        public array $ranges,
        public ?int $replicationLagBytes = null,
    ) {
        if ($ranges === []) {
            throw new InvalidArgumentException('A primary failover plan must promote a replica of a slot-owning primary.');
        }

        if ($primaryPort === $replicaPort) {
            throw new InvalidArgumentException(sprintf('Primary failover primary and replica are both port %d.', $primaryPort));
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

    public function describeLag(): string
    {
        return $this->replicationLagBytes === null ? 'unknown' : sprintf('%d bytes', $this->replicationLagBytes);
    }

    public function summary(): string
    {
        return sprintf(
            'primary-failover promote=%d demote=%d slots=%s (%d) lag=%s',
            $this->replicaPort,
            $this->primaryPort,
            $this->describeRanges(),
            $this->slotCount(),
            $this->describeLag(),
        );
    }

    public function postcondition(): string
    {
        return sprintf(
            'replica %d owns slots %s as a primary and former primary %d follows it',
            $this->replicaPort,
            $this->describeRanges(),
            $this->primaryPort,
        );
    }
}
