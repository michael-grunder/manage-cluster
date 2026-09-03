<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final readonly class SlotRange
{
    public const int FIRST_SLOT = 0;
    public const int LAST_SLOT = 16383;
    public const int TOTAL_SLOTS = 16384;

    public function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start < self::FIRST_SLOT || $end > self::LAST_SLOT) {
            throw new InvalidArgumentException(sprintf(
                'Slot range %d-%d is outside the valid slot space %d-%d.',
                $start,
                $end,
                self::FIRST_SLOT,
                self::LAST_SLOT,
            ));
        }

        if ($end < $start) {
            throw new InvalidArgumentException(sprintf('Slot range %d-%d ends before it starts.', $start, $end));
        }
    }

    public function count(): int
    {
        return $this->end - $this->start + 1;
    }

    public function contains(int $slot): bool
    {
        return $slot >= $this->start && $slot <= $this->end;
    }

    public function label(): string
    {
        return $this->start === $this->end
            ? (string) $this->start
            : sprintf('%d-%d', $this->start, $this->end);
    }

    /**
     * @return list<int>
     */
    public function slots(): array
    {
        return range($this->start, $this->end);
    }

    /**
     * Collapse an unordered slot list into ascending, non-overlapping ranges.
     *
     * @param list<int> $slots
     * @return list<SlotRange>
     */
    public static function compact(array $slots): array
    {
        $unique = array_values(array_unique($slots));
        sort($unique, SORT_NUMERIC);

        $ranges = [];
        $count = count($unique);
        $index = 0;

        while ($index < $count) {
            $start = $unique[$index];
            $end = $start;
            $index++;

            while ($index < $count && $unique[$index] === $end + 1) {
                $end = $unique[$index];
                $index++;
            }

            $ranges[] = new self($start, $end);
        }

        return $ranges;
    }

    /**
     * @param list<SlotRange> $ranges
     * @return list<int>
     */
    public static function expand(array $ranges): array
    {
        $slots = [];
        foreach ($ranges as $range) {
            foreach ($range->slots() as $slot) {
                $slots[] = $slot;
            }
        }

        sort($slots, SORT_NUMERIC);

        return $slots;
    }

    /**
     * @param list<SlotRange> $ranges
     */
    public static function countSlots(array $ranges): int
    {
        return array_sum(array_map(static fn (self $range): int => $range->count(), $ranges));
    }

    /**
     * @param list<SlotRange> $ranges
     */
    public static function format(array $ranges, string $empty = '-'): string
    {
        if ($ranges === []) {
            return $empty;
        }

        return implode(',', array_map(static fn (self $range): string => $range->label(), $ranges));
    }

    /**
     * @param list<SlotRange> $ranges
     */
    public static function containsSlot(array $ranges, int $slot): bool
    {
        foreach ($ranges as $range) {
            if ($range->contains($slot)) {
                return true;
            }
        }

        return false;
    }
}
