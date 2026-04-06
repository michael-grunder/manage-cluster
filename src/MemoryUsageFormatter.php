<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class MemoryUsageFormatter
{
    public static function format(?int $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }

        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        $units = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $value = (float) $bytes;
        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024) {
                return $value >= 100
                    ? sprintf('%.0f %s', $value, $unit)
                    : sprintf('%.1f %s', $value, $unit);
            }
        }

        return sprintf('%.1f EiB', $value / 1024);
    }
}
