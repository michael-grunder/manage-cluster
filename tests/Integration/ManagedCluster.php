<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests\Integration;

use Mgrunder\CreateCluster\ClusterShardsParser;
use Mgrunder\CreateCluster\ClusterShardStatus;
use Mgrunder\CreateCluster\PortParser;
use Mgrunder\CreateCluster\RedisNodeClient;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A real cluster for the integration suite to churn.
 *
 * By default the fixture provisions a throwaway cluster on a free contiguous
 * port range with its own state directory, so a run can execute destructive
 * events without touching anything the developer cares about. Setting
 * `MANAGE_CLUSTER_IT_PORTS` attaches to a cluster that is already running
 * instead, which makes iterating faster at the cost of permanently reshaping
 * that cluster.
 */
final class ManagedCluster
{
    /**
     * Redis derives the cluster bus port by adding this to the client port, so
     * a candidate range is only usable when both halves are free.
     */
    private const int CLUSTER_BUS_PORT_OFFSET = 10000;

    private const string PORTS_ENV = 'MANAGE_CLUSTER_IT_PORTS';

    private const string STATE_DIR_ENV = 'MANAGE_CLUSTER_IT_STATE_DIR';

    /**
     * Where the search for a free range starts. Deliberately clear of the
     * 7000-range clusters a developer is likely to have running by hand.
     */
    private const int SEARCH_FIRST_PORT = 7300;

    private const int SEARCH_LAST_PORT = 7900;

    private bool $stopped = false;

    /**
     * @param list<int> $ports
     */
    private function __construct(
        public int $seedPort,
        public array $ports,
        public string $stateDir,
        private bool $ownsCluster,
    ) {
    }

    /**
     * Attach to the cluster named by `MANAGE_CLUSTER_IT_PORTS`, or start a
     * fresh one sized to the requested shape.
     */
    public static function acquire(int $primaries = 3, int $replicasPerPrimary = 1): self
    {
        $configuredPorts = getenv(self::PORTS_ENV);
        $cluster = is_string($configuredPorts) && $configuredPorts !== ''
            ? self::attach($configuredPorts)
            : self::provision($primaries, $replicasPerPrimary);

        $cluster->waitUntilSettled();

        return $cluster;
    }

    private static function attach(string $spec): self
    {
        $ports = PortParser::parse([$spec]);
        if ($ports === []) {
            throw new RuntimeException(sprintf('%s did not resolve to any ports.', self::PORTS_ENV));
        }

        $stateDir = getenv(self::STATE_DIR_ENV);

        return new self(
            seedPort: $ports[0],
            ports: $ports,
            stateDir: is_string($stateDir) && $stateDir !== '' ? $stateDir : '/tmp/manage-cluster',
            ownsCluster: false,
        );
    }

    private static function provision(int $primaries, int $replicasPerPrimary): self
    {
        $nodeCount = $primaries * (1 + $replicasPerPrimary);
        $seedPort = self::findFreePortRange($nodeCount);

        $stateDir = sys_get_temp_dir() . '/manage-cluster-it-' . bin2hex(random_bytes(6));
        if (!mkdir($stateDir, 0o700, true) && !is_dir($stateDir)) {
            throw new RuntimeException(sprintf('Unable to create integration state directory %s.', $stateDir));
        }

        $cluster = new self(
            seedPort: $seedPort,
            ports: range($seedPort, $seedPort + $nodeCount - 1),
            stateDir: $stateDir,
            ownsCluster: true,
        );

        $cluster->run([
            'start',
            (string) $seedPort,
            '--primaries',
            (string) $primaries,
            '--replicas',
            (string) $replicasPerPrimary,
        ], timeoutSeconds: 120.0);

        return $cluster;
    }

    /**
     * Block until every node agrees the cluster is up.
     *
     * `start` returns once the slots are assigned, which is a moment before the
     * nodes have gossiped their way to `cluster_state:ok`. Chaos refuses to run
     * against a cluster that still reports CLUSTERDOWN, so a test that begins
     * the instant `start` returns races that window.
     */
    public function waitUntilSettled(float $timeoutSeconds = 30.0): void
    {
        $client = new RedisNodeClient();
        $deadline = microtime(true) + $timeoutSeconds;
        $lastState = 'unknown';

        while (microtime(true) < $deadline) {
            $settled = true;
            foreach ($this->ports as $port) {
                try {
                    $info = $client->fetchClusterInfo($port, false, null);
                } catch (\Throwable) {
                    $settled = false;
                    break;
                }

                $lastState = $info['cluster_state'] ?? 'unknown';
                if ($lastState !== 'ok') {
                    $settled = false;
                    break;
                }
            }

            if ($settled) {
                return;
            }

            usleep(200_000);
        }

        throw new RuntimeException(sprintf(
            'Cluster seeded at %d never reached cluster_state:ok (last saw %s).',
            $this->seedPort,
            $lastState,
        ));
    }

    /**
     * Stop and delete a provisioned cluster. Attached clusters are left alone;
     * the developer owns those.
     */
    public function shutdown(): void
    {
        if (!$this->ownsCluster || $this->stopped) {
            return;
        }

        $this->stopped = true;

        // primary-remove can retire the seed port itself, so name every port
        // the fixture started with: `stop` resolves the cluster from whichever
        // of them the state store still knows, including any primary that
        // primary-add joined outside the original range.
        $this->run(
            ['stop', ...array_map(strval(...), $this->ports)],
            timeoutSeconds: 60.0,
            mustSucceed: false,
        );

        // Anything still listening was outside the state store's view.
        foreach ($this->livePorts() as $port) {
            try {
                (new RedisNodeClient())->shutdown($port, false, null);
            } catch (\Throwable) {
                // Already gone, or never ours to stop.
            }
        }

        self::removeDirectory($this->stateDir);
    }

    /**
     * Run one chaos event of a single category and return the combined output.
     * `--abort-on-failure` turns a broken category into a fast, loud failure
     * instead of a run that quietly retries until the test times out.
     *
     * @param list<string> $extraArgs
     */
    public function runChaosEvent(string $category, array $extraArgs = [], float $timeoutSeconds = 180.0): string
    {
        return $this->run([
            'chaos',
            (string) $this->seedPort,
            '--categories',
            $category,
            '--max-events',
            '1',
            '--interval',
            '1',
            '--cooldown',
            '0',
            '--abort-on-failure',
            ...$extraArgs,
        ], $timeoutSeconds);
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args, float $timeoutSeconds = 60.0, bool $mustSucceed = true): string
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/manage-cluster', ...$args, '--state-dir', $this->stateDir],
            timeout: $timeoutSeconds,
        );
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();
        if ($mustSucceed && !$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "manage-cluster %s failed with exit code %s:\n%s",
                implode(' ', $args),
                (string) $process->getExitCode(),
                $output,
            ));
        }

        return $output;
    }

    /**
     * The cluster's current shape, read straight from CLUSTER SHARDS through
     * the same parser the tool uses.
     *
     * @return list<ClusterShardStatus>
     */
    public function shards(): array
    {
        $client = new RedisNodeClient();

        foreach ($this->livePorts() as $port) {
            try {
                return (new ClusterShardsParser())->parse($client->fetchClusterShards($port, false, null));
            } catch (\Throwable) {
                // Chaos removes and restarts nodes, so any single seed may be
                // gone by the time the assertion runs. Try the next one.
                continue;
            }
        }

        throw new RuntimeException('No cluster node answered CLUSTER SHARDS.');
    }

    /**
     * Map of primary port to the ports of the replicas following it.
     *
     * @return array<int, list<int>>
     */
    public function replicaPortsByPrimaryPort(): array
    {
        $topology = [];
        foreach ($this->shards() as $shard) {
            $replicaPorts = array_map(
                static fn ($replica): int => $replica->port,
                $shard->replicas,
            );
            sort($replicaPorts);
            $topology[$shard->master->port] = $replicaPorts;
        }

        ksort($topology);

        return $topology;
    }

    /**
     * @return list<int>
     */
    public function slotOwningPrimaryPorts(): array
    {
        $ports = [];
        foreach ($this->shards() as $shard) {
            if ($shard->ownsSlots()) {
                $ports[] = $shard->master->port;
            }
        }

        sort($ports);

        return $ports;
    }

    /**
     * Total slots currently claimed by some shard. Every completed chaos event
     * must leave this at the full slot space.
     */
    public function coveredSlotCount(): int
    {
        $covered = 0;
        foreach ($this->shards() as $shard) {
            $covered += $shard->slotCount();
        }

        return $covered;
    }

    /**
     * @return list<int>
     */
    private function livePorts(): array
    {
        $candidates = $this->ports;

        // primary-add joins a node above the original range; include a small
        // window past the end so assertions can still find a seed.
        $last = $candidates === [] ? $this->seedPort : max($candidates);
        for ($port = $last + 1; $port <= $last + 8; $port++) {
            $candidates[] = $port;
        }

        return array_values(array_filter(
            $candidates,
            static fn (int $port): bool => self::isPortOpen($port),
        ));
    }

    private static function findFreePortRange(int $count): int
    {
        for ($base = self::SEARCH_FIRST_PORT; $base + $count - 1 <= self::SEARCH_LAST_PORT; $base += $count) {
            if (self::isRangeFree($base, $count)) {
                return $base;
            }
        }

        throw new RuntimeException(sprintf(
            'No free %d-port range between %d and %d for an integration cluster.',
            $count,
            self::SEARCH_FIRST_PORT,
            self::SEARCH_LAST_PORT,
        ));
    }

    private static function isRangeFree(int $base, int $count): bool
    {
        for ($port = $base; $port < $base + $count; $port++) {
            if (self::isPortOpen($port) || self::isPortOpen($port + self::CLUSTER_BUS_PORT_OFFSET)) {
                return false;
            }
        }

        return true;
    }

    private static function isPortOpen(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
