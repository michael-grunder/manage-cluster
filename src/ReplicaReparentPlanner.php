<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Chooses which live replica could follow a different primary.
 *
 * The planner is pure: it turns an observed cluster view into zero or more
 * plans and never talks to Redis. A donor shard only gives up a replica while
 * it keeps a spare healthy one, and the recipient must be a reachable primary
 * that actually serves slots, so the move stays a routing experiment rather
 * than a redundancy cut.
 */
final readonly class ReplicaReparentPlanner
{
    /**
     * @return list<ReplicaReparentPlan>
     */
    public function candidates(ChaosClusterView $view, bool $allowDrainingDonor = false): array
    {
        $plans = [];

        foreach ($view->nodeStateByPort as $replicaPort => $replica) {
            if (!$replica->isStableManagedReplica() || $replica->primaryPort === null) {
                continue;
            }

            $source = $view->primaryStateByPort[$replica->primaryPort] ?? null;
            if (!$source instanceof ChaosPrimaryState || !$source->reachable) {
                continue;
            }

            // Moving the donor's last healthy replica leaves it degraded, which
            // is only allowed when the operator opted into --unsafe.
            if (!$allowDrainingDonor && $source->healthyReplicaCount < 2) {
                continue;
            }

            foreach ($view->primaryStateByPort as $targetPort => $target) {
                if ($targetPort === $source->port || !$this->isAttachableTarget($view, $target)) {
                    continue;
                }

                $plans[] = new ReplicaReparentPlan(
                    replicaPort: $replicaPort,
                    replicaNodeId: $replica->nodeId,
                    sourcePrimaryPort: $source->port,
                    sourcePrimaryNodeId: $source->nodeId,
                    targetPrimaryPort: $targetPort,
                    targetPrimaryNodeId: $target->nodeId,
                );
            }
        }

        usort(
            $plans,
            static fn (ReplicaReparentPlan $left, ReplicaReparentPlan $right): int
                => [$left->replicaPort, $left->targetPrimaryPort] <=> [$right->replicaPort, $right->targetPrimaryPort],
        );

        return $plans;
    }

    /**
     * `CLUSTER REPLICATE` needs a primary the replica already knows, and a
     * recipient that owns no slots would move the replica out of the keyspace
     * clients actually read from.
     */
    private function isAttachableTarget(ChaosClusterView $view, ChaosPrimaryState $target): bool
    {
        if (!$target->reachable || !$target->ownsSlots() || $target->nodeId === '') {
            return false;
        }

        $node = $view->nodeStateByPort[$target->port] ?? null;

        return $node instanceof ChaosNodeState && $node->isSettledPrimary();
    }
}
