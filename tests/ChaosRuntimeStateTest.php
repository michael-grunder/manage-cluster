<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosEventRecord;
use Mgrunder\CreateCluster\ChaosOptions;
use Mgrunder\CreateCluster\ChaosRuntimeState;
use PHPUnit\Framework\TestCase;

final class ChaosRuntimeStateTest extends TestCase
{
    public function testRecentHistoryKeepsTheTailOfTheRunOldestFirst(): void
    {
        $runtime = $this->runtimeWithFailovers(7000, 7001, 7002, 7003, 7004, 7005, 7006, 7007);

        self::assertSame(
            [7002, 7003, 7004, 7005, 7006, 7007],
            array_map(static fn (ChaosEventRecord $event): ?int => $event->targetPort, $runtime->recentHistory()),
        );
        self::assertSame([7007], array_map(
            static fn (ChaosEventRecord $event): ?int => $event->targetPort,
            $runtime->recentHistory(1),
        ));
        self::assertSame([], $runtime->recentHistory(0));
    }

    public function testRecentEventCountSeesRepeatsThatAlternateBetweenTargets(): void
    {
        // The failover ping-pong that motivated the window: alternating shards
        // means the previous event never repeats, but the last six do.
        $runtime = $this->runtimeWithFailovers(7000, 7001, 7000, 7001, 7000);

        self::assertSame(3, $runtime->recentEventCount(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, 7000));
        self::assertSame(2, $runtime->recentEventCount(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, 7001));
        self::assertSame(5, $runtime->recentEventCount(ChaosOptions::CATEGORY_PRIMARY_FAILOVER));
        self::assertSame(0, $runtime->recentEventCount(ChaosOptions::CATEGORY_SLOT_MIGRATION));
    }

    public function testRecentEventCountForgetsEventsThatLeftTheWindow(): void
    {
        $runtime = $this->runtimeWithFailovers(7000, 7001, 7001, 7001, 7001, 7001, 7001);

        self::assertSame(0, $runtime->recentEventCount(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, 7000));
        self::assertSame(1, $runtime->recentEventCount(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, 7000, window: 7));
    }

    public function testMostRecentMatchingCanBeScopedToTheRecencyWindow(): void
    {
        $runtime = $this->runtimeWithFailovers(7000);
        for ($index = 0; $index < ChaosRuntimeState::RECENCY_WINDOW; $index++) {
            $runtime->rememberHistory($this->event(ChaosOptions::CATEGORY_SLOT_MIGRATION, 7002));
        }

        self::assertInstanceOf(
            ChaosEventRecord::class,
            $runtime->mostRecentMatching(ChaosOptions::CATEGORY_PRIMARY_FAILOVER),
        );
        self::assertNull($runtime->mostRecentMatching(
            ChaosOptions::CATEGORY_PRIMARY_FAILOVER,
            window: ChaosRuntimeState::RECENCY_WINDOW,
        ));
    }

    private function runtimeWithFailovers(int ...$targetPorts): ChaosRuntimeState
    {
        $runtime = new ChaosRuntimeState(
            clusterId: 'test-cluster',
            seedPort: 7000,
            startedAt: 0.0,
            allowedCategories: [ChaosOptions::CATEGORY_PRIMARY_FAILOVER],
        );

        foreach ($targetPorts as $targetPort) {
            $runtime->rememberHistory($this->event(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, $targetPort));
        }

        return $runtime;
    }

    private function event(string $category, int $targetPort): ChaosEventRecord
    {
        return new ChaosEventRecord(
            id: 1,
            category: $category,
            status: 'completed',
            targetPort: $targetPort,
            targetPrimaryPort: null,
            startedAt: 0.0,
            completedAt: 1.0,
            summary: sprintf('%s target=%d', $category, $targetPort),
            postcondition: 'done',
        );
    }
}
