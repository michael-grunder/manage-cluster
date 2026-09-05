<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

/**
 * One replica reparent: move `replicaPort` from `sourcePrimaryPort` to
 * `targetPrimaryPort` with `CLUSTER REPLICATE`.
 *
 * The replica keeps its process, endpoint, and node ID, so a client that
 * cached the donor shard's replica list still reaches a live server that no
 * longer belongs to that shard. The plan records both node IDs so convergence
 * can prove the same node moved instead of a new one appearing at the port.
 */
final readonly class ReplicaReparentPlan
{
    public function __construct(
        public int $replicaPort,
        public string $replicaNodeId,
        public int $sourcePrimaryPort,
        public string $sourcePrimaryNodeId,
        public int $targetPrimaryPort,
        public string $targetPrimaryNodeId,
    ) {
        if ($sourcePrimaryPort === $targetPrimaryPort) {
            throw new InvalidArgumentException(sprintf(
                'Replica reparent source and target are both primary %d.',
                $sourcePrimaryPort,
            ));
        }

        if ($replicaPort === $targetPrimaryPort || $replicaPort === $sourcePrimaryPort) {
            throw new InvalidArgumentException(sprintf('Replica %d cannot replicate itself.', $replicaPort));
        }

        if ($targetPrimaryNodeId === '') {
            throw new InvalidArgumentException(sprintf(
                'Replica reparent needs the node id of target primary %d.',
                $targetPrimaryPort,
            ));
        }
    }

    public function summary(): string
    {
        return sprintf(
            'replica-reparent replica=%d from=%d to=%d',
            $this->replicaPort,
            $this->sourcePrimaryPort,
            $this->targetPrimaryPort,
        );
    }

    public function postcondition(): string
    {
        return sprintf(
            'replica %d keeps its node id and follows primary %d instead of %d',
            $this->replicaPort,
            $this->targetPrimaryPort,
            $this->sourcePrimaryPort,
        );
    }
}
