<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\SlotRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlotRangeTest extends TestCase
{
    public function testCountAndContains(): void
    {
        $range = new SlotRange(100, 199);

        self::assertSame(100, $range->count());
        self::assertTrue($range->contains(100));
        self::assertTrue($range->contains(199));
        self::assertFalse($range->contains(99));
        self::assertFalse($range->contains(200));
    }

    public function testLabelCollapsesSingleSlotRanges(): void
    {
        self::assertSame('7', (new SlotRange(7, 7))->label());
        self::assertSame('7-9', (new SlotRange(7, 9))->label());
    }

    public function testRejectsRangesOutsideTheSlotSpace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot range 0-16384 is outside the valid slot space 0-16383.');

        new SlotRange(0, 16384);
    }

    public function testRejectsInvertedRanges(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot range 10-9 ends before it starts.');

        new SlotRange(10, 9);
    }

    /**
     * @param list<int> $slots
     * @param list<string> $expected
     */
    #[DataProvider('compactProvider')]
    public function testCompactCollapsesSlotsIntoRanges(array $slots, array $expected): void
    {
        $labels = array_map(
            static fn (SlotRange $range): string => $range->label(),
            SlotRange::compact($slots),
        );

        self::assertSame($expected, $labels);
    }

    /**
     * @return iterable<string, array{slots: list<int>, expected: list<string>}>
     */
    public static function compactProvider(): iterable
    {
        yield 'empty list' => [
            'slots' => [],
            'expected' => [],
        ];

        yield 'single slot' => [
            'slots' => [42],
            'expected' => ['42'],
        ];

        yield 'contiguous run' => [
            'slots' => [1, 2, 3, 4],
            'expected' => ['1-4'],
        ];

        yield 'unsorted input is normalized' => [
            'slots' => [4, 1, 3, 2],
            'expected' => ['1-4'],
        ];

        yield 'duplicates collapse' => [
            'slots' => [1, 1, 2, 2, 3],
            'expected' => ['1-3'],
        ];

        yield 'gaps split ranges' => [
            'slots' => [0, 1, 5, 6, 7, 9],
            'expected' => ['0-1', '5-7', '9'],
        ];
    }

    public function testExpandRoundTripsCompact(): void
    {
        $slots = [10, 11, 12, 40, 100, 101];

        self::assertSame($slots, SlotRange::expand(SlotRange::compact($slots)));
    }

    public function testCountSlotsSumsEveryRange(): void
    {
        self::assertSame(0, SlotRange::countSlots([]));
        self::assertSame(13, SlotRange::countSlots([new SlotRange(0, 9), new SlotRange(100, 102)]));
    }

    public function testFormatJoinsRangesAndFallsBackForEmptyOwnership(): void
    {
        self::assertSame('-', SlotRange::format([]));
        self::assertSame('none', SlotRange::format([], 'none'));
        self::assertSame('0-9,100', SlotRange::format([new SlotRange(0, 9), new SlotRange(100, 100)]));
    }

    public function testContainsSlotScansEveryRange(): void
    {
        $ranges = [new SlotRange(0, 9), new SlotRange(100, 102)];

        self::assertTrue(SlotRange::containsSlot($ranges, 9));
        self::assertTrue(SlotRange::containsSlot($ranges, 101));
        self::assertFalse(SlotRange::containsSlot($ranges, 10));
        self::assertFalse(SlotRange::containsSlot([], 0));
    }
}
