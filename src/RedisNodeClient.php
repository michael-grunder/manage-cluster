<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use Redis;
use RedisException;
use RuntimeException;

final class RedisNodeClient
{
    public function __construct()
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('PhpRedis extension is required but not loaded.');
        }
    }

    public function waitForReady(int $port, bool $tls, ?string $caCert, float $seconds = 10.0): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            try {
                $redis = $this->connect($port, $tls, $caCert);
                $redis->ping();
                $redis->close();

                return;
            } catch (RedisException) {
                usleep(100_000);
            }
        }

        throw new RuntimeException(sprintf('Timed out waiting for Redis node at port %d', $port));
    }

    /**
     * @return list<int>
     */
    public function discoverClusterPorts(int $seedPort, bool $tls, ?string $caCert): array
    {
        $redis = $this->connect($seedPort, $tls, $caCert);

        try {
            $raw = $redis->rawCommand('CLUSTER', 'NODES');
        } finally {
            $redis->close();
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [$seedPort];
        }

        $ports = [];
        foreach (preg_split('/\R/', trim($raw)) as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || !isset($parts[1])) {
                continue;
            }

            $address = $parts[1];
            if (($at = strpos($address, '@')) !== false) {
                $address = substr($address, 0, $at);
            }

            if (!is_string($address) || $address === '') {
                continue;
            }

            if (!preg_match('/:(\d+)$/', $address, $matches)) {
                continue;
            }

            $port = (int) $matches[1];
            $ports[$port] = true;
        }

        if ($ports === []) {
            return [$seedPort];
        }

        $result = array_map('intval', array_keys($ports));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    public function shutdown(int $port, bool $tls, ?string $caCert): void
    {
        try {
            $redis = $this->connect($port, $tls, $caCert);
        } catch (RedisException) {
            return;
        }

        try {
            $redis->rawCommand('SHUTDOWN', 'NOSAVE');
        } catch (RedisException) {
            // SHUTDOWN closes the connection by design.
        }

        try {
            $redis->close();
        } catch (RedisException) {
        }
    }

    public function flushDb(int $port, bool $tls, ?string $caCert): void
    {
        $redis = $this->connect($port, $tls, $caCert);

        try {
            $response = $redis->rawCommand('FLUSHDB');
            if ($response === false) {
                throw new RuntimeException(sprintf('FLUSHDB failed on Redis node at port %d', $port));
            }
        } finally {
            $redis->close();
        }
    }

    /**
     * @return array<mixed>
     */
    public function fetchClusterShards(int $seedPort, bool $tls, ?string $caCert): array
    {
        $redis = $this->connect($seedPort, $tls, $caCert);

        try {
            $raw = $redis->rawCommand('CLUSTER', 'SHARDS');
        } finally {
            $redis->close();
        }

        if (!is_array($raw)) {
            throw new RuntimeException('CLUSTER SHARDS returned an unexpected response.');
        }

        return $raw;
    }

    private function connect(int $port, bool $tls, ?string $caCert): Redis
    {
        $redis = new Redis();
        $host = $tls ? 'tls://127.0.0.1' : '127.0.0.1';

        $context = null;
        if ($tls) {
            $stream = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ];

            if ($caCert !== null && $caCert !== '') {
                $stream['cafile'] = $caCert;
            }

            $context = ['stream' => $stream];
        }

        $connected = $redis->connect($host, $port, 1.5, null, 0, 1.5, $context);
        if ($connected !== true) {
            throw new RuntimeException(sprintf('Failed to connect to Redis node at %s:%d', $host, $port));
        }

        return $redis;
    }
}
