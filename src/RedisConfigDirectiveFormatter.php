<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final class RedisConfigDirectiveFormatter
{
    public static function normalizeName(string $name): string
    {
        if (str_starts_with($name, '--')) {
            $name = substr($name, 2);
        }

        if ($name === '' || str_starts_with($name, '-')) {
            throw new InvalidArgumentException(sprintf('Invalid Redis config name: %s', $name));
        }

        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new InvalidArgumentException(sprintf('Invalid Redis config name: %s', $name));
        }

        return $name;
    }

    public static function format(string $name, string $value): string
    {
        $name = self::normalizeName($name);
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException(sprintf('Redis config value for %s cannot contain newlines.', $name));
        }

        return sprintf('%s %s', $name, self::formatValue($value));
    }

    private static function formatValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (!preg_match('/[\s#"\\\\]/', $value)) {
            return $value;
        }

        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
        ]) . '"';
    }
}
