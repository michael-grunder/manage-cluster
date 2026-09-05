<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

/**
 * Chooses which replicas may be promoted over their primary.
 *
 * The planner is pure: it turns an observed cluster view into zero or more
 * plans and never talks to Redis. A shard qualifies only when the promotion
 * target is a managed, attached, caught-up replica and the primary it would
 * demote is reachable and owns slots, so a normal `CLUSTER FAILOVER` has a
 * realistic chance of completing instead of being rejected or hanging.
 */
final readonly class PrimaryFailoverPlanner
{
    public const int DEFAULT_MAX_REPLICATION_LAG_BYTES = 1_048_576;

    public function __construct(
        private int $maxReplicationLagBytes = self::DEFAULT_MAX_REPLICATION_LAG_BYTES,
    ) {
        if ($maxReplicationLagBytes < 0) {
            throw new InvalidArgumentException('The maximum replication lag for a failover cannot be negative.');
        }
    }

    /**
     * @return list<PrimaryFailoverPlan>
     */
    public function candidates(ChaosClusterView $view): array
    {
        $plans = [];

        foreach ($view->primaryStateByPort as $primaryPort => $primary) {
            if (!$primary->reachable || !$primary->ownsSlots()) {
                continue;
            }

            $primaryState = $view->nodeStateByPort[$primaryPort] ?? null;
            if (!$primaryState instanceof ChaosNodeState || !$this->isDemotablePrimary($primaryState)) {
                continue;
            }

            foreach ($primary->replicaPorts as $replicaPort) {
                $replicaState = $view->nodeStateByPort[$replicaPort] ?? null;
                if (!$replicaState instanceof ChaosNodeState || !$replicaState->isStableManagedReplica()) {
                    continue;
                }

                if ($replicaState->primaryPort !== $primaryPort) {
                    continue;
                }

                $lag = $this->replicationLagBytes($primaryState, $replicaState);
                if ($lag === null || $lag > $this->maxReplicationLagBytes) {
                    continue;
                }

                $plans[] = new PrimaryFailoverPlan(
                    primaryPort: $primaryPort,
                    primaryNodeId: $primary->nodeId,
                    replicaPort: $replicaPort,
                    replicaNodeId: $replicaState->nodeId,
                    ranges: $primary->slotRanges,
                    replicationLagBytes: $lag,
                );
            }
        }

        usort(
            $plans,
            static fn (PrimaryFailoverPlan $left, PrimaryFailoverPlan $right): int
                => [$left->primaryPort, $left->replicaPort] <=> [$right->primaryPort, $right->replicaPort],
        );

        return $plans;
    }

    public function maxReplicationLagBytes(): int
    {
        return $this->maxReplicationLagBytes;
    }

    private function isDemotablePrimary(ChaosNodeState $primary): bool
    {
        return $primary->role === 'primary'
            && $primary->managed
            && $primary->knownByCluster
            && $primary->reachable
            && !$primary->isFailed
            && !$primary->isHandshake
            && !$primary->isLoading
            && !$primary->failoverInProgress;
    }

    /**
     * Replication offsets are the only lag signal both Redis and Valkey report
     * for every node, so an unknown offset disqualifies the shard instead of
     * being treated as caught up.
     */
    private function replicationLagBytes(ChaosNodeState $primary, ChaosNodeState $replica): ?int
    {
        if ($primary->replicationOffset === null || $replica->replicationOffset === null) {
            return null;
        }

        return max(0, $primary->replicationOffset - $replica->replicationOffset);
    }
}
