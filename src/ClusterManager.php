<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use Redis;
use RuntimeException;
use Symfony\Component\Process\Process;

final class ClusterManager
{
    public function __construct(
        private readonly SystemInspector $systemInspector,
        private readonly ClusterStateStore $stateStore,
        private readonly RedisNodeClient $redisNodeClient,
        private readonly TlsMaterialGenerator $tlsMaterialGenerator,
        private readonly ClusterShardsParser $clusterShardsParser,
        private readonly ClusterStatusRenderer $clusterStatusRenderer,
        private readonly ClusterStatusTuiRenderer $clusterStatusTuiRenderer,
    ) {
    }

    public function start(CommandLineOptions $options): void
    {
        $this->systemInspector->ensureExecutableExists($options->redisBinary, 'redis-server');
        $this->systemInspector->ensureExecutableExists($options->redisCliBinary, 'redis-cli');

        if ($options->tls) {
            $this->systemInspector->ensureExecutableExists('openssl', 'openssl');
        }

        $groupSize = $options->replicas + 1;
        if (count($options->ports) % $groupSize !== 0) {
            throw new RuntimeException(sprintf(
                'Node count (%d) must be divisible by replicas+1 (%d).',
                count($options->ports),
                $groupSize,
            ));
        }

        $masters = (int) (count($options->ports) / $groupSize);
        if ($masters < 3) {
            throw new RuntimeException(sprintf('Need at least 3 masters, got %d.', $masters));
        }

        foreach ($options->ports as $port) {
            if ($this->systemInspector->isPortListening($port)) {
                throw new RuntimeException(sprintf('Port %d is already in use.', $port));
            }
        }

        $clusterDir = $this->stateStore->createClusterDirectory();
        $clusterId = basename($clusterDir);

        $tlsMaterial = null;
        if ($options->tls) {
            $tlsMaterial = $this->tlsMaterialGenerator->generate(
                clusterDir: $clusterDir,
                announceIp: $options->announceIp,
                days: $options->tlsDays,
                rsaBits: $options->tlsRsaBits,
            );
        }

        $startedPorts = [];

        try {
            foreach ($options->ports as $port) {
                $configPath = $this->writeNodeConfiguration(
                    clusterDir: $clusterDir,
                    port: $port,
                    announceIp: $options->announceIp,
                    tls: $options->tls,
                    tlsMaterial: $tlsMaterial,
                );

                $this->runProcess([$options->redisBinary, $configPath]);
                $this->redisNodeClient->waitForReady($port, $options->tls, $tlsMaterial['ca_cert'] ?? null);
                $startedPorts[] = $port;
            }

            $this->createCluster($options, $tlsMaterial);
        } catch (\Throwable $exception) {
            foreach ($startedPorts as $startedPort) {
                $this->redisNodeClient->shutdown($startedPort, $options->tls, $tlsMaterial['ca_cert'] ?? null);
            }

            $this->systemInspector->waitForPortsToClose($startedPorts);
            throw $exception;
        }

        $metadata = [
            'id' => $clusterId,
            'cluster_dir' => $clusterDir,
            'created_at' => date(DATE_ATOM),
            'ports' => $options->ports,
            'replicas' => $options->replicas,
            'tls' => $options->tls,
            'announce_ip' => $options->announceIp,
            'redis_binary' => $options->redisBinary,
            'redis_cli_binary' => $options->redisCliBinary,
            'tls_material' => $tlsMaterial,
        ];

        $this->stateStore->persistClusterMetadata($metadata);

        printf("Started cluster %s\n", $clusterId);
        printf("State: %s\n", $clusterDir);
        printf("Ports: %s\n", implode(' ', array_map('strval', $options->ports)));
    }

    public function stop(CommandLineOptions $options): void
    {
        $clusters = [];
        foreach ($options->ports as $port) {
            $metadata = $this->stateStore->findClusterByPort($port);
            if ($metadata === null) {
                $clusters[sprintf('adhoc-%d', $port)] = [
                    'id' => sprintf('adhoc-%d', $port),
                    'ports' => [$port],
                    'tls' => false,
                    'tls_material' => null,
                    'cluster_dir' => null,
                ];

                continue;
            }

            $id = is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('port-%d', $port);
            $clusters[$id] = $metadata;
        }

        foreach ($clusters as $metadata) {
            $seed = $this->extractFirstPort($metadata);
            $tls = (bool) ($metadata['tls'] ?? false);
            $caCert = is_array($metadata['tls_material'] ?? null)
                ? (is_string($metadata['tls_material']['ca_cert'] ?? null) ? $metadata['tls_material']['ca_cert'] : null)
                : null;

            $ports = $this->discoverPortsForStop($seed, $tls, $caCert, $metadata);
            foreach ($ports as $clusterPort) {
                $this->redisNodeClient->shutdown($clusterPort, $tls, $caCert);
            }

            $this->systemInspector->waitForPortsToClose($ports);

            if (is_string($metadata['cluster_dir'] ?? null)) {
                $this->stateStore->removeClusterMetadata($metadata);
                printf("Stopped cluster %s (%s)\n", $metadata['id'] ?? 'unknown', implode(' ', array_map('strval', $ports)));
            } else {
                printf("Stopped nodes: %s\n", implode(' ', array_map('strval', $ports)));
            }
        }
    }

    public function rebalance(CommandLineOptions $options): void
    {
        $this->systemInspector->ensureExecutableExists($options->redisCliBinary, 'redis-cli');

        $seedPort = $options->ports[0];
        $metadata = $this->stateStore->findClusterByPort($seedPort);

        $tls = (bool) ($metadata['tls'] ?? false);
        $caCert = is_array($metadata['tls_material'] ?? null)
            ? (is_string($metadata['tls_material']['ca_cert'] ?? null) ? $metadata['tls_material']['ca_cert'] : null)
            : null;

        $command = [$options->redisCliBinary];
        if ($tls) {
            $command[] = '--tls';
            if ($caCert !== null) {
                $command[] = '--cacert';
                $command[] = $caCert;
            }
        }

        $command[] = '--cluster';
        $command[] = 'rebalance';
        $command[] = sprintf('127.0.0.1:%d', $seedPort);
        $command[] = '--cluster-yes';

        $this->runProcess($command);

        printf("Rebalanced cluster using seed 127.0.0.1:%d\n", $seedPort);
    }

    public function status(CommandLineOptions $options): void
    {
        $seedPort = $options->ports[0];
        $metadata = $this->stateStore->findClusterByPort($seedPort) ?? [];

        $tls = (bool) ($metadata['tls'] ?? false);
        $caCert = is_array($metadata['tls_material'] ?? null)
            ? (is_string($metadata['tls_material']['ca_cert'] ?? null) ? $metadata['tls_material']['ca_cert'] : null)
            : null;

        while (true) {
            $rawShards = $this->readClusterShardsWithFallback($seedPort, $tls, $caCert);
            $shards = $this->clusterShardsParser->parse($rawShards);

            $renderedWithTui = $this->clusterStatusTuiRenderer->render(
                shards: $shards,
                seedPort: $seedPort,
                watchMode: $options->watch,
            );

            if (!$renderedWithTui) {
                if ($options->watch) {
                    $this->clearTerminal();
                }

                fwrite(STDOUT, $this->clusterStatusRenderer->render(
                    shards: $shards,
                    width: $this->detectTerminalWidth(),
                    seedPort: $seedPort,
                    watchMode: $options->watch,
                ));
            }

            if (!$options->watch) {
                return;
            }

            usleep(1_000_000);
        }
    }

    public function flush(CommandLineOptions $options): void
    {
        foreach ($this->resolveRequestedClusters($options->ports) as $metadata) {
            $seedPort = $this->extractFirstPort($metadata);
            $tls = (bool) ($metadata['tls'] ?? false);
            $caCert = is_array($metadata['tls_material'] ?? null)
                ? (is_string($metadata['tls_material']['ca_cert'] ?? null) ? $metadata['tls_material']['ca_cert'] : null)
                : null;

            $rawShards = $this->readClusterShardsWithFallback($seedPort, $tls, $caCert);
            $shards = $this->clusterShardsParser->parse($rawShards);
            $primaryPorts = $this->extractPrimaryPorts($shards);
            if ($primaryPorts === []) {
                throw new RuntimeException(sprintf('No primary nodes discovered for seed port %d.', $seedPort));
            }

            foreach ($primaryPorts as $port) {
                $this->flushDbWithFallback($port, $tls, $caCert);
            }

            printf(
                "Flushed cluster %s primary nodes: %s\n",
                is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort),
                implode(' ', array_map('strval', $primaryPorts)),
            );
        }
    }

    public function fill(CommandLineOptions $options): void
    {
        $fill = $options->fill;
        if ($fill === null) {
            throw new RuntimeException('Missing fill options.');
        }

        $metadata = $this->resolveSingleClusterForFill($options->ports);
        $seedPort = $this->extractFirstPort($metadata);
        $tls = (bool) ($metadata['tls'] ?? false);
        $caCert = is_array($metadata['tls_material'] ?? null)
            ? (is_string($metadata['tls_material']['ca_cert'] ?? null) ? $metadata['tls_material']['ca_cert'] : null)
            : null;

        $rawShards = $this->readClusterShardsWithFallback($seedPort, $tls, $caCert);
        $shards = $this->clusterShardsParser->parse($rawShards);
        $primaryPorts = $this->extractPrimaryPorts($shards);
        if ($primaryPorts === []) {
            throw new RuntimeException(sprintf('No primary nodes discovered for seed port %d.', $seedPort));
        }

        if ($fill->pinPrimaryPort !== null && !in_array($fill->pinPrimaryPort, $primaryPorts, true)) {
            throw new RuntimeException(sprintf(
                '--pin-primary %d is not a primary in cluster %s. Primaries: %s',
                $fill->pinPrimaryPort,
                is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort),
                implode(' ', array_map('strval', $primaryPorts)),
            ));
        }

        $connections = [];
        try {
            foreach ($primaryPorts as $port) {
                $connections[$port] = $this->connectNodeWithFallback($port, $tls, $caCert);
            }

            $startUsedBytes = $this->sumUsedMemoryBytes($connections);
            if ($startUsedBytes >= $fill->sizeBytes) {
                printf(
                    "Cluster %s already at %s (target %s); no new keys generated.\n",
                    is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort),
                    $this->formatBytes($startUsedBytes),
                    $this->formatBytes($fill->sizeBytes),
                );

                return;
            }

            $pinnedTag = null;
            if ($fill->pinPrimaryPort !== null) {
                $pinnedTag = $this->findHashTagForPrimary($fill->pinPrimaryPort, $shards);
            }

            $writes = 0;
            $keyCounter = 0;
            $currentUsedBytes = $startUsedBytes;
            $containerMemberSize = max(8, (int) ceil($fill->memberSize / $fill->members));
            $fillStartAt = microtime(true);
            $lastProgressAt = $fillStartAt;
            $renderProgressOnSingleLine = $this->isInteractiveStdout();
            $clusterLabel = is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort);
            $this->renderFillProgress(
                clusterLabel: $clusterLabel,
                currentUsedBytes: $currentUsedBytes,
                targetUsedBytes: $fill->sizeBytes,
                keysAdded: 0,
                elapsedSeconds: 0,
                singleLine: $renderProgressOnSingleLine,
            );

            while ($currentUsedBytes < $fill->sizeBytes) {
                $keyCounter++;
                $type = $fill->types[random_int(0, count($fill->types) - 1)];
                $prefix = $pinnedTag === null ? '' : sprintf('{%s}:', $pinnedTag);
                $key = sprintf('%s%s:%d', $prefix, $type, $keyCounter);

                $slot = $this->clusterKeySlot($key);
                $targetPort = $this->findPrimaryPortForSlot($slot, $shards);
                if ($targetPort === null) {
                    throw new RuntimeException(sprintf('Unable to map slot %d to a primary node.', $slot));
                }

                if ($fill->pinPrimaryPort !== null && $targetPort !== $fill->pinPrimaryPort) {
                    throw new RuntimeException(sprintf(
                        'Internal error: expected slot %d to map to primary %d, got %d.',
                        $slot,
                        $fill->pinPrimaryPort,
                        $targetPort,
                    ));
                }

                $redis = $connections[$targetPort] ?? null;
                if (!$redis instanceof Redis) {
                    throw new RuntimeException(sprintf('No Redis connection available for primary port %d.', $targetPort));
                }

                $this->writeFillKey(
                    redis: $redis,
                    key: $key,
                    type: $type,
                    members: $fill->members,
                    memberSize: $fill->memberSize,
                    containerMemberSize: $containerMemberSize,
                );

                $writes++;
                if ($writes % 200 === 0) {
                    $currentUsedBytes = $this->sumUsedMemoryBytes($connections);
                }

                $now = microtime(true);
                if (($now - $lastProgressAt) >= 1.0) {
                    $currentUsedBytes = $this->sumUsedMemoryBytes($connections);
                    $lastProgressAt = $now;
                    $this->renderFillProgress(
                        clusterLabel: $clusterLabel,
                        currentUsedBytes: $currentUsedBytes,
                        targetUsedBytes: $fill->sizeBytes,
                        keysAdded: $writes,
                        elapsedSeconds: $now - $fillStartAt,
                        singleLine: $renderProgressOnSingleLine,
                    );
                }
            }

            $endUsedBytes = $this->sumUsedMemoryBytes($connections);
            if ($renderProgressOnSingleLine) {
                fwrite(STDOUT, PHP_EOL);
            }

            printf(
                "Filled cluster %s from %s to %s (target %s) with %d keys%s.\n",
                $clusterLabel,
                $this->formatBytes($startUsedBytes),
                $this->formatBytes($endUsedBytes),
                $this->formatBytes($fill->sizeBytes),
                $writes,
                $fill->pinPrimaryPort !== null ? sprintf(' pinned to primary %d via {%s}', $fill->pinPrimaryPort, $pinnedTag) : '',
            );
        } finally {
            foreach ($connections as $connection) {
                try {
                    $connection->close();
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * @param array{ca_cert: string, server_cert: string, server_key: string}|null $tlsMaterial
     */
    private function writeNodeConfiguration(
        string $clusterDir,
        int $port,
        ?string $announceIp,
        bool $tls,
        ?array $tlsMaterial,
    ): string {
        $nodeDir = sprintf('%s/node-%d', $clusterDir, $port);
        if (!mkdir($concurrentDirectory = $nodeDir, 0o755, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Failed to create node directory: %s', $nodeDir));
        }

        $configPath = sprintf('%s/redis.conf', $nodeDir);
        $lines = [
            'cluster-enabled yes',
            sprintf('cluster-config-file %s/nodes.conf', $nodeDir),
            sprintf('dir %s', $nodeDir),
            'dbfilename dump.rdb',
            'appendonly no',
            'save ""',
            'daemonize yes',
            sprintf('pidfile %s/redis.pid', $nodeDir),
            sprintf('logfile %s/redis.log', $nodeDir),
            'protected-mode no',
            'bind 127.0.0.1',
        ];

        if ($tls) {
            if ($tlsMaterial === null) {
                throw new RuntimeException('TLS mode requested without TLS material.');
            }

            $lines[] = 'port 0';
            $lines[] = sprintf('tls-port %d', $port);
            $lines[] = sprintf('tls-cert-file %s', $tlsMaterial['server_cert']);
            $lines[] = sprintf('tls-key-file %s', $tlsMaterial['server_key']);
            $lines[] = sprintf('tls-ca-cert-file %s', $tlsMaterial['ca_cert']);
            $lines[] = 'tls-auth-clients no';
            $lines[] = 'tls-replication yes';
            $lines[] = 'tls-cluster yes';
            $lines[] = 'cluster-announce-port 0';
            $lines[] = sprintf('cluster-announce-tls-port %d', $port);
        } else {
            $lines[] = sprintf('port %d', $port);
        }

        if ($announceIp !== null && $announceIp !== '') {
            $lines[] = sprintf('cluster-announce-ip %s', $announceIp);
        }

        if (file_put_contents($configPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed writing config: %s', $configPath));
        }

        return $configPath;
    }

    /**
     * @param array{ca_cert: string, server_cert: string, server_key: string}|null $tlsMaterial
     */
    private function createCluster(CommandLineOptions $options, ?array $tlsMaterial): void
    {
        $command = [$options->redisCliBinary];

        if ($options->tls) {
            $command[] = '--tls';
            if (is_string($tlsMaterial['ca_cert'] ?? null)) {
                $command[] = '--cacert';
                $command[] = $tlsMaterial['ca_cert'];
            }
        }

        $command[] = '--cluster';
        $command[] = 'create';

        $host = $options->announceIp ?: '127.0.0.1';
        foreach ($options->ports as $port) {
            $command[] = sprintf('%s:%d', $host, $port);
        }

        $command[] = '--cluster-replicas';
        $command[] = (string) $options->replicas;
        $command[] = '--cluster-yes';

        $this->runProcess($command);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return list<int>
     */
    private function discoverPortsForStop(int $seedPort, bool $tls, ?string $caCert, array $metadata): array
    {
        try {
            return $this->redisNodeClient->discoverClusterPorts($seedPort, $tls, $caCert);
        } catch (\Throwable) {
            if (!$tls) {
                try {
                    return $this->redisNodeClient->discoverClusterPorts($seedPort, true, $caCert);
                } catch (\Throwable) {
                    // Fall through to metadata or seed.
                }
            }
        }

        $ports = $metadata['ports'] ?? [$seedPort];
        if (!is_array($ports) || $ports === []) {
            return [$seedPort];
        }

        $normalized = [];
        foreach ($ports as $port) {
            if (!is_int($port) && !is_string($port)) {
                continue;
            }

            $normalized[] = (int) $port;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        return $normalized === [] ? [$seedPort] : $normalized;
    }

    /**
     * @param list<int> $requestedPorts
     * @return list<array<string, mixed>>
     */
    private function resolveRequestedClusters(array $requestedPorts): array
    {
        $clusters = [];
        foreach ($requestedPorts as $port) {
            $metadata = $this->stateStore->findClusterByPort($port);
            if ($metadata === null) {
                $clusters[sprintf('adhoc-%d', $port)] = [
                    'id' => sprintf('adhoc-%d', $port),
                    'ports' => [$port],
                    'tls' => false,
                    'tls_material' => null,
                    'cluster_dir' => null,
                ];

                continue;
            }

            $id = is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('port-%d', $port);
            $clusters[$id] = $metadata;
        }

        return array_values($clusters);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     * @return list<int>
     */
    private function extractPrimaryPorts(array $shards): array
    {
        $ports = [];
        foreach ($shards as $shard) {
            $ports[$shard->master->port] = true;
        }

        $primaryPorts = array_map('intval', array_keys($ports));
        sort($primaryPorts, SORT_NUMERIC);

        return $primaryPorts;
    }

    private function flushDbWithFallback(int $port, bool $tls, ?string $caCert): void
    {
        try {
            $this->redisNodeClient->flushDb($port, $tls, $caCert);

            return;
        } catch (\Throwable) {
            $this->redisNodeClient->flushDb($port, !$tls, $caCert);
        }
    }

    private function connectNodeWithFallback(int $port, bool $tls, ?string $caCert): Redis
    {
        try {
            return $this->redisNodeClient->connectToNode($port, $tls, $caCert);
        } catch (\Throwable) {
            return $this->redisNodeClient->connectToNode($port, !$tls, $caCert);
        }
    }

    /**
     * @param list<int> $requestedPorts
     * @return array<string, mixed>
     */
    private function resolveSingleClusterForFill(array $requestedPorts): array
    {
        if (count($requestedPorts) === 1) {
            $seedPort = $requestedPorts[0];
            $metadata = $this->stateStore->findClusterByPort($seedPort);
            if ($metadata !== null) {
                return $metadata;
            }

            return [
                'id' => sprintf('adhoc-%d', $seedPort),
                'ports' => [$seedPort],
                'tls' => false,
                'tls_material' => null,
                'cluster_dir' => null,
            ];
        }

        $clusters = $this->stateStore->listClusters();
        if (count($clusters) === 1) {
            return $clusters[0];
        }

        if ($clusters === []) {
            throw new RuntimeException('fill needs a seed port when no managed clusters are present.');
        }

        $seeds = [];
        foreach ($clusters as $cluster) {
            try {
                $seeds[] = $this->extractFirstPort($cluster);
            } catch (\Throwable) {
            }
        }

        throw new RuntimeException(sprintf(
            'fill is ambiguous with %d managed clusters; pass a seed port (candidates: %s).',
            count($clusters),
            $seeds === [] ? 'unknown' : implode(' ', array_map('strval', $seeds)),
        ));
    }

    /**
     * @param array<int, Redis> $connections
     */
    private function sumUsedMemoryBytes(array $connections): int
    {
        $sum = 0;
        foreach ($connections as $port => $redis) {
            $info = $redis->info('memory');
            if (!is_array($info)) {
                throw new RuntimeException(sprintf('INFO memory returned unexpected data for primary %d.', $port));
            }

            $used = $info['used_memory'] ?? null;
            if (!is_int($used) && !is_string($used)) {
                throw new RuntimeException(sprintf('used_memory missing for primary %d.', $port));
            }

            $sum += (int) $used;
        }

        return $sum;
    }

    private function writeFillKey(
        Redis $redis,
        string $key,
        string $type,
        int $members,
        int $memberSize,
        int $containerMemberSize,
    ): void {
        if ($type === 'string') {
            $ok = $redis->set($key, $this->randomHexString($memberSize));
            if ($ok !== true) {
                throw new RuntimeException(sprintf('SET failed for key %s', $key));
            }

            return;
        }

        if ($type === 'list') {
            $args = ['RPUSH', $key];
            for ($i = 1; $i <= $members; $i++) {
                $args[] = $this->buildContainerMember($key, $i, $containerMemberSize);
            }

            $response = $redis->rawCommand(...$args);
            if ($response === false) {
                throw new RuntimeException(sprintf('RPUSH failed for key %s', $key));
            }

            return;
        }

        if ($type === 'set') {
            $args = ['SADD', $key];
            for ($i = 1; $i <= $members; $i++) {
                $args[] = $this->buildContainerMember($key, $i, $containerMemberSize);
            }

            $response = $redis->rawCommand(...$args);
            if ($response === false) {
                throw new RuntimeException(sprintf('SADD failed for key %s', $key));
            }

            return;
        }

        if ($type === 'hash') {
            $args = ['HSET', $key];
            for ($i = 1; $i <= $members; $i++) {
                $args[] = sprintf('f%d', $i);
                $args[] = $this->buildContainerMember($key, $i, $containerMemberSize);
            }

            $response = $redis->rawCommand(...$args);
            if ($response === false) {
                throw new RuntimeException(sprintf('HSET failed for key %s', $key));
            }

            return;
        }

        if ($type === 'zset') {
            $args = ['ZADD', $key];
            for ($i = 1; $i <= $members; $i++) {
                $args[] = (string) $i;
                $args[] = $this->buildContainerMember($key, $i, $containerMemberSize);
            }

            $response = $redis->rawCommand(...$args);
            if ($response === false) {
                throw new RuntimeException(sprintf('ZADD failed for key %s', $key));
            }

            return;
        }

        throw new RuntimeException(sprintf('Unsupported fill type: %s', $type));
    }

    private function buildContainerMember(string $key, int $index, int $length): string
    {
        $prefix = sprintf('%s:%d:', $key, $index);
        if (strlen($prefix) >= $length) {
            return substr($prefix, 0, $length);
        }

        return $prefix . $this->randomHexString($length - strlen($prefix));
    }

    private function randomHexString(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function findHashTagForPrimary(int $targetPrimaryPort, array $shards): string
    {
        for ($index = 1; $index <= 100_000; $index++) {
            $candidate = sprintf('s%d', $index);
            $slot = $this->clusterKeySlot(sprintf('{%s}:probe', $candidate));
            $ownerPort = $this->findPrimaryPortForSlot($slot, $shards);
            if ($ownerPort === $targetPrimaryPort) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf('Unable to find a hash tag mapping to primary %d.', $targetPrimaryPort));
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function findPrimaryPortForSlot(int $slot, array $shards): ?int
    {
        foreach ($shards as $shard) {
            if ($slot >= $shard->slotStart && $slot <= $shard->slotEnd) {
                return $shard->master->port;
            }
        }

        return null;
    }

    private function clusterKeySlot(string $key): int
    {
        $open = strpos($key, '{');
        if ($open !== false) {
            $close = strpos($key, '}', $open + 1);
            if ($close !== false && $close > $open + 1) {
                $key = substr($key, $open + 1, $close - $open - 1);
            }
        }

        return $this->crc16($key) % 16384;
    }

    private function renderFillProgress(
        string $clusterLabel,
        int $currentUsedBytes,
        int $targetUsedBytes,
        int $keysAdded,
        float $elapsedSeconds,
        bool $singleLine,
    ): void {
        $percent = $targetUsedBytes > 0
            ? min(100.0, ($currentUsedBytes / $targetUsedBytes) * 100)
            : 100.0;

        $message = sprintf(
            'Filling %s: memory %s/%s (%.1f%%), keys added %s, elapsed %s',
            $clusterLabel,
            $this->formatBytes($currentUsedBytes),
            $this->formatBytes($targetUsedBytes),
            $percent,
            number_format($keysAdded),
            $this->formatElapsed($elapsedSeconds),
        );

        if ($singleLine) {
            fwrite(STDOUT, sprintf("\r\033[2K%s", $message));

            return;
        }

        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function formatElapsed(float $elapsedSeconds): string
    {
        $elapsed = max(0, (int) round($elapsedSeconds));
        $hours = intdiv($elapsed, 3600);
        $minutes = intdiv($elapsed % 3600, 60);
        $seconds = $elapsed % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    private function crc16(string $value): int
    {
        $crc = 0x0000;
        $length = strlen($value);
        for ($offset = 0; $offset < $length; $offset++) {
            $crc ^= ord($value[$offset]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return $crc;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $value, $units[$unitIndex]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function extractFirstPort(array $metadata): int
    {
        $ports = $metadata['ports'] ?? null;
        if (!is_array($ports) || $ports === []) {
            throw new RuntimeException('No ports found in cluster metadata.');
        }

        $port = $ports[0] ?? null;
        if (!is_int($port) && !is_string($port)) {
            throw new RuntimeException('Invalid port found in cluster metadata.');
        }

        return (int) $port;
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command): void
    {
        $process = new Process($command);
        $process->mustRun();
    }

    /**
     * @return array<mixed>
     */
    private function readClusterShardsWithFallback(int $seedPort, bool $tls, ?string $caCert): array
    {
        try {
            return $this->redisNodeClient->fetchClusterShards($seedPort, $tls, $caCert);
        } catch (\Throwable) {
            return $this->redisNodeClient->fetchClusterShards($seedPort, !$tls, $caCert);
        }
    }

    private function clearTerminal(): void
    {
        if ($this->isInteractiveStdout()) {
            fwrite(STDOUT, "\033[H\033[2J");
        }
    }

    private function isInteractiveStdout(): bool
    {
        return function_exists('stream_isatty') && stream_isatty(STDOUT);
    }

    private function detectTerminalWidth(): int
    {
        $columns = getenv('COLUMNS');
        if (is_string($columns) && preg_match('/^\d+$/', $columns) === 1 && (int) $columns > 0) {
            return (int) $columns;
        }

        $sttyOutput = [];
        $sttyExitCode = 1;
        @exec('stty size 2>/dev/null', $sttyOutput, $sttyExitCode);
        if ($sttyExitCode === 0 && isset($sttyOutput[0]) && preg_match('/^\d+\s+(\d+)$/', $sttyOutput[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return 120;
    }
}
