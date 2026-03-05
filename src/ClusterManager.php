<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

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
        if (function_exists('stream_isatty') && stream_isatty(STDOUT)) {
            fwrite(STDOUT, "\033[H\033[2J");
        }
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
