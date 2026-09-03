<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\PrimarySlotAssignment;
use Mgrunder\CreateCluster\SlotMigrationPlan;
use Mgrunder\CreateCluster\SlotMigrationPlanner;
use Mgrunder\CreateCluster\SlotMigrationStrategy;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class SlotMigrationPlannerTest extends TestCase
{
    #[DataProvider('strategyProvider')]
    public function testReturnsNullWhenFewerThanTwoPrimariesExist(SlotMigrationStrategy $strategy): void
    {
        $planner = self::seededPlanner();

        self::assertNull($planner->plan([], $strategy, 16));
        self::assertNull($planner->plan([self::assignment(7000, [new SlotRange(0, 16383)])], $strategy, 16));
    }

    #[DataProvider('strategyProvider')]
    public function testRejectsAZeroSlotBudget(SlotMigrationStrategy $strategy): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A slot migration plan must be allowed to move at least one slot.');

        self::seededPlanner()->plan(self::evenTopology(), $strategy, 0);
    }

    #[DataProvider('strategyProvider')]
    public function testPlansNeverExceedTheSlotBudgetOrLeaveTheSource(SlotMigrationStrategy $strategy): void
    {
        $planner = self::seededPlanner();
        $assignments = self::unevenTopology();

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $plan = $planner->plan($assignments, $strategy, 7);

            self::assertInstanceOf(SlotMigrationPlan::class, $plan);
            self::assertSame($strategy, $plan->strategy);
            self::assertGreaterThanOrEqual(1, $plan->slotCount());
            self::assertLessThanOrEqual(7, $plan->slotCount());
            self::assertNotSame($plan->sourcePort, $plan->destinationPort);

            $source = self::assignmentByPort($assignments, $plan->sourcePort);
            foreach ($plan->slots() as $slot) {
                self::assertTrue(
                    SlotRange::containsSlot($source->ranges, $slot),
                    sprintf('slot %d is not owned by the source', $slot),
                );
            }
        }
    }

    /**
     * @return iterable<string, array{strategy: SlotMigrationStrategy}>
     */
    public static function strategyProvider(): iterable
    {
        yield 'balanced' => ['strategy' => SlotMigrationStrategy::Balanced];
        yield 'random' => ['strategy' => SlotMigrationStrategy::Random];
    }

    public function testBalancedMovesHalfTheGapAsOneContiguousRange(): void
    {
        $planner = self::seededPlanner();
        $assignments = [
            self::assignment(7000, [new SlotRange(0, 999)]),
            self::assignment(7001, []),
            self::assignment(7002, []),
        ];

        $plan = $planner->plan($assignments, SlotMigrationStrategy::Balanced, 1_000);

        self::assertInstanceOf(SlotMigrationPlan::class, $plan);
        self::assertSame(7000, $plan->sourcePort);
        self::assertContains($plan->destinationPort, [7001, 7002]);
        self::assertSame(500, $plan->slotCount());
        self::assertCount(1, $plan->ranges, 'a balanced move is taken from one end of the source');
    }

    public function testBalancedNeverDrainsItsSource(): void
    {
        $planner = self::seededPlanner();
        $assignments = [
            self::assignment(7000, [new SlotRange(0, 1)]),
            self::assignment(7001, [new SlotRange(2, 16383)]),
        ];

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $plan = $planner->plan($assignments, SlotMigrationStrategy::Balanced, 16);

            self::assertInstanceOf(SlotMigrationPlan::class, $plan);
            $source = self::assignmentByPort($assignments, $plan->sourcePort);
            self::assertLessThan(
                $source->slotCount(),
                $plan->slotCount(),
                'a balanced move must leave the source owning at least one slot',
            );
        }
    }

    public function testBalancedReturnsNullWhenNoPrimaryCanSpareASlot(): void
    {
        $assignments = [
            self::assignment(7000, [new SlotRange(0, 0)]),
            self::assignment(7001, [new SlotRange(1, 1)]),
            self::assignment(7002, []),
        ];

        self::assertNull(self::seededPlanner()->plan($assignments, SlotMigrationStrategy::Balanced, 16));
    }

    public function testBalancedPrefersLoadedSourcesAndEmptyDestinations(): void
    {
        $planner = self::seededPlanner();
        $assignments = self::unevenTopology();
        $sources = [];
        $destinations = [];

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $plan = $planner->plan($assignments, SlotMigrationStrategy::Balanced, 16);
            self::assertInstanceOf(SlotMigrationPlan::class, $plan);

            $sources[$plan->sourcePort] = ($sources[$plan->sourcePort] ?? 0) + 1;
            $destinations[$plan->destinationPort] = ($destinations[$plan->destinationPort] ?? 0) + 1;
        }

        // 7000 owns almost every slot, 7002 owns the fewest.
        self::assertGreaterThan(175, $sources[7000] ?? 0);
        self::assertGreaterThan($destinations[7001] ?? 0, $destinations[7002] ?? 0);
        self::assertLessThan(5, $destinations[7000] ?? 0);
    }

    public function testBalancedNeverMovesSlotsOntoTheHeavierPrimary(): void
    {
        $planner = self::seededPlanner();
        $assignments = self::unevenTopology();

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $plan = $planner->plan($assignments, SlotMigrationStrategy::Balanced, 16);
            self::assertInstanceOf(SlotMigrationPlan::class, $plan);

            $source = self::assignmentByPort($assignments, $plan->sourcePort);
            $destination = self::assignmentByPort($assignments, $plan->destinationPort);

            self::assertGreaterThanOrEqual(
                $destination->slotCount(),
                $source->slotCount(),
                'a balanced move must run from the heavier primary to the lighter one',
            );
        }
    }

    public function testBalancedMigrationsConvergeTowardAnEvenDistribution(): void
    {
        $planner = self::seededPlanner();
        $assignments = [
            self::assignment(7000, [new SlotRange(0, 16383)]),
            self::assignment(7001, []),
            self::assignment(7002, []),
        ];

        for ($round = 0; $round < 60; $round++) {
            $plan = $planner->plan($assignments, SlotMigrationStrategy::Balanced, 1_024);
            self::assertInstanceOf(SlotMigrationPlan::class, $plan);
            $assignments = self::applyPlan($assignments, $plan);
        }

        self::assertSame(SlotRange::TOTAL_SLOTS, array_sum(self::slotCounts($assignments)), 'slots are conserved');
        self::assertLessThan(
            1_500,
            self::slotSpread($assignments),
            'repeated balanced migrations should even out slot ownership',
        );
    }

    public function testRandomIgnoresTheCurrentDistributionWhenChoosingNodes(): void
    {
        $planner = self::seededPlanner();
        $assignments = self::unevenTopology();
        $sources = [];
        $destinations = [];

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $plan = $planner->plan($assignments, SlotMigrationStrategy::Random, 16);
            self::assertInstanceOf(SlotMigrationPlan::class, $plan);

            $sources[$plan->sourcePort] = ($sources[$plan->sourcePort] ?? 0) + 1;
            $destinations[$plan->destinationPort] = ($destinations[$plan->destinationPort] ?? 0) + 1;
        }

        // Every primary is picked as a source and destination roughly equally
        // often even though 7000 owns almost every slot.
        foreach ([7000, 7001, 7002] as $port) {
            self::assertGreaterThan(40, $sources[$port] ?? 0);
            self::assertGreaterThan(40, $destinations[$port] ?? 0);
        }
    }

    public function testRandomMayDrainAPrimaryCompletely(): void
    {
        $planner = self::seededPlanner();
        $assignments = [
            self::assignment(7000, [new SlotRange(0, 3)]),
            self::assignment(7001, [new SlotRange(4, 16383)]),
        ];

        $drained = false;
        for ($attempt = 0; $attempt < 100 && !$drained; $attempt++) {
            $plan = $planner->plan($assignments, SlotMigrationStrategy::Random, 16);
            self::assertInstanceOf(SlotMigrationPlan::class, $plan);

            $source = self::assignmentByPort($assignments, $plan->sourcePort);
            $drained = $plan->slotCount() === $source->slotCount();
        }

        self::assertTrue($drained, 'the random strategy is allowed to hand over every slot a primary owns');
    }

    public function testRandomMigrationsFragmentSlotOwnership(): void
    {
        $planner = self::seededPlanner();
        $assignments = self::evenTopology();

        for ($round = 0; $round < 40; $round++) {
            $plan = $planner->plan($assignments, SlotMigrationStrategy::Random, 64);
            self::assertInstanceOf(SlotMigrationPlan::class, $plan);
            $assignments = self::applyPlan($assignments, $plan);
        }

        $rangeCount = array_sum(array_map(
            static fn (PrimarySlotAssignment $assignment): int => count($assignment->ranges),
            $assignments,
        ));

        self::assertSame(SlotRange::TOTAL_SLOTS, array_sum(self::slotCounts($assignments)), 'slots are conserved');
        self::assertGreaterThan(
            count($assignments),
            $rangeCount,
            'random migrations should break contiguous ownership apart',
        );
    }

    private static function seededPlanner(int $seed = 20260903): SlotMigrationPlanner
    {
        return new SlotMigrationPlanner(new Randomizer(new Mt19937($seed)));
    }

    /**
     * @param list<SlotRange> $ranges
     */
    private static function assignment(int $port, array $ranges): PrimarySlotAssignment
    {
        return new PrimarySlotAssignment(
            port: $port,
            nodeId: str_pad((string) $port, 40, 'a', STR_PAD_LEFT),
            ranges: $ranges,
        );
    }

    /**
     * @return list<PrimarySlotAssignment>
     */
    private static function evenTopology(): array
    {
        return [
            self::assignment(7000, [new SlotRange(0, 5460)]),
            self::assignment(7001, [new SlotRange(5461, 10922)]),
            self::assignment(7002, [new SlotRange(10923, 16383)]),
        ];
    }

    /**
     * @return list<PrimarySlotAssignment>
     */
    private static function unevenTopology(): array
    {
        return [
            self::assignment(7000, [new SlotRange(0, 16000)]),
            self::assignment(7001, [new SlotRange(16001, 16300)]),
            self::assignment(7002, [new SlotRange(16301, 16383)]),
        ];
    }

    /**
     * @param list<PrimarySlotAssignment> $assignments
     * @return list<PrimarySlotAssignment>
     */
    private static function applyPlan(array $assignments, SlotMigrationPlan $plan): array
    {
        $moved = array_fill_keys($plan->slots(), true);
        $updated = [];

        foreach ($assignments as $assignment) {
            $slots = $assignment->slots();

            if ($assignment->port === $plan->sourcePort) {
                $slots = array_values(array_filter(
                    $slots,
                    static fn (int $slot): bool => !isset($moved[$slot]),
                ));
            }

            if ($assignment->port === $plan->destinationPort) {
                $slots = [...$slots, ...$plan->slots()];
            }

            $updated[] = self::assignment($assignment->port, SlotRange::compact($slots));
        }

        return $updated;
    }

    /**
     * @param list<PrimarySlotAssignment> $assignments
     * @return array<int, int>
     */
    private static function slotCounts(array $assignments): array
    {
        $counts = [];
        foreach ($assignments as $assignment) {
            $counts[$assignment->port] = $assignment->slotCount();
        }

        return $counts;
    }

    /**
     * @param list<PrimarySlotAssignment> $assignments
     */
    private static function slotSpread(array $assignments): int
    {
        $counts = self::slotCounts($assignments);
        if ($counts === []) {
            return 0;
        }

        return max($counts) - min($counts);
    }

    /**
     * @param list<PrimarySlotAssignment> $assignments
     */
    private static function assignmentByPort(array $assignments, int $port): PrimarySlotAssignment
    {
        foreach ($assignments as $assignment) {
            if ($assignment->port === $port) {
                return $assignment;
            }
        }

        self::fail(sprintf('No assignment for port %d', $port));
    }
}
