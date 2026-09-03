<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * Chooses which slots move where for a single chaos slot-migration event.
 *
 * The planner is deliberately pure: it takes the current slot ownership, a
 * strategy, and a per-event slot budget, and returns a plan or null when no
 * legal move exists. All randomness comes from the injected randomizer so runs
 * can be reproduced with a seed.
 */
final readonly class SlotMigrationPlanner
{
    public function __construct(
        private Randomizer $randomizer = new Randomizer(),
    ) {
    }

    /**
     * @param list<PrimarySlotAssignment> $assignments
     */
    public function plan(array $assignments, SlotMigrationStrategy $strategy, int $maxSlots): ?SlotMigrationPlan
    {
        if ($maxSlots < 1) {
            throw new InvalidArgumentException('A slot migration plan must be allowed to move at least one slot.');
        }

        if (count($assignments) < 2) {
            return null;
        }

        return match ($strategy) {
            SlotMigrationStrategy::Balanced => $this->planBalanced($assignments, $maxSlots),
            SlotMigrationStrategy::Random => $this->planRandom($assignments, $maxSlots),
        };
    }

    /**
     * @param list<PrimarySlotAssignment> $assignments
     */
    private function planBalanced(array $assignments, int $maxSlots): ?SlotMigrationPlan
    {
        // A balanced move never drains its source, so the source must be able to
        // give a slot away and still own one.
        $sourceCandidates = array_values(array_filter(
            $assignments,
            static fn (PrimarySlotAssignment $assignment): bool => $assignment->slotCount() >= 2,
        ));

        if ($sourceCandidates === []) {
            return null;
        }

        $source = $this->pickWeighted(
            $sourceCandidates,
            static fn (PrimarySlotAssignment $assignment): int => $assignment->slotCount(),
        );

        $destinationCandidates = $this->otherThan($assignments, $source);
        if ($destinationCandidates === []) {
            return null;
        }

        $heaviest = max(array_map(
            static fn (PrimarySlotAssignment $assignment): int => $assignment->slotCount(),
            $destinationCandidates,
        ));

        $destination = $this->pickWeighted(
            $destinationCandidates,
            static fn (PrimarySlotAssignment $assignment): int => max(1, $heaviest + 1 - $assignment->slotCount()),
        );

        // Weighting makes the heavier node the likely source, but not a certain
        // one; orienting the move keeps a balanced event from ever pushing slots
        // onto the node that already owns more of them.
        if ($destination->slotCount() > $source->slotCount()) {
            [$source, $destination] = [$destination, $source];
        }

        // Halving the gap converges without overshooting into the mirror image
        // of the imbalance we just corrected.
        $halfGap = intdiv($source->slotCount() - $destination->slotCount(), 2);
        $slotCount = max(1, min($maxSlots, $halfGap));
        $slotCount = min($slotCount, $source->slotCount() - 1);

        // Taking from one end keeps each primary's ownership as contiguous as
        // the surrounding topology allows.
        $sourceSlots = $source->slots();
        $slots = $this->randomizer->getInt(0, 1) === 0
            ? array_slice($sourceSlots, 0, $slotCount)
            : array_slice($sourceSlots, -$slotCount);

        return new SlotMigrationPlan(
            strategy: SlotMigrationStrategy::Balanced,
            sourcePort: $source->port,
            sourceNodeId: $source->nodeId,
            destinationPort: $destination->port,
            destinationNodeId: $destination->nodeId,
            ranges: SlotRange::compact($slots),
        );
    }

    /**
     * @param list<PrimarySlotAssignment> $assignments
     */
    private function planRandom(array $assignments, int $maxSlots): ?SlotMigrationPlan
    {
        $sourceCandidates = array_values(array_filter(
            $assignments,
            static fn (PrimarySlotAssignment $assignment): bool => $assignment->ownsSlots(),
        ));

        if ($sourceCandidates === []) {
            return null;
        }

        $source = $sourceCandidates[$this->randomizer->getInt(0, count($sourceCandidates) - 1)];
        $destinationCandidates = $this->otherThan($assignments, $source);
        if ($destinationCandidates === []) {
            return null;
        }

        $destination = $destinationCandidates[$this->randomizer->getInt(0, count($destinationCandidates) - 1)];

        // No balance check at all: a random window anywhere in the source's slot
        // list, which is exactly what fragments ownership over time.
        $sourceSlots = $source->slots();
        $slotCount = $this->randomizer->getInt(1, min($maxSlots, count($sourceSlots)));
        $offset = $this->randomizer->getInt(0, count($sourceSlots) - $slotCount);
        $slots = array_slice($sourceSlots, $offset, $slotCount);

        return new SlotMigrationPlan(
            strategy: SlotMigrationStrategy::Random,
            sourcePort: $source->port,
            sourceNodeId: $source->nodeId,
            destinationPort: $destination->port,
            destinationNodeId: $destination->nodeId,
            ranges: SlotRange::compact($slots),
        );
    }

    /**
     * @param list<PrimarySlotAssignment> $assignments
     * @return list<PrimarySlotAssignment>
     */
    private function otherThan(array $assignments, PrimarySlotAssignment $exclude): array
    {
        return array_values(array_filter(
            $assignments,
            static fn (PrimarySlotAssignment $assignment): bool => $assignment->port !== $exclude->port,
        ));
    }

    /**
     * @param non-empty-list<PrimarySlotAssignment> $assignments
     * @param callable(PrimarySlotAssignment): int $weigh
     */
    private function pickWeighted(array $assignments, callable $weigh): PrimarySlotAssignment
    {
        $weights = [];
        $total = 0;
        foreach ($assignments as $index => $assignment) {
            $weight = max(1, $weigh($assignment));
            $weights[$index] = $weight;
            $total += $weight;
        }

        $ticket = $this->randomizer->getInt(1, $total);
        foreach ($assignments as $index => $assignment) {
            $ticket -= $weights[$index];
            if ($ticket <= 0) {
                return $assignment;
            }
        }

        return $assignments[count($assignments) - 1];
    }
}
