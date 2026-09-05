<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests\Integration;

use Mgrunder\CreateCluster\ChaosOptions;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Drives each opt-in chaos category against a real cluster.
 *
 * These cover the ground unit tests structurally cannot: that a planned event
 * survives the trip from planner to executor and that the cluster it acts on
 * ends up in the shape the plan promised. The regression that motivated the
 * suite - every category except slot-migration losing its plan between
 * selection and execution - passed every unit test.
 */
#[Group('integration')]
#[RequiresPhpExtension('redis')]
final class ChaosCategoryTest extends TestCase
{
    private ?ManagedCluster $cluster = null;

    protected function tearDown(): void
    {
        $this->cluster?->shutdown();
        $this->cluster = null;
    }

    public function testReplicaReparentMovesALiveReplicaToAnotherPrimary(): void
    {
        $cluster = $this->cluster();
        $before = $cluster->replicaPortsByPrimaryPort();

        $cluster->runChaosEvent(ChaosOptions::CATEGORY_REPLICA_REPARENT);

        $after = $cluster->replicaPortsByPrimaryPort();

        self::assertNotSame($before, $after, 'replica-reparent left the replica layout unchanged.');
        self::assertSame(
            array_keys($before),
            array_keys($after),
            'replica-reparent must move a replica without changing which primaries exist.',
        );
        self::assertSame(
            self::allReplicaPorts($before),
            self::allReplicaPorts($after),
            'replica-reparent must reuse the same replica processes, not create or drop any.',
        );
        self::assertSame(SlotRange::TOTAL_SLOTS, $cluster->coveredSlotCount());
    }

    public function testPrimaryFailoverPromotesAReplicaOverItsPrimary(): void
    {
        $cluster = $this->cluster();
        $before = $cluster->slotOwningPrimaryPorts();

        $cluster->runChaosEvent(ChaosOptions::CATEGORY_PRIMARY_FAILOVER);

        $after = $cluster->slotOwningPrimaryPorts();

        self::assertNotSame($before, $after, 'primary-failover did not change which node leads a shard.');
        self::assertCount(count($before), $after, 'primary-failover must swap a primary, not add or drop one.');
        self::assertSame(SlotRange::TOTAL_SLOTS, $cluster->coveredSlotCount());
    }

    public function testPrimaryAddJoinsAPrimaryAndMigratesSlotsIntoIt(): void
    {
        $cluster = $this->cluster();
        $before = $cluster->slotOwningPrimaryPorts();

        $cluster->runChaosEvent(ChaosOptions::CATEGORY_PRIMARY_ADD);

        $after = $cluster->slotOwningPrimaryPorts();

        self::assertCount(count($before) + 1, $after, 'primary-add did not add a slot-owning primary.');
        self::assertNotEmpty(array_diff($after, $before), 'primary-add did not introduce a new port.');
        self::assertSame(
            SlotRange::TOTAL_SLOTS,
            $cluster->coveredSlotCount(),
            'primary-add must migrate slots into the new primary without dropping coverage.',
        );
    }

    public function testPrimaryRemoveDrainsAndForgetsAPrimary(): void
    {
        $cluster = $this->cluster();
        $before = $cluster->slotOwningPrimaryPorts();

        $cluster->runChaosEvent(ChaosOptions::CATEGORY_PRIMARY_REMOVE);

        $after = $cluster->slotOwningPrimaryPorts();

        self::assertCount(count($before) - 1, $after, 'primary-remove did not drop a slot-owning primary.');
        self::assertNotEmpty(array_diff($before, $after), 'primary-remove did not retire a port.');
        self::assertSame(
            SlotRange::TOTAL_SLOTS,
            $cluster->coveredSlotCount(),
            'primary-remove must drain every slot before forgetting the node.',
        );
    }

    public function testSlotMigrationMovesSlotsWithoutLosingCoverage(): void
    {
        $cluster = $this->cluster();

        $cluster->runChaosEvent(ChaosOptions::CATEGORY_SLOT_MIGRATION);

        self::assertSame(SlotRange::TOTAL_SLOTS, $cluster->coveredSlotCount());
        self::assertNotEmpty($cluster->slotOwningPrimaryPorts());
    }

    /**
     * Four shards with two replicas each, which is the smallest cluster every
     * category under test can actually plan an event against:
     *
     * - primary-remove must leave three slot-owning primaries behind, so three
     *   shards would never yield a candidate.
     * - ReplicaReparentPlanner refuses to strip a donor primary's last healthy
     *   replica, so one replica per primary would never yield one either.
     *
     * Undersizing either dimension makes the run wait for an event that cannot
     * be planned rather than fail with something readable.
     */
    private function cluster(): ManagedCluster
    {
        return $this->cluster ??= ManagedCluster::acquire(primaries: 4, replicasPerPrimary: 2);
    }

    /**
     * @param array<int, list<int>> $topology
     * @return list<int>
     */
    private static function allReplicaPorts(array $topology): array
    {
        $ports = $topology === [] ? [] : array_merge(...array_values($topology));
        sort($ports);

        return $ports;
    }
}
