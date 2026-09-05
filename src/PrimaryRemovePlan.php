<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

/**
 * One primary-remove event, which is inherently multi-step: the victim's
 * replicas move to a surviving primary, every slot it owns is drained to the
 * remaining primaries, the cluster forgets its node ID, and only then does the
 * process stop.
 *
 * Draining first is what makes this a membership test rather than an outage:
 * the keyspace stays fully served while a node clients know about disappears.
 */
final readonly class PrimaryRemovePlan
{
    /**
     * @param list<SlotMigrationPlan> $drainPlans
     * @param list<int> $replicaPorts
     */
    public function __construct(
        public int $port,
        public string $nodeId,
        public array $drainPlans,
        public array $replicaPorts = [],
        public ?int $replicaRecipientPort = null,
        public string $replicaRecipientNodeId = '',
    ) {
        if ($nodeId === '') {
            throw new InvalidArgumentException(sprintf('Primary remove needs the node id of primary %d.', $port));
        }

        foreach ($drainPlans as $drainPlan) {
            if ($drainPlan->sourcePort !== $port) {
                throw new InvalidArgumentException(sprintf(
                    'Primary remove drain plan moves slots from %d instead of the removed primary %d.',
                    $drainPlan->sourcePort,
                    $port,
                ));
            }
        }

        if ($replicaPorts !== [] && ($replicaRecipientPort === null || $replicaRecipientNodeId === '')) {
            throw new InvalidArgumentException(sprintf(
                'Primary remove must reattach the replicas of primary %d before forgetting it.',
                $port,
            ));
        }
    }

    public function slotCount(): int
    {
        return array_sum(array_map(
            static fn (SlotMigrationPlan $plan): int => $plan->slotCount(),
            $this->drainPlans,
        ));
    }

    /**
     * @return list<int>
     */
    public function destinationPorts(): array
    {
        return array_map(
            static fn (SlotMigrationPlan $plan): int => $plan->destinationPort,
            $this->drainPlans,
        );
    }

    public function describeDrain(): string
    {
        if ($this->drainPlans === []) {
            return 'none';
        }

        return implode(',', array_map(
            static fn (SlotMigrationPlan $plan): string => sprintf('%s->%d', $plan->describeRanges(), $plan->destinationPort),
            $this->drainPlans,
        ));
    }

    public function summary(): string
    {
        return sprintf(
            'primary-remove target=%d slots=%d drain=%s replicas=%s',
            $this->port,
            $this->slotCount(),
            $this->describeDrain(),
            $this->replicaPorts === []
                ? 'none'
                : sprintf('%s->%d', implode('+', array_map('strval', $this->replicaPorts)), $this->replicaRecipientPort),
        );
    }

    public function postcondition(): string
    {
        return sprintf(
            'primary %d is gone from the cluster and its %d slot%s are served by %s',
            $this->port,
            $this->slotCount(),
            $this->slotCount() === 1 ? '' : 's',
            $this->drainPlans === []
                ? 'the remaining primaries'
                : implode(',', array_map('strval', $this->destinationPorts())),
        );
    }
}
