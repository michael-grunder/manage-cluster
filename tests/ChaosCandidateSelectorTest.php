<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosCandidateEvent;
use Mgrunder\CreateCluster\ChaosCandidateSelector;
use Mgrunder\CreateCluster\ChaosCategorySelection;
use Mgrunder\CreateCluster\ChaosOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChaosCandidateSelectorTest extends TestCase
{
    #[DataProvider('categoryWeightDistributions')]
    public function testEquallyScoredCandidatesSplitTheDrawByCategoryWeight(
        float $slotMigrationWeight,
        float $expectedShare,
    ): void {
        $counts = $this->drawCounts(
            [
                $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, 3),
                $this->candidate(ChaosOptions::CATEGORY_SLOT_MIGRATION, 3),
            ],
            new ChaosCategorySelection([
                ChaosOptions::CATEGORY_REPLICA_KILL => 1.0,
                ChaosOptions::CATEGORY_SLOT_MIGRATION => $slotMigrationWeight,
            ]),
        );

        self::assertSame(4000, array_sum($counts));
        self::assertEqualsWithDelta($expectedShare, $counts[ChaosOptions::CATEGORY_SLOT_MIGRATION] / 4000, 0.03);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function categoryWeightDistributions(): iterable
    {
        yield 'neutral weights split evenly' => [1.0, 0.5];
        yield 'triple weight wins three of four draws' => [3.0, 0.75];
        yield 'fractional weight is picked less often' => [0.25, 0.2];
    }

    public function testDisabledCategoriesDrawAtTheNeutralWeight(): void
    {
        $counts = $this->drawCounts(
            [
                $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, 3),
                $this->candidate(ChaosOptions::CATEGORY_SLOT_MIGRATION, 3),
            ],
            ChaosCategorySelection::fromCategories([ChaosOptions::CATEGORY_REPLICA_KILL]),
        );

        self::assertEqualsWithDelta(0.5, $counts[ChaosOptions::CATEGORY_SLOT_MIGRATION] / 4000, 0.03);
    }

    #[DataProvider('scoreGapShares')]
    public function testEachScorePointDoublesACandidatesShare(int $scoreGap, float $expectedShare): void
    {
        $counts = $this->drawCounts(
            [
                $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, 5),
                $this->candidate(ChaosOptions::CATEGORY_SLOT_MIGRATION, 5 - $scoreGap),
            ],
            ChaosCategorySelection::fromCategories(ChaosOptions::SUPPORTED_CATEGORIES),
        );

        self::assertEqualsWithDelta($expectedShare, $counts[ChaosOptions::CATEGORY_SLOT_MIGRATION] / 4000, 0.03);
    }

    /**
     * @return iterable<string, array{int, float}>
     */
    public static function scoreGapShares(): iterable
    {
        yield 'a tie splits evenly' => [0, 0.5];
        yield 'one point behind is half as likely' => [1, 1 / 3];
        yield 'two points behind is a quarter as likely' => [2, 0.2];
    }

    /**
     * The bug this replaced: the loser of a three-point gap was cut from the
     * draw before its weight was read, so no `--categories` weight could bring
     * it back.
     */
    public function testAnOperatorWeightOutbidsALowerScore(): void
    {
        $candidates = [
            $this->candidate(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, 5),
            $this->candidate(ChaosOptions::CATEGORY_SLOT_MIGRATION, 2),
        ];

        $unweighted = $this->drawCounts(
            $candidates,
            ChaosCategorySelection::fromCategories(ChaosOptions::SUPPORTED_CATEGORIES),
        );
        self::assertEqualsWithDelta(1 / 9, $unweighted[ChaosOptions::CATEGORY_SLOT_MIGRATION] / 4000, 0.03);

        // A weight of 8 is worth exactly the three score points it is behind.
        $weighted = $this->drawCounts($candidates, new ChaosCategorySelection([
            ChaosOptions::CATEGORY_PRIMARY_FAILOVER => 1.0,
            ChaosOptions::CATEGORY_SLOT_MIGRATION => 8.0,
        ]));
        self::assertEqualsWithDelta(0.5, $weighted[ChaosOptions::CATEGORY_SLOT_MIGRATION] / 4000, 0.03);
    }

    public function testDrawWeightsAreScaledToTheBestCandidate(): void
    {
        $weights = new ChaosCandidateSelector()->drawWeights(
            [
                $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, 40),
                $this->candidate(ChaosOptions::CATEGORY_SLOT_MIGRATION, 38),
                $this->candidate(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, -12),
            ],
            new ChaosCategorySelection([ChaosOptions::CATEGORY_SLOT_MIGRATION => 3.0]),
        );

        self::assertSame(1.0, $weights[0]);
        self::assertSame(0.75, $weights[1]);
        self::assertGreaterThan(0.0, $weights[2]);
    }

    public function testASingleCandidateIsAlwaysSelected(): void
    {
        $only = $this->candidate(ChaosOptions::CATEGORY_REPLICA_ADD, -4);

        self::assertSame($only, new ChaosCandidateSelector()->select(
            [$only],
            ChaosCategorySelection::fromCategories([ChaosOptions::CATEGORY_REPLICA_ADD]),
        ));
    }

    /**
     * @param non-empty-list<ChaosCandidateEvent> $candidates
     *
     * @return array<string, int> pick count keyed by chaos category
     */
    private function drawCounts(array $candidates, ChaosCategorySelection $categories): array
    {
        $selector = new ChaosCandidateSelector();
        $counts = array_fill_keys(ChaosOptions::SUPPORTED_CATEGORIES, 0);

        mt_srand(20250905);
        for ($draw = 0; $draw < 4000; $draw++) {
            $counts[$selector->select($candidates, $categories)->category]++;
        }

        mt_srand();

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    private function candidate(string $category, int $score): ChaosCandidateEvent
    {
        return new ChaosCandidateEvent(
            category: $category,
            targetPort: 7003,
            targetPrimaryPort: 7000,
            score: $score,
            summary: $category,
            postcondition: sprintf('%s converged', $category),
            reasons: ['eligible'],
        );
    }
}
