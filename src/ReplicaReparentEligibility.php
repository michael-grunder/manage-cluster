<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Cluster-wide preconditions for moving a live replica between shards.
 *
 * Reparenting keeps every process running, so like primary failover it does not
 * demand a fully settled cluster: a replica chaos killed in another shard is no
 * reason to refuse. What it does need is a second shard to move into, and a
 * cluster that is not already reorganizing itself.
 */
final readonly class ReplicaReparentEligibility
{
    public const int MINIMUM_SLOT_OWNING_PRIMARIES = 2;

    /**
     * @return list<string>
     */
    public function blockers(ChaosClusterView $view, bool $unsafe): array
    {
        if ($view->clusterDown) {
            return ['cluster is down'];
        }

        $blockers = [];
        $slotOwningPrimaries = 0;
        foreach ($view->primaryStateByPort as $primary) {
            if ($primary->reachable && $primary->ownsSlots()) {
                $slotOwningPrimaries++;
            }
        }

        if ($slotOwningPrimaries < self::MINIMUM_SLOT_OWNING_PRIMARIES) {
            $blockers[] = sprintf(
                'needs at least %d reachable slot-owning primaries, found %d',
                self::MINIMUM_SLOT_OWNING_PRIMARIES,
                $slotOwningPrimaries,
            );
        }

        if ($unsafe) {
            return $blockers;
        }

        return [...$blockers, ...ChaosSettlementBlockers::unsettledMembership($view)];
    }
}
