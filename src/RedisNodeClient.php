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
                $redis = $this->connectToNode($port, $tls, $caCert);
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
        $redis = $this->connectToNode($seedPort, $tls, $caCert);

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
            if (trim($line) === '') {
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

            if ($address === '') {
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
            $redis = $this->connectToNode($port, $tls, $caCert);
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
        $redis = $this->connectToNode($port, $tls, $caCert);

        try {
            $response = $redis->rawCommand('FLUSHDB');
            if ($response === false) {
                throw new RuntimeException(sprintf('FLUSHDB failed on Redis node at port %d', $port));
            }
        } finally {
            $redis->close();
        }
    }

    public function fetchConfigDir(int $port, bool $tls, ?string $caCert): string
    {
        $redis = $this->connectToNode($port, $tls, $caCert);

        try {
            $response = $redis->rawCommand('CONFIG', 'GET', 'dir');
        } finally {
            $redis->close();
        }

        if (!is_array($response) || count($response) < 2) {
            throw new RuntimeException(sprintf('CONFIG GET dir returned an unexpected response for port %d.', $port));
        }

        $dir = $response[1] ?? null;
        if (!is_string($dir) || trim($dir) === '') {
            throw new RuntimeException(sprintf('CONFIG GET dir did not include a usable directory for port %d.', $port));
        }

        return $dir;
    }

    public function clusterMeet(int $port, bool $tls, ?string $caCert, string $host, int $meetPort): void
    {
        $redis = $this->connectToNode($port, $tls, $caCert);

        try {
            $response = $redis->rawCommand('CLUSTER', 'MEET', $host, (string) $meetPort);
        } finally {
            $redis->close();
        }

        if ($response === false) {
            throw new RuntimeException(sprintf('CLUSTER MEET failed on port %d.', $port));
        }
    }

    public function clusterReplicate(int $port, bool $tls, ?string $caCert, string $nodeId): void
    {
        $redis = $this->connectToNode($port, $tls, $caCert);

        try {
            $response = $redis->rawCommand('CLUSTER', 'REPLICATE', $nodeId);
        } finally {
            $redis->close();
        }

        if ($response === false) {
            throw new RuntimeException(sprintf('CLUSTER REPLICATE failed on port %d.', $port));
        }
    }

    public function waitForKnownClusterNode(
        int $port,
        bool $tls,
        ?string $caCert,
        string $nodeId,
        float $seconds = 10.0,
    ): void {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $redis = null;
            try {
                $redis = $this->connectToNode($port, $tls, $caCert);
                $response = $redis->rawCommand('CLUSTER', 'NODES');
                if (is_string($response) && str_contains($response, $nodeId)) {
                    $redis->close();

                    return;
                }
            } catch (RedisException) {
                // Retry until timeout.
            } finally {
                if ($redis instanceof Redis) {
                    try {
                        $redis->close();
                    } catch (RedisException) {
                    }
                }
            }

            usleep(100_000);
        }

        throw new RuntimeException(sprintf(
            'Timed out waiting for node %s to be visible from cluster node at port %d.',
            $nodeId,
            $port,
        ));
    }

    /**
     * @return array<mixed>
     */
    public function fetchClusterShards(int $seedPort, bool $tls, ?string $caCert): array
    {
        $redis = $this->connectToNode($seedPort, $tls, $caCert);

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

    public function tryFetchUsedMemoryBytes(int $port, bool $tls, ?string $caCert): ?int
    {
        try {
            $info = $this->fetchInfo($port, $tls, $caCert, 'memory');
        } catch (RedisException|RuntimeException) {
            return null;
        }

        $used = $info['used_memory'] ?? null;
        if (!is_int($used) && !is_string($used)) {
            return null;
        }

        return (int) $used;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchInfo(int $port, bool $tls, ?string $caCert, ?string $section = null): array
    {
        $redis = $this->connectToNode($port, $tls, $caCert);

        try {
            $info = $section === null ? $redis->info() : $redis->info($section);
        } finally {
            $redis->close();
        }

        if (!is_array($info)) {
            throw new RuntimeException(sprintf('INFO returned an unexpected response for port %d.', $port));
        }

        return $info;
    }

    /**
     * @return array<string, string>
     */
    public function fetchClusterInfo(int $port, bool $tls, ?string $caCert): array
    {
        $redis = $this->connectToNode($port, $tls, $caCert);

        try {
            $raw = $redis->rawCommand('CLUSTER', 'INFO');
        } finally {
            $redis->close();
        }

        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException(sprintf('CLUSTER INFO returned an unexpected response for port %d.', $port));
        }

        $info = [];
        foreach (preg_split('/\R/', trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }

            [$key, $value] = array_pad(explode(':', $line, 2), 2, null);
            if (!is_string($key) || !is_string($value) || $key === '') {
                continue;
            }

            $info[$key] = trim($value);
        }

        return $info;
    }

    public function connectToNode(int $port, bool $tls, ?string $caCert): Redis
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
