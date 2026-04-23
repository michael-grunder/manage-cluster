<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class PortRangeFormatter
{
    /**
     * @param list<int> $ports
     */
    public static function formatRange(array $ports): string
    {
        $count = count($ports);
        if ($count === 0) {
            return '-';
        }

        if ($count === 1) {
            return (string) $ports[0];
        }

        return sprintf('%d-%d', $ports[0], $ports[$count - 1]);
    }

    /**
     * @param list<int> $ports
     */
    public static function formatCompactList(array $ports): string
    {
        if ($ports === []) {
            return '-';
        }

        $segments = [];
        $rangeStart = $ports[0];
        $previous = $ports[0];

        for ($index = 1, $count = count($ports); $index < $count; $index++) {
            $port = $ports[$index];
            if ($port === $previous + 1) {
                $previous = $port;
                continue;
            }

            array_push($segments, ...self::formatCompactSegment($rangeStart, $previous));
            $rangeStart = $previous = $port;
        }

        array_push($segments, ...self::formatCompactSegment($rangeStart, $previous));

        return implode(' ', $segments);
    }

    /**
     * @return list<string>
     */
    private static function formatCompactSegment(int $start, int $end): array
    {
        if ($start === $end) {
            return [(string) $start];
        }

        if ($end === $start + 1) {
            return [(string) $start, (string) $end];
        }

        return [sprintf('%d-%d', $start, $end)];
    }
}
