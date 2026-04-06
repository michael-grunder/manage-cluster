<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\FillProgressEstimator;
use PHPUnit\Framework\TestCase;

final class FillProgressEstimatorTest extends TestCase
{
    public function testWaitsForFirstObservedProgressBeforeEstimating(): void
    {
        $estimator = new FillProgressEstimator(startUsedBytes: 100, targetUsedBytes: 1_100);

        self::assertNull($estimator->estimateRemainingSeconds(currentUsedBytes: 100, elapsedSeconds: 1.0));
        self::assertSame(18, $estimator->estimateRemainingSeconds(currentUsedBytes: 200, elapsedSeconds: 2.0));
    }

    public function testIncorporatesSubsequentTicksIntoRateEstimate(): void
    {
        $estimator = new FillProgressEstimator(startUsedBytes: 100, targetUsedBytes: 1_100);

        $estimator->estimateRemainingSeconds(currentUsedBytes: 200, elapsedSeconds: 2.0);

        self::assertSame(20, $estimator->estimateRemainingSeconds(currentUsedBytes: 300, elapsedSeconds: 5.0));
    }

    public function testReturnsZeroWhenTargetHasBeenReached(): void
    {
        $estimator = new FillProgressEstimator(startUsedBytes: 100, targetUsedBytes: 500);

        self::assertSame(0, $estimator->estimateRemainingSeconds(currentUsedBytes: 500, elapsedSeconds: 3.0));
    }
}
