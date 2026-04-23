<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use Redis;
use RuntimeException;
use Symfony\Component\Process\Process;

final class ClusterManager
{
    private const int CHAOS_STABLE_POLLS = 2;
    private const int CHAOS_PRIMARY_REPLICA_CAP = 2;

    public function __construct(
        private readonly SystemInspector $systemInspector,
        private readonly ClusterStateStore $stateStore,
        private readonly RedisNodeClient $redisNodeClient,
        private readonly TlsMaterialGenerator $tlsMaterialGenerator,
        private readonly StartScriptGenerator $startScriptGenerator,
        private readonly ClusterShardsParser $clusterShardsParser,
        private readonly ClusterStatusRenderer $clusterStatusRenderer,
        private readonly ClusterStatusTuiRenderer $clusterStatusTuiRenderer,
        private readonly ManagedClusterSummaryRenderer $managedClusterSummaryRenderer,
        private readonly ClusterTreeSelector $clusterTreeSelector,
        private readonly ConsoleOutput $output,
    ) {
    }

    public function start(CommandLineOptions $options): void
    {
        if ($options->generatedScriptPath !== null) {
            $this->generateStartScript($options);

            return;
        }

        $this->output->step('Validating required executables');
        $this->systemInspector->ensureExecutableExists($options->redisBinary, 'redis-server');
        $this->systemInspector->ensureExecutableExists($options->redisCliBinary, 'redis-cli');

        if ($options->tls) {
            $this->systemInspector->ensureExecutableExists('openssl', 'openssl');
        }
        $this->output->success('Executable validation complete');

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
        if ($masters !== $options->primaries) {
            throw new RuntimeException(sprintf(
                'Node count (%d) with --replicas %d creates %d primaries, but --primaries is %d.',
                count($options->ports),
                $options->replicas,
                $masters,
                $options->primaries,
            ));
        }

        foreach ($options->ports as $port) {
            if ($this->systemInspector->isPortListening($port)) {
                throw new RuntimeException(sprintf('Port %d is already in use.', $port));
            }
        }
        $this->output->success(sprintf('Validated %d requested ports', count($options->ports)));

        $clusterDir = $this->stateStore->createClusterDirectory();
        $clusterId = basename($clusterDir);
        $this->output->info(sprintf('Using cluster state directory %s', $clusterDir));
        $this->output->detail('Server', $this->systemInspector->describeServerBinary($options->redisBinary));

        $tlsMaterial = null;
        if ($options->tls) {
            $this->output->step('Generating ephemeral TLS material');
            $tlsMaterial = $this->tlsMaterialGenerator->generate(
                clusterDir: $clusterDir,
                announceIp: $options->announceIp,
                days: $options->tlsDays,
                rsaBits: $options->tlsRsaBits,
            );
            $this->output->success('TLS material generated');
        }

        $startedPorts = [];
        $startProcesses = [];

        try {
            $this->output->step(sprintf('Starting %d Redis nodes', count($options->ports)));
            foreach ($options->ports as $port) {
                $configPath = $this->writeNodeConfiguration(
                    clusterDir: $clusterDir,
                    port: $port,
                    announceIp: $options->announceIp,
                    tls: $options->tls,
                    tlsMaterial: $tlsMaterial,
                );

                $process = new Process([...[$options->redisBinary, $configPath], ...$options->startServerArgs]);
                $process->start();
                $startProcesses[$port] = $process;
                $startedPorts[] = $port;
            }

            $this->waitForStartProcesses($startProcesses);
            $this->output->step('Waiting for Redis nodes to become ready');
            $this->redisNodeClient->waitForReadyPorts($options->ports, $options->tls, $tlsMaterial['ca_cert'] ?? null);
            $this->output->success(sprintf(
                'Redis nodes are ready on ports %s',
                PortRangeFormatter::formatCompactList($options->ports),
            ));

            $this->output->step('Creating cluster topology and assigning slots');
            $this->createCluster($options, $tlsMaterial);
            $this->output->success('Cluster topology created');
        } catch (\Throwable $exception) {
            $this->output->warning('Start failed; shutting down any nodes that were launched');
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
            'primaries' => $options->primaries,
            'replicas' => $options->replicas,
            'tls' => $options->tls,
            'announce_ip' => $options->announceIp,
            'redis_binary' => $options->redisBinary,
            'redis_cli_binary' => $options->redisCliBinary,
            'tls_material' => $tlsMaterial,
        ];

        $this->stateStore->persistClusterMetadata($metadata);

        $this->output->success(sprintf('Started cluster %s', $clusterId));
        $this->output->detail('State', $clusterDir);
        $this->output->detail('Ports', PortRangeFormatter::formatCompactList($options->ports));
    }

    public function generateStartScript(CommandLineOptions $options): void
    {
        $path = $options->generatedScriptPath;
        if ($path === null || $path === '') {
            throw new RuntimeException('Missing generated script path.');
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Script output directory does not exist: %s', $directory));
        }

        $script = $this->startScriptGenerator->generate($options);

        $this->output->step(sprintf('Writing start script to %s', $path));
        if (file_put_contents($path, $script, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write generated script: %s', $path));
        }

        if (!chmod($path, 0o755)) {
            throw new RuntimeException(sprintf('Failed to make generated script executable: %s', $path));
        }

        $this->output->success(sprintf('Generated start script at %s', $path));
    }

    public function stop(CommandLineOptions $options): void
    {
        $this->systemInspector->ensureExecutableExists($options->redisCliBinary, 'redis-cli');

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

        /** @var list<array{metadata: array<string, mixed>, label: string, ports: list<int>}> $clusterStops */
        $clusterStops = [];
        /** @var array<int, array{port: int, tls: bool, ca_cert: ?string}> $shutdownTargets */
        $shutdownTargets = [];
        foreach ($clusters as $metadata) {
            $clusterLabel = is_string($metadata['id'] ?? null) ? $metadata['id'] : 'unknown';
            $this->output->step(sprintf('Resolving nodes for cluster %s', $clusterLabel));
            $seed = $this->extractFirstPort($metadata);
            $tls = (bool) ($metadata['tls'] ?? false);
            $caCert = is_array($metadata['tls_material'] ?? null)
                ? (is_string($metadata['tls_material']['ca_cert'] ?? null) ? $metadata['tls_material']['ca_cert'] : null)
                : null;

            $ports = $this->discoverPortsForStop($seed, $tls, $caCert, $metadata);
            foreach ($ports as $clusterPort) {
                $shutdownTargets[$clusterPort] ??= [
                    'port' => $clusterPort,
                    'tls' => $tls,
                    'ca_cert' => $caCert,
                ];
            }

            $clusterStops[] = [
                'metadata' => $metadata,
                'label' => $clusterLabel,
                'ports' => $ports,
            ];
        }

        if ($shutdownTargets === []) {
            return;
        }

        $ports = array_map('intval', array_keys($shutdownTargets));
        sort($ports, SORT_NUMERIC);

        $this->output->step(sprintf(
            'Sending SHUTDOWN signal to %s %s',
            count($ports) === 1 ? 'port' : 'ports',
            $this->formatCompactPortList($ports),
        ));
        $shutdownProcesses = $this->startShutdownProcesses($options->redisCliBinary, $shutdownTargets);
        $this->waitForShutdownProcesses($shutdownProcesses);

        $this->output->step('Waiting for nodes to exit');
        $this->systemInspector->waitForPortsToClose($ports);

        foreach ($clusterStops as $clusterStop) {
            $metadata = $clusterStop['metadata'];
            $clusterLabel = $clusterStop['label'];
            $ports = $clusterStop['ports'];
            if (is_string($metadata['cluster_dir'] ?? null)) {
                $this->stateStore->removeClusterMetadata($metadata);
                $this->output->success(sprintf(
                    'Stopped cluster %s (%s)',
                    $clusterLabel,
                    $this->formatCompactPortList($ports),
                ));
            } else {
                $this->output->success(sprintf('Stopped nodes: %s', $this->formatCompactPortList($ports)));
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

        $this->output->step(sprintf('Rebalancing cluster using seed 127.0.0.1:%d', $seedPort));
        $this->runProcess($command);
        $this->output->success(sprintf('Rebalanced cluster using seed 127.0.0.1:%d', $seedPort));
    }

    public function kill(CommandLineOptions $options): void
    {
        if (!$this->clusterTreeSelector->supportsInteractiveSelection()) {
            throw new RuntimeException('kill needs an interactive TTY to choose a cluster node.');
        }

        $seedPort = $options->ports[0];
        $metadata = $this->stateStore->findClusterByPort($seedPort);
        [$tls, $caCert, , $rawShards] = $this->resolveSeedConnectionContext($seedPort, $metadata);
        $shards = $this->clusterShardsParser->parse($rawShards);

        $selectedNode = $this->clusterTreeSelector->select(
            shards: $shards,
            seedPort: $seedPort,
            mode: ClusterTreeViewMode::AllNodes,
            title: 'Select a cluster node to shut down',
        );

        if (!$selectedNode instanceof ClusterNodeStatus) {
            throw new RuntimeException('Kill cancelled.');
        }

        $this->output->step(sprintf('Sending SHUTDOWN to %s', $selectedNode->address()));
        $this->redisNodeClient->shutdown($selectedNode->port, $tls, $caCert);
        $this->systemInspector->waitForPortsToClose([$selectedNode->port]);
        $this->persistClusterMetadataPortRemoval($metadata, $selectedNode->port);
        $this->output->success(sprintf('Stopped cluster node %s', $selectedNode->address()));
    }

    public function status(CommandLineOptions $options): void
    {
        if ($options->ports === []) {
            $this->renderManagedClusterSummary(watchMode: $options->watch, runningOnly: false);

            return;
        }

        $seedPort = $options->ports[0];
        $metadata = $this->stateStore->findClusterByPort($seedPort) ?? [];

        $tls = (bool) ($metadata['tls'] ?? false);
        $caCert = is_array($metadata['tls_material'] ?? null)
            ? (is_string($metadata['tls_material']['ca_cert'] ?? null) ? $metadata['tls_material']['ca_cert'] : null)
            : null;

        $latencyMonitor = $options->watch ? $this->tryCreateLatencyMonitor($tls, $caCert) : null;

        try {
            if ($options->watch) {
                $this->clearTerminal();
            }

            while (true) {
                $rawShards = $this->readClusterShardsWithFallback($seedPort, $tls, $caCert);
                $shards = $this->enrichShardsWithUsedMemory(
                    $this->clusterShardsParser->parse($rawShards),
                    $tls,
                    $caCert,
                );
                $ports = $this->extractClusterPorts($shards);
                $latencyMonitor?->updatePorts($ports);
                $latenciesByPort = $latencyMonitor?->snapshotForPorts($ports) ?? [];

                $renderedWithTui = $this->clusterStatusTuiRenderer->render(
                    shards: $shards,
                    seedPort: $seedPort,
                    watchMode: $options->watch,
                    latenciesByPort: $latenciesByPort,
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
                        latenciesByPort: $latenciesByPort,
                    ));
                }

                if (!$options->watch) {
                    return;
                }

                usleep(1_000_000);
            }
        } finally {
            $latencyMonitor?->stop();
        }
    }

    public function list(CommandLineOptions $options): void
    {
        $this->renderManagedClusterSummary(watchMode: false, runningOnly: true);
    }

    /**
     * @param list<ClusterShardStatus> $shards
     * @return list<ClusterShardStatus>
     */
    private function enrichShardsWithUsedMemory(array $shards, bool $tls, ?string $caCert): array
    {
        $usedMemoryByPort = [];

        foreach ($shards as $shard) {
            $nodes = [$shard->master, ...$shard->replicas];
            foreach ($nodes as $node) {
                $usedMemoryByPort[$node->port] ??= $this->redisNodeClient->tryFetchUsedMemoryBytes(
                    $node->port,
                    $tls,
                    $caCert,
                );
            }
        }

        return array_map(
            static fn (ClusterShardStatus $shard): ClusterShardStatus => new ClusterShardStatus(
                slotStart: $shard->slotStart,
                slotEnd: $shard->slotEnd,
                master: $shard->master->withUsedMemoryBytes($usedMemoryByPort[$shard->master->port] ?? null),
                replicas: array_map(
                    static fn (ClusterNodeStatus $replica): ClusterNodeStatus => $replica->withUsedMemoryBytes(
                        $usedMemoryByPort[$replica->port] ?? null,
                    ),
                    $shard->replicas,
                ),
            ),
            $shards,
        );
    }

    private function tryCreateLatencyMonitor(bool $tls, ?string $caCert): ?AsyncNodeLatencyMonitor
    {
        try {
            return new AsyncNodeLatencyMonitor($tls, $caCert);
        } catch (\Throwable) {
            return null;
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
                $this->output->info(sprintf('Flushing primary %d', $port));
                $this->flushDbWithFallback($port, $tls, $caCert);
            }

            $this->output->success(sprintf(
                'Flushed cluster %s primary nodes: %s',
                is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort),
                implode(' ', array_map('strval', $primaryPorts)),
            ));
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
                $this->output->info(sprintf(
                    'Cluster %s already at %s (target %s); no new keys generated.',
                    is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort),
                    $this->formatBytes($startUsedBytes),
                    $this->formatBytes($fill->sizeBytes),
                ));

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
            $progressEstimator = new FillProgressEstimator($startUsedBytes, $fill->sizeBytes);
            $renderProgressOnSingleLine = $this->output->isInteractive();
            $clusterLabel = is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort);
            $this->output->step(sprintf('Filling cluster %s', $clusterLabel));
            $this->renderFillProgress(
                currentUsedBytes: $currentUsedBytes,
                targetUsedBytes: $fill->sizeBytes,
                keysAdded: 0,
                remainingSeconds: null,
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
                        currentUsedBytes: $currentUsedBytes,
                        targetUsedBytes: $fill->sizeBytes,
                        keysAdded: $writes,
                        remainingSeconds: $progressEstimator->estimateRemainingSeconds(
                            currentUsedBytes: $currentUsedBytes,
                            elapsedSeconds: $now - $fillStartAt,
                        ),
                        singleLine: $renderProgressOnSingleLine,
                    );
                }
            }

            $endUsedBytes = $this->sumUsedMemoryBytes($connections);
            $this->output->finishProgress();

            $this->output->success(sprintf(
                'Filled cluster %s from %s to %s (target %s) with %d keys%s.',
                $clusterLabel,
                $this->formatBytes($startUsedBytes),
                $this->formatBytes($endUsedBytes),
                $this->formatBytes($fill->sizeBytes),
                $writes,
                $fill->pinPrimaryPort !== null ? sprintf(' pinned to primary %d via {%s}', $fill->pinPrimaryPort, $pinnedTag) : '',
            ));
        } finally {
            $this->output->finishProgress();
            foreach ($connections as $connection) {
                try {
                    $connection->close();
                } catch (\Throwable) {
                }
            }
        }
    }

    public function addReplica(CommandLineOptions $options): void
    {
        $this->systemInspector->ensureExecutableExists($options->redisBinary, 'redis-server');

        $seedPort = $options->ports[0];
        $this->output->step(sprintf('Preparing replica using seed %d', $seedPort));

        $metadata = $this->stateStore->findClusterByPort($seedPort);
        [$tls, $caCert, $tlsMaterial, $rawShards] = $this->resolveSeedConnectionContext($seedPort, $metadata);
        $shards = $this->clusterShardsParser->parse($rawShards);

        $primaryNode = $this->selectPrimaryForReplicaCreation($shards, $seedPort);
        if (!$primaryNode instanceof ClusterNodeStatus) {
            throw new RuntimeException(sprintf('Unable to resolve a primary node from seed port %d.', $seedPort));
        }

        $primaryPort = $primaryNode->port;
        $this->output->info(sprintf('Selected primary %s', $primaryNode->address()));

        $replicaPort = $this->createReplicaForPrimary(
            options: $options,
            metadata: $metadata,
            seedPort: $seedPort,
            primaryNode: $primaryNode,
            tls: $tls,
            caCert: $caCert,
            tlsMaterial: $tlsMaterial,
            rawShards: $shards,
        );

        $this->output->success(sprintf('Replica %d attached to primary %d', $replicaPort, $primaryPort));
    }

    public function restartReplica(CommandLineOptions $options): void
    {
        if (!$this->clusterTreeSelector->supportsInteractiveSelection()) {
            throw new RuntimeException('restart-replica needs an interactive TTY to choose a failed replica.');
        }

        $seedPort = $options->ports[0];
        $metadata = $this->stateStore->findClusterByPort($seedPort);
        if (!is_array($metadata)) {
            throw new RuntimeException(sprintf(
                'restart-replica requires managed cluster metadata for seed port %d so the replica config can be reused.',
                $seedPort,
            ));
        }

        [$tls, $caCert, , $rawShards] = $this->resolveSeedConnectionContext($seedPort, $metadata);
        $shards = $this->clusterShardsParser->parse($rawShards);

        $selectedReplica = $this->clusterTreeSelector->select(
            shards: $shards,
            seedPort: $seedPort,
            mode: ClusterTreeViewMode::FailedReplicasOnly,
            title: 'Select a failed replica to restart',
        );

        if (!$selectedReplica instanceof ClusterNodeStatus) {
            throw new RuntimeException('Replica restart cancelled.');
        }

        if ($selectedReplica->role !== 'replica') {
            throw new RuntimeException(sprintf('Selected node %s is not a replica.', $selectedReplica->address()));
        }

        if ($selectedReplica->health !== 'fail') {
            throw new RuntimeException(sprintf('Selected replica %s is not in fail state.', $selectedReplica->address()));
        }

        $this->restartReplicaPort($selectedReplica->port, $metadata, $options, $tls, $caCert);
        $this->output->success(sprintf('Replica %s restarted', $selectedReplica->address()));
    }

    public function chaos(CommandLineOptions $options): void
    {
        $chaos = $options->chaos;
        if (!$chaos instanceof ChaosOptions) {
            throw new RuntimeException('Missing chaos options.');
        }

        $implementedCategories = [
            ChaosOptions::CATEGORY_REPLICA_KILL,
            ChaosOptions::CATEGORY_REPLICA_RESTART,
            ChaosOptions::CATEGORY_REPLICA_ADD,
        ];

        if (array_intersect($chaos->categories, $implementedCategories) === []) {
            throw new RuntimeException('chaos v1 currently implements replica-kill, replica-restart, and replica-add.');
        }

        if ($chaos->seed !== null) {
            mt_srand($chaos->seed);
            $this->output->info(sprintf('Using chaos PRNG seed %d', $chaos->seed));
        }

        $seedPort = $options->ports[0];
        $metadata = $this->stateStore->findClusterByPort($seedPort);
        if (!is_array($metadata)) {
            throw new RuntimeException(sprintf('chaos requires managed cluster metadata for seed port %d.', $seedPort));
        }

        [$tls, $caCert] = $this->resolveSeedConnectionContext($seedPort, $metadata);
        $runtime = new ChaosRuntimeState(
            clusterId: is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort),
            seedPort: $seedPort,
            startedAt: microtime(true),
            allowedCategories: $chaos->categories,
        );

        $dryRunBudget = $chaos->dryRun && $chaos->maxEvents === null ? 1 : $chaos->maxEvents;

        while (true) {
            if ($dryRunBudget !== null && $runtime->completedEventCount() >= $dryRunBudget) {
                $this->output->success(sprintf('Chaos finished after %d planned events.', $runtime->completedEventCount()));

                return;
            }

            $view = $this->discoverChaosClusterView($runtime, $metadata, $seedPort, $tls, $caCert);
            if ($view->clusterDown) {
                throw new RuntimeException('Refusing to continue chaos while the cluster reports CLUSTERDOWN.');
            }

            if (!$view->broadlyHealthy && $view->degradedPrimaryPorts === []) {
                throw new RuntimeException('Cluster is broadly unhealthy; chaos aborted before stacking more topology churn.');
            }

            $candidate = $this->selectChaosCandidate($view, $runtime, $chaos);
            if (!$candidate instanceof ChaosCandidateEvent) {
                $runtime->markFailure();
                if ($runtime->consecutiveFailures >= $chaos->maxFailures) {
                    throw new RuntimeException(sprintf(
                        'Chaos failed to select a safe event %d times in a row; aborting.',
                        $runtime->consecutiveFailures,
                    ));
                }

                $this->emitChaosWatchLine($chaos, sprintf(
                    '[wait ] no eligible events (failure %d/%d)',
                    $runtime->consecutiveFailures,
                    $chaos->maxFailures,
                ));
                sleep(1);
                continue;
            }

            $event = new ChaosEventRecord(
                id: $runtime->nextEventId(),
                category: $candidate->category,
                status: 'planned',
                targetPort: $candidate->targetPort,
                targetPrimaryPort: $candidate->targetPrimaryPort,
                startedAt: microtime(true),
                completedAt: null,
                summary: $candidate->summary,
                postcondition: $candidate->postcondition,
                reasons: $candidate->reasons,
            );
            $runtime->inflightEvent = $event;
            $this->emitChaosWatchLine($chaos, sprintf('[chaos] event#%d %s', $event->id, $event->summary));

            if ($chaos->dryRun) {
                $planned = $event->withStatus('completed', microtime(true), ['dry-run']);
                $runtime->rememberHistory($planned);
                $runtime->inflightEvent = null;
                $runtime->resetFailures();
                $this->emitChaosWatchLine($chaos, sprintf(
                    '[plan ] %s | because: %s',
                    $planned->summary,
                    implode('; ', $candidate->reasons),
                ));
                continue;
            }

            try {
                $running = $event->withStatus('running');
                $runtime->inflightEvent = $running;
                $this->executeChaosEvent($running, $view, $runtime, $options, $metadata, $tls, $caCert);

                $waiting = $running->withStatus('waiting');
                $runtime->inflightEvent = $waiting;
                $completed = $this->waitForChaosEventConvergence($waiting, $runtime, $metadata, $seedPort, $tls, $caCert, $chaos);
                $runtime->rememberHistory($completed);
                $runtime->inflightEvent = null;
                $runtime->resetFailures();

                $elapsedSeconds = $completed->completedAt !== null ? max(0.0, $completed->completedAt - $completed->startedAt) : 0.0;
                $this->emitChaosWatchLine($chaos, sprintf('[done ] %s completed in %.1fs', $completed->summary, $elapsedSeconds));
            } catch (\Throwable $exception) {
                $runtime->markFailure();
                $failed = $event->withStatus('failed', microtime(true), [$exception->getMessage()]);
                $runtime->rememberHistory($failed);
                $runtime->inflightEvent = null;

                if ($runtime->consecutiveFailures >= $chaos->maxFailures) {
                    throw new RuntimeException(sprintf(
                        'Chaos aborted after %d consecutive failures. Last error: %s',
                        $runtime->consecutiveFailures,
                        $exception->getMessage(),
                    ), previous: $exception);
                }

                $this->output->warning($exception->getMessage());
                sleep(1);
                continue;
            }

            $this->sleepBetweenChaosSteps($chaos);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<ClusterShardStatus> $rawShards
     * @param array{ca_cert: string, server_cert: string, server_key: string}|null $tlsMaterial
     */
    private function createReplicaForPrimary(
        CommandLineOptions $options,
        ?array $metadata,
        int $seedPort,
        ClusterNodeStatus $primaryNode,
        bool $tls,
        ?string $caCert,
        ?array $tlsMaterial,
        array $rawShards,
        ?int $replicaPort = null,
    ): int {
        $usedPorts = $this->extractClusterPorts($rawShards);
        $selectedReplicaPort = $replicaPort ?? $options->replicaPort ?? $this->selectReplicaPortOutsideClusterRange($usedPorts);
        if (in_array($selectedReplicaPort, $usedPorts, true)) {
            throw new RuntimeException(sprintf('Requested replica port %d is already part of the cluster.', $selectedReplicaPort));
        }

        if ($this->systemInspector->isPortListening($selectedReplicaPort)) {
            throw new RuntimeException(sprintf('Port %d is already in use.', $selectedReplicaPort));
        }

        $clusterDir = $this->resolveReplicaClusterDirectory($primaryNode->port, $tls, $caCert, $metadata);
        $this->output->info(sprintf('Using cluster directory %s', $clusterDir));

        $configPath = $this->writeNodeConfiguration(
            clusterDir: $clusterDir,
            port: $selectedReplicaPort,
            announceIp: $options->announceIp,
            tls: $tls,
            tlsMaterial: $tlsMaterial,
        );

        $this->output->step(sprintf('Starting Redis node on port %d', $selectedReplicaPort));
        try {
            $this->runProcess([$options->redisBinary, $configPath]);
            $this->redisNodeClient->waitForReady($selectedReplicaPort, $tls, $caCert);
            $this->output->success(sprintf('Redis node %d is ready', $selectedReplicaPort));

            $meetHost = $primaryNode->endpoint !== ''
                ? $primaryNode->endpoint
                : ($primaryNode->ip !== '' ? $primaryNode->ip : '127.0.0.1');

            $this->output->step(sprintf('Sending CLUSTER MEET to %s:%d', $meetHost, $primaryNode->port));
            $this->redisNodeClient->clusterMeet($selectedReplicaPort, $tls, $caCert, $meetHost, $primaryNode->port);
            $this->redisNodeClient->waitForKnownClusterNode($selectedReplicaPort, $tls, $caCert, $primaryNode->id);
            $this->output->success('Replica joined cluster gossip');

            $this->output->step(sprintf('Sending CLUSTER REPLICATE %s', $primaryNode->shortId()));
            $this->redisNodeClient->clusterReplicate($selectedReplicaPort, $tls, $caCert, $primaryNode->id);
            $this->waitForReplicaAttachment($seedPort, $primaryNode->port, $selectedReplicaPort, $tls, $caCert);
        } catch (\Throwable $exception) {
            $this->output->warning(sprintf('Replica setup failed; shutting down port %d', $selectedReplicaPort));
            $this->redisNodeClient->shutdown($selectedReplicaPort, $tls, $caCert);
            $this->systemInspector->waitForPortsToClose([$selectedReplicaPort]);
            throw $exception;
        }

        $this->persistClusterMetadataPortAddition($metadata, $selectedReplicaPort);

        return $selectedReplicaPort;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function restartReplicaPort(int $port, array $metadata, CommandLineOptions $options, bool $tls, ?string $caCert): void
    {
        $configPath = $this->resolveExistingNodeConfigPath($metadata, $port);
        $redisBinary = $this->resolveRedisBinaryForRestart($metadata, $options);

        $this->output->step(sprintf('Restarting failed replica %d', $port));
        $this->runProcess([$redisBinary, $configPath]);
        $this->redisNodeClient->waitForReady($port, $tls, $caCert);
        $this->output->success(sprintf('Replica %d restarted using %s', $port, basename($configPath)));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function discoverChaosClusterView(
        ChaosRuntimeState $runtime,
        array $metadata,
        int $seedPort,
        bool $tls,
        ?string $caCert,
    ): ChaosClusterView {
        $rawShards = $this->readClusterShardsWithFallback($seedPort, $tls, $caCert);
        $shards = $this->clusterShardsParser->parse($rawShards);
        if ($shards === []) {
            throw new RuntimeException(sprintf('Unable to parse cluster topology from seed port %d.', $seedPort));
        }

        $clusterInfo = $this->readClusterInfoWithFallback($seedPort, $tls, $caCert);
        $clusterDown = ($clusterInfo['cluster_state'] ?? 'ok') !== 'ok';
        $managedPorts = $this->readManagedPorts($metadata);
        $currentPrimaryPorts = [];
        $currentReplicaPorts = [];
        $nodeStateByPort = [];
        $replicaPortsByPrimary = [];

        foreach ($shards as $shard) {
            $currentPrimaryPorts[$shard->master->port] = true;
            $slotRange = $shard->slotRange();

            $masterInfo = $this->tryFetchNodeInfo($shard->master->port, $tls, $caCert);
            $nodeStateByPort[$shard->master->port] = $this->buildChaosNodeState(
                port: $shard->master->port,
                nodeId: $shard->master->id,
                role: 'primary',
                primaryPort: null,
                knownByCluster: true,
                reachable: $masterInfo !== null || $this->systemInspector->isPortListening($shard->master->port),
                health: $shard->master->health,
                slotRanges: [$slotRange],
                managed: in_array($shard->master->port, $managedPorts, true),
                info: $masterInfo,
                clusterDir: is_string($metadata['cluster_dir'] ?? null) ? $metadata['cluster_dir'] : null,
            );

            foreach ($shard->replicas as $replica) {
                $currentReplicaPorts[$replica->port] = true;
                $replicaPortsByPrimary[$shard->master->port][] = $replica->port;
                $runtime->rememberReplicaPrimary($replica->port, $shard->master->port);
                $replicaInfo = $this->tryFetchNodeInfo($replica->port, $tls, $caCert);
                $nodeStateByPort[$replica->port] = $this->buildChaosNodeState(
                    port: $replica->port,
                    nodeId: $replica->id,
                    role: 'replica',
                    primaryPort: $shard->master->port,
                    knownByCluster: true,
                    reachable: $replicaInfo !== null || $this->systemInspector->isPortListening($replica->port),
                    health: $replica->health,
                    slotRanges: [],
                    managed: in_array($replica->port, $managedPorts, true),
                    info: $replicaInfo,
                    clusterDir: is_string($metadata['cluster_dir'] ?? null) ? $metadata['cluster_dir'] : null,
                );
            }
        }

        foreach ($managedPorts as $managedPort) {
            if (isset($nodeStateByPort[$managedPort])) {
                continue;
            }

            $runtimePrimaryPort = $runtime->lastKnownPrimaryForReplica($managedPort);
            $info = $this->tryFetchNodeInfo($managedPort, $tls, $caCert);
            $nodeStateByPort[$managedPort] = $this->buildChaosNodeState(
                port: $managedPort,
                nodeId: '',
                role: $runtimePrimaryPort !== null ? 'replica' : 'unknown',
                primaryPort: $runtimePrimaryPort,
                knownByCluster: false,
                reachable: $info !== null || $this->systemInspector->isPortListening($managedPort),
                health: 'unknown',
                slotRanges: [],
                managed: true,
                info: $info,
                clusterDir: is_string($metadata['cluster_dir'] ?? null) ? $metadata['cluster_dir'] : null,
            );
        }

        ksort($nodeStateByPort, SORT_NUMERIC);

        $primaryStateByPort = [];
        foreach ($shards as $shard) {
            $replicaPorts = $replicaPortsByPrimary[$shard->master->port] ?? [];
            $healthyReplicaCount = 0;
            $syncingReplicaCount = 0;
            $failedReplicaCount = 0;

            foreach ($replicaPorts as $replicaPort) {
                $replicaState = $nodeStateByPort[$replicaPort] ?? null;
                if (!$replicaState instanceof ChaosNodeState) {
                    continue;
                }

                if ($replicaState->isHealthyReplica()) {
                    $healthyReplicaCount++;
                }

                if ($replicaState->isSyncing) {
                    $syncingReplicaCount++;
                }

                if ($replicaState->isFailed || !$replicaState->reachable) {
                    $failedReplicaCount++;
                }
            }

            $masterState = $nodeStateByPort[$shard->master->port];
            $primaryStateByPort[$shard->master->port] = new ChaosPrimaryState(
                port: $shard->master->port,
                nodeId: $shard->master->id,
                reachable: $masterState->reachable && !$masterState->isFailed,
                slotRanges: [$shard->slotRange()],
                replicaPorts: $replicaPorts,
                healthyReplicaCount: $healthyReplicaCount,
                syncingReplicaCount: $syncingReplicaCount,
                failedReplicaCount: $failedReplicaCount,
            );
        }

        $replicaStateByPort = [];
        foreach ($nodeStateByPort as $port => $nodeState) {
            if ($nodeState->role === 'replica') {
                $replicaStateByPort[$port] = $nodeState;
            }
        }

        $degradedPrimaryPorts = [];
        foreach ($primaryStateByPort as $primaryPort => $primaryState) {
            if ($primaryState->isDegraded()) {
                $degradedPrimaryPorts[] = $primaryPort;
            }
        }

        sort($degradedPrimaryPorts, SORT_NUMERIC);
        $topologyHash = $this->buildChaosTopologyHash($nodeStateByPort, $primaryStateByPort, $clusterDown);
        $allPrimariesReachable = array_reduce(
            $primaryStateByPort,
            static fn (bool $carry, ChaosPrimaryState $primary): bool => $carry && $primary->reachable,
            true,
        );

        return new ChaosClusterView(
            clusterId: is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $seedPort),
            seedPort: $seedPort,
            topologyHash: $topologyHash,
            clusterDown: $clusterDown,
            broadlyHealthy: !$clusterDown && $allPrimariesReachable,
            nodeStateByPort: $nodeStateByPort,
            primaryStateByPort: $primaryStateByPort,
            replicaStateByPort: $replicaStateByPort,
            degradedPrimaryPorts: $degradedPrimaryPorts,
        );
    }

    private function selectChaosCandidate(
        ChaosClusterView $view,
        ChaosRuntimeState $runtime,
        ChaosOptions $chaos,
    ): ?ChaosCandidateEvent {
        $candidates = [];

        if (in_array(ChaosOptions::CATEGORY_REPLICA_RESTART, $chaos->categories, true)) {
            foreach ($view->replicaStateByPort as $replicaPort => $replica) {
                if ($replica->reachable || !$replica->managed) {
                    continue;
                }

                $primaryPort = $replica->primaryPort ?? $runtime->lastKnownPrimaryForReplica($replicaPort);
                $primary = $primaryPort !== null ? ($view->primaryStateByPort[$primaryPort] ?? null) : null;
                if (!$primary instanceof ChaosPrimaryState || !$primary->reachable) {
                    continue;
                }

                $score = 3;
                $reasons = ['managed replica is down and its primary is still reachable'];
                if (in_array($replicaPort, $runtime->intentionallyDownReplicaPorts(), true)) {
                    $score += 4;
                    $reasons[] = 'repairs an earlier intentional kill';
                }

                if ($runtime->lastEventTargeted(ChaosOptions::CATEGORY_REPLICA_RESTART, $replicaPort)) {
                    $score -= 3;
                }

                $candidates[] = new ChaosCandidateEvent(
                    category: ChaosOptions::CATEGORY_REPLICA_RESTART,
                    targetPort: $replicaPort,
                    targetPrimaryPort: $primaryPort,
                    score: $score,
                    summary: sprintf('replica-restart target=%d primary=%d', $replicaPort, $primaryPort),
                    postcondition: sprintf('replica %d is reachable again as a replica of %d', $replicaPort, $primaryPort),
                    reasons: $reasons,
                );
            }
        }

        if (in_array(ChaosOptions::CATEGORY_REPLICA_ADD, $chaos->categories, true)) {
            foreach ($view->primaryStateByPort as $primaryPort => $primary) {
                if (!$primary->reachable || !$primary->ownsSlots() || $primary->syncingReplicaCount > 0) {
                    continue;
                }

                if (count($primary->replicaPorts) >= self::CHAOS_PRIMARY_REPLICA_CAP) {
                    continue;
                }

                $replicaPort = $this->selectChaosReplicaPort($view);
                if ($replicaPort === null) {
                    continue;
                }

                $score = 1;
                $reasons = ['primary has capacity for one more managed replica'];
                if ($primary->isDegraded()) {
                    $score += 3;
                    $reasons[] = 'repairs a degraded primary with zero healthy replicas';
                } elseif ($primary->healthyReplicaCount < self::CHAOS_PRIMARY_REPLICA_CAP) {
                    $score += 2;
                    $reasons[] = 'balances replica inventory toward a lower-redundancy primary';
                }

                $latestKill = $runtime->mostRecentMatching(ChaosOptions::CATEGORY_REPLICA_KILL);
                if ($latestKill instanceof ChaosEventRecord && $latestKill->targetPrimaryPort === $primaryPort) {
                    $score += 2;
                    $reasons[] = 'follows up on a recent replica loss on this primary';
                }

                $candidates[] = new ChaosCandidateEvent(
                    category: ChaosOptions::CATEGORY_REPLICA_ADD,
                    targetPort: $replicaPort,
                    targetPrimaryPort: $primaryPort,
                    score: $score,
                    summary: sprintf('replica-add target=%d primary=%d', $replicaPort, $primaryPort),
                    postcondition: sprintf('new replica %d is attached to primary %d', $replicaPort, $primaryPort),
                    reasons: $reasons,
                );
            }
        }

        if (in_array(ChaosOptions::CATEGORY_REPLICA_KILL, $chaos->categories, true)) {
            foreach ($view->replicaStateByPort as $replicaPort => $replica) {
                if (!$replica->reachable || $replica->isFailed || $replica->isSyncing || !$replica->managed) {
                    continue;
                }

                $primaryPort = $replica->primaryPort;
                $primary = $primaryPort !== null ? ($view->primaryStateByPort[$primaryPort] ?? null) : null;
                if (!$primary instanceof ChaosPrimaryState || !$primary->reachable || $primary->syncingReplicaCount > 0) {
                    continue;
                }

                $score = 2;
                $reasons = ['healthy managed replica on a reachable primary'];
                if ($primary->healthyReplicaCount >= 2) {
                    $reasons[] = 'primary keeps at least one healthy replica after the kill';
                } elseif ($primary->healthyReplicaCount === 1) {
                    if (!$chaos->unsafe && count($view->degradedPrimaryPorts) > 0) {
                        continue;
                    }

                    $score -= 1;
                    $reasons[] = 'creates one degraded primary, which is allowed in normal v1 mode';
                } else {
                    continue;
                }

                if ($runtime->lastEventTargeted(ChaosOptions::CATEGORY_REPLICA_KILL, $replicaPort)) {
                    $score -= 3;
                }

                $candidates[] = new ChaosCandidateEvent(
                    category: ChaosOptions::CATEGORY_REPLICA_KILL,
                    targetPort: $replicaPort,
                    targetPrimaryPort: $primaryPort,
                    score: $score,
                    summary: sprintf('replica-kill target=%d primary=%d', $replicaPort, $primaryPort),
                    postcondition: sprintf('replica %d is unreachable or failed while primary %d remains healthy', $replicaPort, $primaryPort),
                    reasons: $reasons,
                );
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (ChaosCandidateEvent $left, ChaosCandidateEvent $right): int => $right->score <=> $left->score);
        $topScore = $candidates[0]->score;
        $topCandidates = array_values(array_filter(
            $candidates,
            static fn (ChaosCandidateEvent $candidate): bool => $candidate->score >= ($topScore - 1),
        ));

        return $topCandidates[mt_rand(0, count($topCandidates) - 1)];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function executeChaosEvent(
        ChaosEventRecord $event,
        ChaosClusterView $view,
        ChaosRuntimeState $runtime,
        CommandLineOptions $options,
        array $metadata,
        bool $tls,
        ?string $caCert,
    ): void {
        switch ($event->category) {
            case ChaosOptions::CATEGORY_REPLICA_KILL:
                if ($event->targetPort === null) {
                    throw new RuntimeException('replica-kill is missing a target port.');
                }

                $this->output->step(sprintf('Stopping replica %d', $event->targetPort));
                $this->redisNodeClient->shutdown($event->targetPort, $tls, $caCert);
                $this->systemInspector->waitForPortsToClose([$event->targetPort]);
                break;

            case ChaosOptions::CATEGORY_REPLICA_RESTART:
                if ($event->targetPort === null) {
                    throw new RuntimeException('replica-restart is missing a target port.');
                }

                $this->restartReplicaPort($event->targetPort, $metadata, $options, $tls, $caCert);
                break;

            case ChaosOptions::CATEGORY_REPLICA_ADD:
                if ($event->targetPrimaryPort === null || $event->targetPort === null) {
                    throw new RuntimeException('replica-add is missing a target primary or target port.');
                }

                $rawShards = $this->readClusterShardsWithFallback($view->seedPort, $tls, $caCert);
                $shards = $this->clusterShardsParser->parse($rawShards);
                $primaryNode = $this->findPrimaryNodeByPort($shards, $event->targetPrimaryPort);
                if (!$primaryNode instanceof ClusterNodeStatus) {
                    throw new RuntimeException(sprintf('Unable to resolve primary %d for replica-add.', $event->targetPrimaryPort));
                }

                [, , $tlsMaterial] = $this->resolveSeedConnectionContext($view->seedPort, $metadata);
                $this->createReplicaForPrimary(
                    options: $options,
                    metadata: $metadata,
                    seedPort: $view->seedPort,
                    primaryNode: $primaryNode,
                    tls: $tls,
                    caCert: $caCert,
                    tlsMaterial: $tlsMaterial,
                    rawShards: $shards,
                    replicaPort: $event->targetPort,
                );
                $runtime->rememberReplicaPrimary($event->targetPort, $event->targetPrimaryPort);
                break;

            default:
                throw new RuntimeException(sprintf('Unsupported chaos event category: %s', $event->category));
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function waitForChaosEventConvergence(
        ChaosEventRecord $event,
        ChaosRuntimeState $runtime,
        array $metadata,
        int $seedPort,
        bool $tls,
        ?string $caCert,
        ChaosOptions $chaos,
    ): ChaosEventRecord {
        $deadline = microtime(true) + $chaos->waitTimeoutSeconds;
        $stablePolls = 0;
        $lastTopologyHash = null;

        while (microtime(true) < $deadline) {
            $view = $this->discoverChaosClusterView($runtime, $metadata, $seedPort, $tls, $caCert);
            $postconditionSatisfied = $this->isChaosEventPostconditionSatisfied($event, $view);

            if ($chaos->watch) {
                $this->emitChaosWatchLine($chaos, $this->formatChaosWaitLine($event, $view));
            }

            if ($postconditionSatisfied) {
                if ($lastTopologyHash === $view->topologyHash) {
                    $stablePolls++;
                } else {
                    $stablePolls = 1;
                    $lastTopologyHash = $view->topologyHash;
                }

                if ($stablePolls >= self::CHAOS_STABLE_POLLS) {
                    $runtime->lastStableTopologyHash = $view->topologyHash;

                    return $event->withStatus('completed', microtime(true));
                }
            } else {
                $stablePolls = 0;
                $lastTopologyHash = null;
            }

            sleep(1);
        }

        throw new RuntimeException(sprintf(
            'Timed out waiting for %s (%s).',
            $event->summary,
            $event->postcondition,
        ));
    }

    private function isChaosEventPostconditionSatisfied(ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        return match ($event->category) {
            ChaosOptions::CATEGORY_REPLICA_KILL => $this->isReplicaKillSatisfied($event, $view),
            ChaosOptions::CATEGORY_REPLICA_RESTART => $this->isReplicaRestartSatisfied($event, $view),
            ChaosOptions::CATEGORY_REPLICA_ADD => $this->isReplicaAddSatisfied($event, $view),
            default => false,
        };
    }

    private function isReplicaKillSatisfied(ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        $primaryPort = $event->targetPrimaryPort;
        $primary = $primaryPort !== null ? ($view->primaryStateByPort[$primaryPort] ?? null) : null;
        if (!$primary instanceof ChaosPrimaryState || !$primary->reachable || $view->clusterDown) {
            return false;
        }

        $target = $event->targetPort !== null ? ($view->nodeStateByPort[$event->targetPort] ?? null) : null;
        if (!$target instanceof ChaosNodeState) {
            return $event->targetPort !== null && !$this->systemInspector->isPortListening($event->targetPort);
        }

        return !$target->reachable || $target->isFailed;
    }

    private function isReplicaRestartSatisfied(ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        $target = $event->targetPort !== null ? ($view->nodeStateByPort[$event->targetPort] ?? null) : null;
        if (!$target instanceof ChaosNodeState) {
            return false;
        }

        return $target->role === 'replica'
            && $target->reachable
            && !$target->isFailed
            && !$target->isHandshake
            && $target->primaryPort === $event->targetPrimaryPort;
    }

    private function isReplicaAddSatisfied(ChaosEventRecord $event, ChaosClusterView $view): bool
    {
        return $this->isReplicaRestartSatisfied($event, $view);
    }

    private function emitChaosWatchLine(ChaosOptions $chaos, string $message): void
    {
        if (!$chaos->watch && !str_starts_with($message, '[chaos]') && !str_starts_with($message, '[plan ]') && !str_starts_with($message, '[done ]')) {
            return;
        }

        $this->output->info($message);
    }

    private function formatChaosWaitLine(ChaosEventRecord $event, ChaosClusterView $view): string
    {
        $target = $event->targetPort !== null ? ($view->nodeStateByPort[$event->targetPort] ?? null) : null;
        $primary = $event->targetPrimaryPort !== null ? ($view->primaryStateByPort[$event->targetPrimaryPort] ?? null) : null;
        $targetReachable = $target instanceof ChaosNodeState ? ($target->reachable ? '1' : '0') : '0';
        $targetFailed = $target instanceof ChaosNodeState ? ($target->isFailed ? '1' : '0') : '0';
        $primaryHealthy = $primary instanceof ChaosPrimaryState ? ($primary->reachable ? '1' : '0') : '0';

        return sprintf(
            '[wait ] event#%d target=%s reachable=%s failed=%s primary=%s healthy=%s degraded=%s',
            $event->id,
            $event->targetPort !== null ? (string) $event->targetPort : '-',
            $targetReachable,
            $targetFailed,
            $event->targetPrimaryPort !== null ? (string) $event->targetPrimaryPort : '-',
            $primaryHealthy,
            $view->degradedPrimaryPorts === [] ? '-' : implode(',', array_map('strval', $view->degradedPrimaryPorts)),
        );
    }

    private function sleepBetweenChaosSteps(ChaosOptions $chaos): void
    {
        if ($chaos->cooldownSeconds > 0) {
            sleep($chaos->cooldownSeconds);
        }

        $remainingInterval = max(0, $chaos->intervalSeconds - $chaos->cooldownSeconds);
        if ($remainingInterval > 0) {
            sleep($remainingInterval);
        }
    }

    /**
     * @param array<int, ChaosNodeState> $nodeStateByPort
     * @param array<int, ChaosPrimaryState> $primaryStateByPort
     */
    private function buildChaosTopologyHash(array $nodeStateByPort, array $primaryStateByPort, bool $clusterDown): string
    {
        $parts = [$clusterDown ? 'down' : 'ok'];
        foreach ($nodeStateByPort as $port => $node) {
            $parts[] = implode(':', [
                (string) $port,
                $node->role,
                $node->primaryPort !== null ? (string) $node->primaryPort : '-',
                $node->reachable ? '1' : '0',
                $node->isFailed ? '1' : '0',
                $node->isSyncing ? '1' : '0',
                implode(',', $node->slotRanges),
            ]);
        }

        foreach ($primaryStateByPort as $port => $primary) {
            $parts[] = implode(':', [
                'p',
                (string) $port,
                (string) $primary->healthyReplicaCount,
                (string) $primary->syncingReplicaCount,
                (string) $primary->failedReplicaCount,
            ]);
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryFetchNodeInfo(int $port, bool $tls, ?string $caCert): ?array
    {
        try {
            return $this->redisNodeClient->fetchInfo($port, $tls, $caCert);
        } catch (\Throwable) {
            try {
                return $this->redisNodeClient->fetchInfo($port, !$tls, $caCert);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /**
     * @param array<string, mixed>|null $info
     * @param list<string> $slotRanges
     */
    private function buildChaosNodeState(
        int $port,
        string $nodeId,
        string $role,
        ?int $primaryPort,
        bool $knownByCluster,
        bool $reachable,
        string $health,
        array $slotRanges,
        bool $managed,
        ?array $info,
        ?string $clusterDir,
    ): ChaosNodeState {
        $isLoading = $this->readInfoBool($info, 'loading');
        $linkStatus = $role === 'replica' ? $this->readInfoString($info, 'master_link_status') : '';
        $isSyncing = $role === 'replica'
            && (
                $this->readInfoBool($info, 'master_sync_in_progress')
                || ($linkStatus !== '' && $linkStatus !== 'up')
            );

        return new ChaosNodeState(
            port: $port,
            nodeId: $nodeId,
            role: $role,
            primaryPort: $primaryPort,
            knownByCluster: $knownByCluster,
            reachable: $reachable,
            isFailed: $health === 'fail',
            isHandshake: false,
            isLoading: $isLoading,
            isSyncing: $isSyncing,
            linkStatus: $linkStatus,
            slotRanges: $slotRanges,
            pid: $clusterDir !== null ? $this->readNodePid($clusterDir, $port) : null,
            managed: $managed,
            health: $health,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return list<int>
     */
    private function readManagedPorts(array $metadata): array
    {
        $ports = $metadata['ports'] ?? [];
        if (!is_array($ports)) {
            return [];
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

        return $normalized;
    }

    private function selectChaosReplicaPort(ChaosClusterView $view): ?int
    {
        $usedPorts = array_map('intval', array_keys($view->nodeStateByPort));
        if ($usedPorts === []) {
            return null;
        }

        return $this->selectReplicaPortOutsideClusterRange($usedPorts);
    }

    /**
     * @return array<string, string>
     */
    private function readClusterInfoWithFallback(int $seedPort, bool $tls, ?string $caCert): array
    {
        try {
            return $this->redisNodeClient->fetchClusterInfo($seedPort, $tls, $caCert);
        } catch (\Throwable) {
            return $this->redisNodeClient->fetchClusterInfo($seedPort, !$tls, $caCert);
        }
    }

    /**
     * @param array<string, mixed>|null $info
     */
    private function readInfoString(?array $info, string $key): string
    {
        $value = $info[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed>|null $info
     */
    private function readInfoBool(?array $info, string $key): bool
    {
        $value = $info[$key] ?? null;

        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_string($value) => in_array(strtolower($value), ['1', 'yes', 'true'], true),
            default => false,
        };
    }

    private function readNodePid(string $clusterDir, int $port): ?int
    {
        $pidPath = sprintf('%s/node-%d/redis.pid', rtrim($clusterDir, '/'), $port);
        if (!is_file($pidPath)) {
            return null;
        }

        $pid = trim((string) file_get_contents($pidPath));
        if (!preg_match('/^\d+$/', $pid)) {
            return null;
        }

        return (int) $pid;
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
     * @param array<string, mixed>|null $metadata
     * @return array{bool, ?string, array{ca_cert: string, server_cert: string, server_key: string}|null, array<mixed>}
     */
    private function resolveSeedConnectionContext(int $seedPort, ?array $metadata): array
    {
        $defaultTls = is_array($metadata) ? (bool) ($metadata['tls'] ?? false) : false;
        $caCert = is_array($metadata) && is_array($metadata['tls_material'] ?? null)
            ? (is_string($metadata['tls_material']['ca_cert'] ?? null) ? $metadata['tls_material']['ca_cert'] : null)
            : null;
        $tlsMaterial = is_array($metadata) && is_array($metadata['tls_material'] ?? null)
            && is_string($metadata['tls_material']['ca_cert'] ?? null)
            && is_string($metadata['tls_material']['server_cert'] ?? null)
            && is_string($metadata['tls_material']['server_key'] ?? null)
            ? $metadata['tls_material']
            : null;

        $modes = [$defaultTls, !$defaultTls];
        foreach ($modes as $mode) {
            try {
                $rawShards = $this->redisNodeClient->fetchClusterShards($seedPort, $mode, $caCert);

                return [$mode, $caCert, $tlsMaterial, $rawShards];
            } catch (\Throwable) {
                // Try alternate mode.
            }
        }

        throw new RuntimeException(sprintf('Unable to read cluster state from seed port %d.', $seedPort));
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function findPrimaryNodeByPort(array $shards, int $port): ?ClusterNodeStatus
    {
        foreach ($shards as $shard) {
            if ($shard->master->port === $port) {
                return $shard->master;
            }
        }

        return null;
    }

    /**
     * @param list<ClusterShardStatus> $shards
     */
    private function selectPrimaryForReplicaCreation(array $shards, int $seedPort): ?ClusterNodeStatus
    {
        if ($this->clusterTreeSelector->supportsInteractiveSelection()) {
            return $this->clusterTreeSelector->select(
                shards: $shards,
                seedPort: $seedPort,
                mode: ClusterTreeViewMode::PrimariesOnly,
                title: 'Select a primary to receive a new replica',
            );
        }

        $primaryNode = $this->findPrimaryNodeByPort($shards, $seedPort);
        if ($primaryNode instanceof ClusterNodeStatus) {
            return $primaryNode;
        }

        throw new RuntimeException('add-replica needs an interactive TTY when the provided seed port is not already a primary.');
    }

    /**
     * @param list<ClusterShardStatus> $shards
     * @return list<int>
     */
    private function extractClusterPorts(array $shards): array
    {
        $ports = [];
        foreach ($shards as $shard) {
            $ports[$shard->master->port] = true;
            foreach ($shard->replicas as $replica) {
                $ports[$replica->port] = true;
            }
        }

        $allPorts = array_map('intval', array_keys($ports));
        sort($allPorts, SORT_NUMERIC);

        return $allPorts;
    }

    /**
     * @param list<int> $usedPorts
     */
    private function selectReplicaPortOutsideClusterRange(array $usedPorts): int
    {
        if ($usedPorts === []) {
            throw new RuntimeException('Unable to auto-select replica port without discovered cluster ports.');
        }

        $used = array_fill_keys($usedPorts, true);
        $maxPort = max($usedPorts);
        for ($candidate = $maxPort + 1; $candidate <= 65535; $candidate++) {
            if (isset($used[$candidate])) {
                continue;
            }

            if ($this->systemInspector->isPortListening($candidate)) {
                continue;
            }

            return $candidate;
        }

        throw new RuntimeException('Unable to find an available replica port.');
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function resolveReplicaClusterDirectory(int $primaryPort, bool $tls, ?string $caCert, ?array $metadata): string
    {
        $primaryNodeDir = $this->readConfigDirWithFallback($primaryPort, $tls, $caCert);
        $defaultStateRoot = sprintf('%s/manage-cluster', rtrim(sys_get_temp_dir(), '/'));
        $normalizedNodeDir = rtrim($primaryNodeDir, '/');

        if (str_starts_with($normalizedNodeDir, $defaultStateRoot . '/')) {
            $nodeBase = basename($normalizedNodeDir);
            if (preg_match('/^node-\d+$/', $nodeBase) === 1) {
                return dirname($normalizedNodeDir);
            }

            return $normalizedNodeDir;
        }

        if (is_array($metadata) && is_string($metadata['cluster_dir'] ?? null) && $metadata['cluster_dir'] !== '') {
            return $metadata['cluster_dir'];
        }

        return $this->stateStore->createClusterDirectory();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveExistingNodeConfigPath(array $metadata, int $port): string
    {
        $clusterDir = $metadata['cluster_dir'] ?? null;
        if (!is_string($clusterDir) || $clusterDir === '') {
            throw new RuntimeException(sprintf('Cluster metadata is missing cluster_dir for replica %d.', $port));
        }

        $configPath = sprintf('%s/node-%d/redis.conf', rtrim($clusterDir, '/'), $port);
        if (!is_file($configPath)) {
            throw new RuntimeException(sprintf('Replica config not found for port %d: %s', $port, $configPath));
        }

        return $configPath;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveRedisBinaryForRestart(array $metadata, CommandLineOptions $options): string
    {
        $binary = $metadata['redis_binary'] ?? null;
        if (!is_string($binary) || $binary === '') {
            $binary = $options->redisBinary;
        }

        $this->systemInspector->ensureExecutableExists($binary, 'redis-server');

        return $binary;
    }

    private function readConfigDirWithFallback(int $port, bool $tls, ?string $caCert): string
    {
        try {
            return $this->redisNodeClient->fetchConfigDir($port, $tls, $caCert);
        } catch (\Throwable) {
            return $this->redisNodeClient->fetchConfigDir($port, !$tls, $caCert);
        }
    }

    private function waitForReplicaAttachment(
        int $seedPort,
        int $primaryPort,
        int $replicaPort,
        bool $tls,
        ?string $caCert,
        float $seconds = 10.0,
    ): void {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            try {
                $rawShards = $this->redisNodeClient->fetchClusterShards($seedPort, $tls, $caCert);
            } catch (\Throwable) {
                usleep(100_000);
                continue;
            }

            $shards = $this->clusterShardsParser->parse($rawShards);
            foreach ($shards as $shard) {
                if ($shard->master->port !== $primaryPort) {
                    continue;
                }

                foreach ($shard->replicas as $replica) {
                    if ($replica->port === $replicaPort) {
                        return;
                    }
                }
            }

            usleep(100_000);
        }

        throw new RuntimeException(sprintf(
            'Timed out waiting for replica %d to attach to primary %d.',
            $replicaPort,
            $primaryPort,
        ));
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
        int $currentUsedBytes,
        int $targetUsedBytes,
        int $keysAdded,
        ?int $remainingSeconds,
        bool $singleLine,
    ): void {
        $percent = $targetUsedBytes > 0
            ? min(100.0, ($currentUsedBytes / $targetUsedBytes) * 100)
            : 100.0;

        $message = sprintf(
            '[%s %.0f%%] %s/%s, %s keys',
            $this->formatDuration($remainingSeconds ?? 0),
            $percent,
            $this->formatBytes($currentUsedBytes),
            $this->formatBytes($targetUsedBytes),
            number_format($keysAdded),
        );

        $this->output->progress($message, $singleLine);
    }

    private function formatDuration(int $seconds): string
    {
        $duration = max(0, $seconds);
        $hours = intdiv($duration, 3600);
        $minutes = intdiv($duration % 3600, 60);
        $seconds = $duration % 60;

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
     * @param array<string, mixed>|null $metadata
     */
    private function persistClusterMetadataPortAddition(?array $metadata, int $port): void
    {
        if (!is_array($metadata)) {
            return;
        }

        $ports = $metadata['ports'] ?? [];
        if (!is_array($ports)) {
            $ports = [];
        }

        $ports[] = $port;
        $normalized = [];
        foreach ($ports as $candidate) {
            if (!is_int($candidate) && !is_string($candidate)) {
                continue;
            }

            $normalized[] = (int) $candidate;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);
        $metadata['ports'] = $normalized;
        $this->stateStore->persistClusterMetadata($metadata);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function persistClusterMetadataPortRemoval(?array $metadata, int $port): void
    {
        if (!is_array($metadata)) {
            return;
        }

        $ports = $metadata['ports'] ?? null;
        if (!is_array($ports)) {
            return;
        }

        $normalized = [];
        foreach ($ports as $candidate) {
            if (!is_int($candidate) && !is_string($candidate)) {
                continue;
            }

            $candidatePort = (int) $candidate;
            if ($candidatePort === $port) {
                continue;
            }

            $normalized[] = $candidatePort;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        if ($normalized === []) {
            $this->stateStore->removeClusterMetadata($metadata);

            return;
        }

        $metadata['ports'] = $normalized;
        $this->stateStore->persistClusterMetadata($metadata);
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
     * @param array<int, Process> $processes
     */
    private function waitForStartProcesses(array $processes): void
    {
        $failures = [];
        foreach ($processes as $port => $process) {
            $process->wait();
            if ($process->isSuccessful()) {
                continue;
            }

            $output = trim($process->getErrorOutput() . "\n" . $process->getOutput());
            $failures[] = $output === ''
                ? sprintf('port %d exited with status %d', $port, $process->getExitCode() ?? -1)
                : sprintf('port %d exited with status %d: %s', $port, $process->getExitCode() ?? -1, $output);
        }

        if ($failures !== []) {
            throw new RuntimeException(sprintf(
                'Failed to start Redis node process%s: %s',
                count($failures) === 1 ? '' : 'es',
                implode('; ', $failures),
            ));
        }
    }

    /**
     * @param array<int, array{port: int, tls: bool, ca_cert: ?string}> $targets
     * @return array<int, Process>
     */
    private function startShutdownProcesses(string $redisCliBinary, array $targets): array
    {
        $processes = [];
        foreach ($targets as $port => $target) {
            $process = new Process($this->buildRedisCliShutdownCommand(
                redisCliBinary: $redisCliBinary,
                port: $target['port'],
                tls: $target['tls'],
                caCert: $target['ca_cert'],
            ));
            $process->start();

            $processes[$port] = $process;
        }

        return $processes;
    }

    /**
     * @param array<int, Process> $processes
     */
    private function waitForShutdownProcesses(array $processes): void
    {
        /** @var array<string, array{ports: list<int>, exit_code: int, output: string}> $failures */
        $failures = [];
        foreach ($processes as $port => $process) {
            $process->wait();
            if ($process->isSuccessful()) {
                continue;
            }

            $output = trim($process->getErrorOutput() . "\n" . $process->getOutput());
            $exitCode = $process->getExitCode() ?? -1;
            $key = sprintf('%d|%s', $exitCode, $output);
            if (!isset($failures[$key])) {
                $failures[$key] = [
                    'ports' => [],
                    'exit_code' => $exitCode,
                    'output' => $output,
                ];
            }

            $failures[$key]['ports'][] = $port;
        }

        foreach ($failures as $failure) {
            sort($failure['ports'], SORT_NUMERIC);
            $this->output->warning($this->formatShutdownFailureMessage(
                ports: $failure['ports'],
                exitCode: $failure['exit_code'],
                output: $failure['output'],
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function buildRedisCliShutdownCommand(string $redisCliBinary, int $port, bool $tls, ?string $caCert): array
    {
        $command = [
            $redisCliBinary,
            '-h',
            '127.0.0.1',
            '-p',
            (string) $port,
        ];

        if ($tls) {
            $command[] = '--tls';
            if ($caCert !== null) {
                $command[] = '--cacert';
                $command[] = $caCert;
            }
        }

        $command[] = 'SHUTDOWN';
        $command[] = 'NOSAVE';

        return $command;
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

    private function renderManagedClusterSummary(bool $watchMode, bool $runningOnly): void
    {
        while (true) {
            $clusters = $this->buildManagedClusterSummaries($runningOnly);

            if ($watchMode) {
                $this->clearTerminal();
            }

            fwrite(STDOUT, $this->managedClusterSummaryRenderer->render(
                clusters: $clusters,
                width: $this->detectTerminalWidth(),
                watchMode: $watchMode,
                runningOnly: $runningOnly,
            ));

            if (!$watchMode) {
                return;
            }

            usleep(1_000_000);
        }
    }

    /**
     * @return list<array{
     *   id: string,
     *   seed_port: int,
     *   port_range: string,
     *   total_nodes: int,
     *   listening_nodes: int,
     *   replicas: int,
     *   tls: bool
     * }>
     */
    private function buildManagedClusterSummaries(bool $runningOnly): array
    {
        $summaries = [];
        foreach ($this->stateStore->listClusters() as $metadata) {
            $ports = $this->normalizeClusterPorts($metadata['ports'] ?? null);
            if ($ports === []) {
                continue;
            }

            $listeningNodes = 0;
            foreach ($ports as $port) {
                if ($this->systemInspector->isPortListening($port)) {
                    $listeningNodes++;
                }
            }

            if ($runningOnly && $listeningNodes === 0) {
                continue;
            }

            $summaries[] = [
                'id' => is_string($metadata['id'] ?? null) ? $metadata['id'] : sprintf('seed-%d', $ports[0]),
                'seed_port' => $ports[0],
                'port_range' => $this->formatPortRange($ports),
                'total_nodes' => count($ports),
                'listening_nodes' => $listeningNodes,
                'replicas' => is_int($metadata['replicas'] ?? null) ? $metadata['replicas'] : (int) ($metadata['replicas'] ?? 0),
                'tls' => (bool) ($metadata['tls'] ?? false),
            ];
        }

        usort(
            $summaries,
            static fn (array $left, array $right): int => $left['seed_port'] <=> $right['seed_port'],
        );

        return $summaries;
    }

    /**
     * @param mixed $ports
     * @return list<int>
     */
    private function normalizeClusterPorts(mixed $ports): array
    {
        if (!is_array($ports)) {
            return [];
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

        return $normalized;
    }

    /**
     * @param list<int> $ports
     */
    private function formatPortRange(array $ports): string
    {
        return PortRangeFormatter::formatRange($ports);
    }

    /**
     * @param list<int> $ports
     */
    private function formatCompactPortList(array $ports): string
    {
        return PortRangeFormatter::formatCompactList($ports);
    }

    /**
     * @param list<int> $ports
     */
    private function formatShutdownFailureMessage(array $ports, int $exitCode, string $output): string
    {
        $subject = count($ports) === 1 ? 'process' : 'processes';
        $target = count($ports) === 1 ? 'port' : 'ports';
        $message = sprintf(
            'SHUTDOWN %s for %s %s exited with status %d',
            $subject,
            $target,
            $this->formatCompactPortList($ports),
            $exitCode,
        );

        if ($output === '') {
            return $message;
        }

        return sprintf('%s: %s', $message, $output);
    }

    private function clearTerminal(): void
    {
        if ($this->isInteractiveStdout()) {
            fwrite(STDOUT, "\033[H\033[2J");
        }
    }

    private function isInteractiveStdout(): bool
    {
        return $this->output->isInteractive();
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
