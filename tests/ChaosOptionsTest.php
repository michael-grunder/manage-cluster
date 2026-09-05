<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosCategorySelection;
use Mgrunder\CreateCluster\ChaosOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChaosOptionsTest extends TestCase
{
    #[DataProvider('abortDecisions')]
    public function testShouldAbortAfterFailures(
        int $maxFailures,
        bool $abortOnFailure,
        int $consecutiveFailures,
        bool $expected,
    ): void {
        $options = self::options($maxFailures, $abortOnFailure);

        self::assertSame($expected, $options->shouldAbortAfterFailures($consecutiveFailures));
    }

    /**
     * @return iterable<string, array{int, bool, int, bool}>
     */
    public static function abortDecisions(): iterable
    {
        yield 'default keeps churning through failures' => [ChaosOptions::UNLIMITED_FAILURES, false, 25, false];
        yield 'no failures never aborts' => [ChaosOptions::UNLIMITED_FAILURES, false, 0, false];
        yield 'abort-on-failure stops at the first failure' => [ChaosOptions::UNLIMITED_FAILURES, true, 1, true];
        yield 'abort-on-failure ignores a zero count' => [ChaosOptions::UNLIMITED_FAILURES, true, 0, false];
        yield 'ceiling not yet reached' => [5, false, 4, false];
        yield 'ceiling reached' => [5, false, 5, true];
        yield 'ceiling exceeded' => [5, false, 6, true];
        yield 'abort-on-failure wins over a high ceiling' => [100, true, 1, true];
    }

    private static function options(int $maxFailures, bool $abortOnFailure): ChaosOptions
    {
        return new ChaosOptions(
            categories: ChaosCategorySelection::fromCategories(ChaosOptions::DEFAULT_CATEGORIES),
            intervalSeconds: 8,
            maxEvents: null,
            maxFailures: $maxFailures,
            abortOnFailure: $abortOnFailure,
            dryRun: false,
            watch: false,
            seed: null,
            waitTimeoutSeconds: 60,
            cooldownSeconds: 2,
            allowSlotMigration: false,
            allowPrimaryFailover: false,
            allowReplicaReparent: false,
            allowPrimaryAdd: false,
            allowPrimaryRemove: false,
            unsafe: false,
        );
    }
}
