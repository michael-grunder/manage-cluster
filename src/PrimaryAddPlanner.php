<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

/**
 * Chooses which primary donates slots to a brand new primary.
 *
 * The planner is pure: the caller has already found a free port, and the plan
 * only says where the new shard's slots come from. The donor is the primary
 * that owns the most, so growing the cluster evens ownership out instead of
 * carving a shard out of an already small one, and it never hands over
 * everything it has.
 */
final readonly class PrimaryAddPlanner
{
    public function plan(ChaosClusterView $view, int $newPrimaryPort, int $maxSlots): ?PrimaryAddPlan
    {
        if ($maxSlots < 1) {
            throw new InvalidArgumentException('A primary add plan must be allowed to move at least one slot.');
        }

        $donor = $this->selectDonor($view, $newPrimaryPort);
        if (!$donor instanceof ChaosPrimaryState) {
            return null;
        }

        // Never drain the donor: it keeps at least half of what it owns.
        $slotCount = min($maxSlots, intdiv($donor->slotCount(), 2));
        if ($slotCount < 1) {
            return null;
        }

        $slots = array_slice(SlotRange::expand($donor->slotRanges), -$slotCount);

        return new PrimaryAddPlan(
            newPrimaryPort: $newPrimaryPort,
            donorPort: $donor->port,
            donorNodeId: $donor->nodeId,
            ranges: SlotRange::compact($slots),
        );
    }

    private function selectDonor(ChaosClusterView $view, int $newPrimaryPort): ?ChaosPrimaryState
    {
        $donor = null;
        foreach ($view->primaryStateByPort as $port => $primary) {
            if ($port === $newPrimaryPort || $primary->nodeId === '' || $primary->slotCount() < 2) {
                continue;
            }

            $node = $view->nodeStateByPort[$port] ?? null;
            if (!$node instanceof ChaosNodeState || !$node->isSettledPrimary()) {
                continue;
            }

            if (!$donor instanceof ChaosPrimaryState || $primary->slotCount() > $donor->slotCount()) {
                $donor = $primary;
            }
        }

        return $donor;
    }
}
