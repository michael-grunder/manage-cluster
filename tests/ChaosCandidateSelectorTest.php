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

    public function testCategoryDrawWeightsAreScaledToTheBestCandidateInEachCategory(): void
    {
        $weights = new ChaosCandidateSelector()->categoryDrawWeights(
            [
                $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, -12),
                $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, 40),
                $this->candidate(ChaosOptions::CATEGORY_SLOT_MIGRATION, 38),
                $this->candidate(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, -12),
            ],
            new ChaosCategorySelection([ChaosOptions::CATEGORY_SLOT_MIGRATION => 3.0]),
        );

        // A category is represented by its best candidate, not its worst and
        // not the number of candidates it produced.
        self::assertSame(1.0, $weights[ChaosOptions::CATEGORY_REPLICA_KILL]);
        self::assertSame(0.75, $weights[ChaosOptions::CATEGORY_SLOT_MIGRATION]);
        self::assertGreaterThan(0.0, $weights[ChaosOptions::CATEGORY_PRIMARY_FAILOVER]);
    }

    /**
     * The regression that motivated the two-stage draw: replica-kill offers one
     * candidate per healthy replica while slot-migration offers a single plan,
     * so a flat draw over the candidate list starved slot migration in
     * proportion to how many replicas the cluster happened to have.
     */
    #[DataProvider('replicaCounts')]
    public function testACategoryShareDoesNotDependOnHowManyCandidatesItEnumerated(int $replicaCount): void
    {
        $candidates = [$this->candidate(ChaosOptions::CATEGORY_SLOT_MIGRATION, 2)];
        for ($replica = 0; $replica < $replicaCount; $replica++) {
            $candidates[] = $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, 2);
        }

        $counts = $this->drawCounts($candidates, ChaosCategorySelection::fromCategories(ChaosOptions::SUPPORTED_CATEGORIES));

        self::assertEqualsWithDelta(0.5, $counts[ChaosOptions::CATEGORY_SLOT_MIGRATION] / 4000, 0.03);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function replicaCounts(): iterable
    {
        yield 'one replica' => [1];
        yield 'nine replicas' => [9];
        yield 'sixty replicas' => [60];
    }

    public function testWithinACategoryTheBetterScoringCandidateWins(): void
    {
        $selector = new ChaosCandidateSelector();
        $high = $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, 5);
        $low = $this->candidate(ChaosOptions::CATEGORY_REPLICA_KILL, 3);

        $picked = 0;
        mt_srand(20250905);
        for ($draw = 0; $draw < 4000; $draw++) {
            if ($selector->select([$high, $low], ChaosCategorySelection::fromCategories([ChaosOptions::CATEGORY_REPLICA_KILL])) === $high) {
                $picked++;
            }
        }

        mt_srand();

        // Two score points apart, so the better candidate wins four times in five.
        self::assertEqualsWithDelta(0.8, $picked / 4000, 0.03);
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
