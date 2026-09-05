<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Chooses which primary could leave the cluster, and how its keyspace and
 * replicas are handed over before it does.
 *
 * The planner is pure. It refuses to plan a removal that would leave fewer than
 * three slot-owning primaries, never removes the seed the runner discovers the
 * cluster through, and always produces a complete drain: every slot the victim
 * owns is assigned to a surviving primary before the node is forgotten.
 */
final readonly class PrimaryRemovePlanner
{
    public const int MINIMUM_REMAINING_SLOT_OWNERS = 3;

    /**
     * @return list<PrimaryRemovePlan>
     */
    public function candidates(ChaosClusterView $view, int $seedPort): array
    {
        $plans = [];

        foreach ($view->primaryStateByPort as $victimPort => $victim) {
            if ($victimPort === $seedPort || $victim->nodeId === '') {
                continue;
            }

            $victimNode = $view->nodeStateByPort[$victimPort] ?? null;
            if (!$victimNode instanceof ChaosNodeState || !$victimNode->managed || !$victimNode->isSettledPrimary()) {
                continue;
            }

            $survivors = $this->survivors($view, $victimPort);
            if ($survivors === []) {
                continue;
            }

            $drainPlans = $this->planDrain($victim, $survivors);
            if (!$this->keepsEnoughSlotOwners($survivors, $drainPlans)) {
                continue;
            }

            $replicaPorts = $this->movableReplicaPorts($view, $victim);
            if (count($replicaPorts) !== count($victim->replicaPorts)) {
                // A replica that cannot be reattached would be stranded when the
                // cluster forgets its primary, so the whole removal waits.
                continue;
            }

            $replicaRecipient = $this->selectReplicaRecipient($drainPlans, $survivors);

            $plans[] = new PrimaryRemovePlan(
                port: $victimPort,
                nodeId: $victim->nodeId,
                drainPlans: $drainPlans,
                replicaPorts: $replicaPorts,
                replicaRecipientPort: $replicaPorts === [] ? null : $replicaRecipient->port,
                replicaRecipientNodeId: $replicaPorts === [] ? '' : $replicaRecipient->nodeId,
            );
        }

        usort(
            $plans,
            static fn (PrimaryRemovePlan $left, PrimaryRemovePlan $right): int => $left->port <=> $right->port,
        );

        return $plans;
    }

    /**
     * @return list<ChaosPrimaryState>
     */
    private function survivors(ChaosClusterView $view, int $victimPort): array
    {
        $survivors = [];
        foreach ($view->primaryStateByPort as $port => $primary) {
            if ($port === $victimPort || $primary->nodeId === '') {
                continue;
            }

            $node = $view->nodeStateByPort[$port] ?? null;
            if ($node instanceof ChaosNodeState && $node->isSettledPrimary()) {
                $survivors[$port] = $primary;
            }
        }

        // Drain shares follow ascending port order so a plan is reproducible
        // regardless of the order the topology was discovered in.
        ksort($survivors, SORT_NUMERIC);

        return array_values($survivors);
    }

    /**
     * Spread the victim's slots over the survivors in contiguous chunks, so the
     * cluster stays as unfragmented as the existing ownership allows.
     *
     * @param list<ChaosPrimaryState> $survivors
     * @return list<SlotMigrationPlan>
     */
    private function planDrain(ChaosPrimaryState $victim, array $survivors): array
    {
        $slots = SlotRange::expand($victim->slotRanges);
        if ($slots === []) {
            return [];
        }

        $shareCount = min(count($survivors), count($slots));
        $plans = [];
        $offset = 0;

        for ($index = 0; $index < $shareCount; $index++) {
            $remainingShares = $shareCount - $index;
            $share = (int) ceil((count($slots) - $offset) / $remainingShares);
            $chunk = array_slice($slots, $offset, $share);
            $offset += $share;

            $destination = $survivors[$index];
            $plans[] = new SlotMigrationPlan(
                sourcePort: $victim->port,
                sourceNodeId: $victim->nodeId,
                destinationPort: $destination->port,
                destinationNodeId: $destination->nodeId,
                ranges: SlotRange::compact($chunk),
            );
        }

        return $plans;
    }

    /**
     * @param list<ChaosPrimaryState> $survivors
     * @param list<SlotMigrationPlan> $drainPlans
     */
    private function keepsEnoughSlotOwners(array $survivors, array $drainPlans): bool
    {
        $receiving = array_fill_keys(
            array_map(static fn (SlotMigrationPlan $plan): int => $plan->destinationPort, $drainPlans),
            true,
        );

        $slotOwners = 0;
        foreach ($survivors as $survivor) {
            if ($survivor->ownsSlots() || isset($receiving[$survivor->port])) {
                $slotOwners++;
            }
        }

        return $slotOwners >= self::MINIMUM_REMAINING_SLOT_OWNERS;
    }

    /**
     * @return list<int>
     */
    private function movableReplicaPorts(ChaosClusterView $view, ChaosPrimaryState $victim): array
    {
        $ports = [];
        foreach ($victim->replicaPorts as $replicaPort) {
            $replica = $view->nodeStateByPort[$replicaPort] ?? null;
            if ($replica instanceof ChaosNodeState && $replica->isStableManagedReplica()) {
                $ports[] = $replicaPort;
            }
        }

        return $ports;
    }

    /**
     * Reattach orphaned replicas where the keyspace went, so the shard that
     * grew also gains the redundancy.
     *
     * @param list<SlotMigrationPlan> $drainPlans
     * @param list<ChaosPrimaryState> $survivors
     */
    private function selectReplicaRecipient(array $drainPlans, array $survivors): ChaosPrimaryState
    {
        $largest = null;
        foreach ($drainPlans as $plan) {
            if (!$largest instanceof SlotMigrationPlan || $plan->slotCount() > $largest->slotCount()) {
                $largest = $plan;
            }
        }

        if ($largest instanceof SlotMigrationPlan) {
            foreach ($survivors as $survivor) {
                if ($survivor->port === $largest->destinationPort) {
                    return $survivor;
                }
            }
        }

        $fewestReplicas = $survivors[0];
        foreach ($survivors as $survivor) {
            if ($survivor->healthyReplicaCount < $fewestReplicas->healthyReplicaCount) {
                $fewestReplicas = $survivor;
            }
        }

        return $fewestReplicas;
    }
}
