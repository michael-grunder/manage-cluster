<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosCandidateEvent;
use Mgrunder\CreateCluster\ChaosEventRecord;
use Mgrunder\CreateCluster\ChaosOptions;
use Mgrunder\CreateCluster\PrimaryAddPlan;
use Mgrunder\CreateCluster\PrimaryFailoverPlan;
use Mgrunder\CreateCluster\PrimaryRemovePlan;
use Mgrunder\CreateCluster\ReplicaReparentPlan;
use Mgrunder\CreateCluster\SlotMigrationPlan;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChaosEventRecordTest extends TestCase
{
    /**
     * Every plan-carrying category must reach execution with its plan attached;
     * dropping one aborts the run with "<category> is missing a ... plan".
     */
    #[DataProvider('planCarryingCandidates')]
    public function testFromCandidateCarriesThePlanForEveryCategory(
        ChaosCandidateEvent $candidate,
        string $planProperty,
    ): void {
        $record = ChaosEventRecord::fromCandidate(7, $candidate, 1234.5);

        self::assertSame($candidate->$planProperty, $record->$planProperty);
        self::assertSame($candidate->category, $record->category);
        self::assertSame(7, $record->id);
        self::assertSame('planned', $record->status);
        self::assertSame(1234.5, $record->startedAt);
        self::assertNull($record->completedAt);
    }

    #[DataProvider('planCarryingCandidates')]
    public function testWithStatusKeepsThePlanForEveryCategory(
        ChaosCandidateEvent $candidate,
        string $planProperty,
    ): void {
        $record = ChaosEventRecord::fromCandidate(7, $candidate, 1234.5)->withStatus('running');

        self::assertSame($candidate->$planProperty, $record->$planProperty);
        self::assertSame('running', $record->status);
    }

    /**
     * @return iterable<string, array{ChaosCandidateEvent, string}>
     */
    public static function planCarryingCandidates(): iterable
    {
        $ranges = [new SlotRange(0, 100)];

        $slotMigration = new SlotMigrationPlan(
            sourcePort: 7000,
            sourceNodeId: 'source-node',
            destinationPort: 7001,
            destinationNodeId: 'destination-node',
            ranges: $ranges,
        );

        yield 'slot-migration' => [
            self::candidate(ChaosOptions::CATEGORY_SLOT_MIGRATION, slotMigrationPlan: $slotMigration),
            'slotMigrationPlan',
        ];

        yield 'primary-failover' => [
            self::candidate(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, primaryFailoverPlan: new PrimaryFailoverPlan(
                primaryPort: 7000,
                primaryNodeId: 'primary-node',
                replicaPort: 7003,
                replicaNodeId: 'replica-node',
                ranges: $ranges,
            )),
            'primaryFailoverPlan',
        ];

        yield 'replica-reparent' => [
            self::candidate(ChaosOptions::CATEGORY_REPLICA_REPARENT, replicaReparentPlan: new ReplicaReparentPlan(
                replicaPort: 7003,
                replicaNodeId: 'replica-node',
                sourcePrimaryPort: 7000,
                sourcePrimaryNodeId: 'source-node',
                targetPrimaryPort: 7001,
                targetPrimaryNodeId: 'target-node',
            )),
            'replicaReparentPlan',
        ];

        yield 'primary-add' => [
            self::candidate(ChaosOptions::CATEGORY_PRIMARY_ADD, primaryAddPlan: new PrimaryAddPlan(
                newPrimaryPort: 7009,
                donorPort: 7000,
                donorNodeId: 'donor-node',
                ranges: $ranges,
            )),
            'primaryAddPlan',
        ];

        yield 'primary-remove' => [
            self::candidate(ChaosOptions::CATEGORY_PRIMARY_REMOVE, primaryRemovePlan: new PrimaryRemovePlan(
                port: 7000,
                nodeId: 'source-node',
                drainPlans: [$slotMigration],
            )),
            'primaryRemovePlan',
        ];
    }

    private static function candidate(
        string $category,
        ?SlotMigrationPlan $slotMigrationPlan = null,
        ?PrimaryFailoverPlan $primaryFailoverPlan = null,
        ?ReplicaReparentPlan $replicaReparentPlan = null,
        ?PrimaryAddPlan $primaryAddPlan = null,
        ?PrimaryRemovePlan $primaryRemovePlan = null,
    ): ChaosCandidateEvent {
        return new ChaosCandidateEvent(
            category: $category,
            targetPort: 7003,
            targetPrimaryPort: 7000,
            score: 10,
            summary: $category . ' summary',
            postcondition: $category . ' postcondition',
            reasons: ['because'],
            slotMigrationPlan: $slotMigrationPlan,
            primaryFailoverPlan: $primaryFailoverPlan,
            replicaReparentPlan: $replicaReparentPlan,
            primaryAddPlan: $primaryAddPlan,
            primaryRemovePlan: $primaryRemovePlan,
        );
    }
}
