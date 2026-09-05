<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\ChaosCategorySelection;
use Mgrunder\CreateCluster\ChaosOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChaosCategorySelectionTest extends TestCase
{
    public function testFromCategoriesGivesEveryCategoryTheNeutralWeight(): void
    {
        $selection = ChaosCategorySelection::fromCategories(ChaosOptions::DEFAULT_CATEGORIES);

        self::assertSame(ChaosOptions::DEFAULT_CATEGORIES, $selection->names());
        self::assertFalse($selection->isEmpty());
        foreach (ChaosOptions::DEFAULT_CATEGORIES as $category) {
            self::assertTrue($selection->has($category));
            self::assertSame(ChaosCategorySelection::DEFAULT_WEIGHT, $selection->weightFor($category));
        }
    }

    public function testWeightForFallsBackToTheNeutralWeightForDisabledCategories(): void
    {
        $selection = ChaosCategorySelection::fromCategories([ChaosOptions::CATEGORY_REPLICA_KILL]);

        self::assertFalse($selection->has(ChaosOptions::CATEGORY_SLOT_MIGRATION));
        self::assertSame(1.0, $selection->weightFor(ChaosOptions::CATEGORY_SLOT_MIGRATION));
    }

    public function testWithKeepsAnExistingWeightWhenNoneIsGiven(): void
    {
        $selection = new ChaosCategorySelection([ChaosOptions::CATEGORY_SLOT_MIGRATION => 2.5]);

        $merged = $selection->with(ChaosOptions::CATEGORY_SLOT_MIGRATION);

        self::assertSame([ChaosOptions::CATEGORY_SLOT_MIGRATION], $merged->names());
        self::assertSame(2.5, $merged->weightFor(ChaosOptions::CATEGORY_SLOT_MIGRATION));
    }

    public function testWithAppendsAMissingCategoryAtTheNeutralWeight(): void
    {
        $selection = ChaosCategorySelection::fromCategories([ChaosOptions::CATEGORY_REPLICA_KILL]);

        $merged = $selection->with(ChaosOptions::CATEGORY_PRIMARY_ADD);

        self::assertSame(
            [ChaosOptions::CATEGORY_REPLICA_KILL, ChaosOptions::CATEGORY_PRIMARY_ADD],
            $merged->names(),
        );
        self::assertSame(1.0, $merged->weightFor(ChaosOptions::CATEGORY_PRIMARY_ADD));
    }

    public function testWithReweightsWithoutMovingTheCategory(): void
    {
        $selection = ChaosCategorySelection::fromCategories([
            ChaosOptions::CATEGORY_REPLICA_KILL,
            ChaosOptions::CATEGORY_SLOT_MIGRATION,
            ChaosOptions::CATEGORY_REPLICA_ADD,
        ]);

        $merged = $selection->with(ChaosOptions::CATEGORY_SLOT_MIGRATION, 4.0);

        self::assertSame(
            [
                ChaosOptions::CATEGORY_REPLICA_KILL,
                ChaosOptions::CATEGORY_SLOT_MIGRATION,
                ChaosOptions::CATEGORY_REPLICA_ADD,
            ],
            $merged->names(),
        );
        self::assertSame(4.0, $merged->weightFor(ChaosOptions::CATEGORY_SLOT_MIGRATION));
    }

    public function testIntersectAndHasAnyOnlyReportEnabledCategories(): void
    {
        $selection = ChaosCategorySelection::fromCategories([
            ChaosOptions::CATEGORY_PRIMARY_ADD,
            ChaosOptions::CATEGORY_REPLICA_KILL,
        ]);

        self::assertSame(
            [ChaosOptions::CATEGORY_PRIMARY_ADD],
            $selection->intersect([ChaosOptions::CATEGORY_PRIMARY_ADD, ChaosOptions::CATEGORY_PRIMARY_REMOVE]),
        );
        self::assertTrue($selection->hasAny([ChaosOptions::CATEGORY_REPLICA_KILL]));
        self::assertFalse($selection->hasAny([ChaosOptions::CATEGORY_PRIMARY_REMOVE]));
    }

    /**
     * @param array<string, float> $weightByCategory
     */
    #[DataProvider('descriptions')]
    public function testDescribeLeavesNeutralWeightsImplicit(array $weightByCategory, string $expected): void
    {
        self::assertSame($expected, new ChaosCategorySelection($weightByCategory)->describe());
    }

    /**
     * @return iterable<string, array{array<string, float>, string}>
     */
    public static function descriptions(): iterable
    {
        yield 'empty selection' => [[], ''];
        yield 'neutral weights are omitted' => [
            [ChaosOptions::CATEGORY_REPLICA_KILL => 1.0, ChaosOptions::CATEGORY_REPLICA_ADD => 1.0],
            'replica-kill,replica-add',
        ];
        yield 'integral weights lose the decimals' => [
            [ChaosOptions::CATEGORY_REPLICA_KILL => 1.0, ChaosOptions::CATEGORY_SLOT_MIGRATION => 3.0],
            'replica-kill,slot-migration:3',
        ];
        yield 'fractional weights stay readable' => [
            [ChaosOptions::CATEGORY_REPLICA_KILL => 0.5, ChaosOptions::CATEGORY_SLOT_MIGRATION => 1.5],
            'replica-kill:0.5,slot-migration:1.5',
        ];
    }

    public function testUnsupportedCategoryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported chaos category: bogus');

        new ChaosCategorySelection(['bogus' => 1.0]);
    }

    #[DataProvider('illegalWeights')]
    public function testIllegalWeightsAreRejected(float $weight): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chaos category weight for slot-migration must be a finite number greater than 0.');

        new ChaosCategorySelection([ChaosOptions::CATEGORY_SLOT_MIGRATION => $weight]);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function illegalWeights(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    public function testTokensAreTheDescribeTokensOnePerEntry(): void
    {
        $selection = new ChaosCategorySelection([
            ChaosOptions::CATEGORY_REPLICA_KILL => 0.5,
            ChaosOptions::CATEGORY_SLOT_MIGRATION => 3.0,
            ChaosOptions::CATEGORY_REPLICA_ADD => 1.0,
        ]);

        self::assertSame(['replica-kill:0.5', 'slot-migration:3', 'replica-add'], $selection->tokens());
        self::assertSame($selection->describe(), implode(',', $selection->tokens()));
    }
}
