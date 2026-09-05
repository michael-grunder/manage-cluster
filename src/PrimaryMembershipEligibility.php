<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Cluster-wide preconditions for growing or shrinking the primary inventory.
 *
 * Both directions rewrite slot ownership and cluster membership, so they need a
 * cluster that is currently serving its whole keyspace from a normal number of
 * primaries. The per-event rules — which primary donates slots, which one can
 * be drained and forgotten — live in the two planners.
 */
final readonly class PrimaryMembershipEligibility
{
    public const int MINIMUM_SLOT_OWNING_PRIMARIES = 3;

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

        // Membership churn is the one family that also refuses to start while a
        // managed replica is missing: the removal path reattaches replicas, and
        // a replica that is down cannot be moved out of the way.
        $downReplicas = [];
        foreach ($view->nodeStateByPort as $port => $node) {
            if ($node->role === 'replica' && $node->knownByCluster && (!$node->reachable || $node->isFailed)) {
                $downReplicas[] = $port;
            }
        }

        if ($downReplicas !== []) {
            sort($downReplicas, SORT_NUMERIC);
            $blockers[] = sprintf('down replicas=%s', implode(',', array_map('strval', $downReplicas)));
        }

        return [...$blockers, ...ChaosSettlementBlockers::unsettledMembership($view)];
    }
}
