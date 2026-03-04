<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final class PortParser
{
    /**
     * @param list<string> $values
     * @return list<int>
     */
    public static function parse(array $values): array
    {
        $ports = [];

        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            foreach (self::expandToken($value) as $port) {
                if ($port < 1 || $port > 65535) {
                    throw new InvalidArgumentException(sprintf('Invalid port: %d', $port));
                }

                $ports[$port] = true;
            }
        }

        if ($ports === []) {
            throw new InvalidArgumentException('No ports provided');
        }

        $result = array_map('intval', array_keys($ports));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @return list<int>
     */
    private static function expandToken(string $token): array
    {
        if (preg_match('/^\{(\d+)\.\.(\d+)\}$/', $token, $matches) === 1) {
            return self::expandRange((int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('/^(\d+)-(\d+)$/', $token, $matches) === 1) {
            return self::expandRange((int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('/^\d+$/', $token) === 1) {
            return [(int) $token];
        }

        throw new InvalidArgumentException(sprintf('Invalid port token: %s', $token));
    }

    /**
     * @return list<int>
     */
    private static function expandRange(int $start, int $end): array
    {
        if ($end < $start) {
            throw new InvalidArgumentException(sprintf('Invalid range: %d..%d', $start, $end));
        }

        /** @var list<int> $range */
        $range = range($start, $end);

        return $range;
    }
}
