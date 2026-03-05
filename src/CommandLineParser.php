<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final class CommandLineParser
{
    /**
     * @param list<string> $argv
     */
    public function parse(array $argv): CommandLineOptions
    {
        $action = null;
        $portTokens = [];

        $replicas = 0;
        $redisBinary = getenv('BIN_REDIS') ?: 'redis-server';
        $redisCliBinary = 'redis-cli';
        $announceIp = null;
        $tls = false;
        $tlsDays = 3650;
        $tlsRsaBits = 2048;
        $stateDir = sprintf('%s/manage-cluster', sys_get_temp_dir());
        $watch = false;

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            switch ($arg) {
                case '--help':
                case '-h':
                    throw new InvalidArgumentException(self::usage());

                case '--start':
                case '--stop':
                case '--rebalance':
                case '--status':
                case '--flush':
                    if ($action !== null) {
                        throw new InvalidArgumentException('Only one action may be used: --start, --stop, --rebalance, --status, or --flush.');
                    }

                    $action = ltrim($arg, '-');
                    break;

                case '--replicas':
                    $replicas = $this->parseIntOption($argv, ++$i, '--replicas');
                    break;

                case '--binary':
                    $redisBinary = $this->parseStringOption($argv, ++$i, '--binary');
                    break;

                case '--redis-cli':
                    $redisCliBinary = $this->parseStringOption($argv, ++$i, '--redis-cli');
                    break;

                case '--cluster-announce-ip':
                    $announceIp = $this->parseStringOption($argv, ++$i, '--cluster-announce-ip');
                    break;

                case '--tls':
                    $tls = true;
                    break;

                case '--tls-days':
                    $tlsDays = $this->parseIntOption($argv, ++$i, '--tls-days');
                    break;

                case '--tls-rsa-bits':
                    $tlsRsaBits = $this->parseIntOption($argv, ++$i, '--tls-rsa-bits');
                    break;

                case '--state-dir':
                    $stateDir = $this->parseStringOption($argv, ++$i, '--state-dir');
                    break;

                case '--watch':
                    $watch = true;
                    break;

                default:
                    if (str_starts_with($arg, '-')) {
                        throw new InvalidArgumentException(sprintf('Unknown option: %s', $arg));
                    }

                    if ($action === null) {
                        if (self::isActionToken($arg)) {
                            $action = $arg;
                            break;
                        }

                        throw new InvalidArgumentException(sprintf('Specify start/stop/rebalance/status/flush (or --start/--stop/--rebalance/--status/--flush) before ports (got: %s).', $arg));
                    }

                    $portTokens[] = $arg;
                    break;
            }
        }

        if ($action === null) {
            throw new InvalidArgumentException('Missing action: use start/stop/rebalance/status/flush (or --start/--stop/--rebalance/--status/--flush).');
        }

        if ($action === 'start' && $replicas < 0) {
            throw new InvalidArgumentException('--replicas must be >= 0.');
        }

        if ($tlsDays <= 0) {
            throw new InvalidArgumentException('--tls-days must be > 0.');
        }

        if ($tlsRsaBits < 1024) {
            throw new InvalidArgumentException('--tls-rsa-bits must be >= 1024.');
        }

        if ($watch && $action !== 'status') {
            throw new InvalidArgumentException('--watch can only be used with status.');
        }

        $ports = PortParser::parse($portTokens);
        if ($action === 'start' && count($ports) === 1) {
            $ports = $this->expandSingleStartPort($ports[0], $replicas);
        }

        if ($action === 'status' && count($ports) !== 1) {
            throw new InvalidArgumentException('status expects exactly one seed port.');
        }

        return new CommandLineOptions(
            action: $action,
            ports: $ports,
            replicas: $replicas,
            redisBinary: $redisBinary,
            redisCliBinary: $redisCliBinary,
            announceIp: $announceIp,
            tls: $tls,
            tlsDays: $tlsDays,
            tlsRsaBits: $tlsRsaBits,
            stateDir: $stateDir,
            watch: $watch,
        );
    }

    public static function usage(): string
    {
        return <<<'TXT'
Usage:
  bin/manage-cluster start PORT [PORT ...] [--replicas N] [--tls]
  bin/manage-cluster stop PORT [PORT ...]
  bin/manage-cluster rebalance PORT [PORT ...]
  bin/manage-cluster status PORT [--watch]
  bin/manage-cluster flush PORT [PORT ...]
  bin/manage-cluster --start PORT [PORT ...] [--replicas N] [--tls]
  bin/manage-cluster --stop PORT [PORT ...]
  bin/manage-cluster --rebalance PORT [PORT ...]
  bin/manage-cluster --status PORT [--watch]
  bin/manage-cluster --flush PORT [PORT ...]

Options:
  --binary PATH                Path to redis-server (default: redis-server)
  --redis-cli PATH             Path to redis-cli (default: redis-cli)
  --replicas N                 Number of replicas per master for start
  --cluster-announce-ip IP     Announce IP for the cluster nodes
  --tls                        Enable TLS-only redis instances
  --tls-days N                 TLS certificate validity in days (default: 3650)
  --tls-rsa-bits N             RSA key size (default: 2048)
  --state-dir PATH             Cluster metadata root (default: /tmp/manage-cluster)
  --watch                      Refresh status output every second (status only)
  -h, --help                   Show this help text

Port tokens can be provided as:
  7000 7001 7002
  7000-7008
  {7000..7008}

For start only:
  A single seed port auto-expands to contiguous ports based on replicas.
  Default replicas (0) expands to 4 ports.
  Example: start 7000 --replicas 2 => {7000..7008}
TXT;
    }

    private static function isActionToken(string $value): bool
    {
        return in_array($value, ['start', 'stop', 'rebalance', 'status', 'flush'], true);
    }

    /**
     * @return list<int>
     */
    private function expandSingleStartPort(int $seedPort, int $replicas): array
    {
        $requiredNodeCount = $replicas === 0
            ? 4
            : (3 * ($replicas + 1));
        $endPort = $seedPort + $requiredNodeCount - 1;

        if ($endPort > 65535) {
            throw new InvalidArgumentException(sprintf(
                'Seed port %d with --replicas %d exceeds max port when expanded (%d).',
                $seedPort,
                $replicas,
                $endPort,
            ));
        }

        /** @var list<int> $ports */
        $ports = range($seedPort, $endPort);

        return $ports;
    }

    /**
     * @param list<string> $argv
     */
    private function parseIntOption(array $argv, int $index, string $option): int
    {
        $value = $this->parseStringOption($argv, $index, $option);
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s expects an integer value.', $option));
        }

        return (int) $value;
    }

    /**
     * @param list<string> $argv
     */
    private function parseStringOption(array $argv, int $index, string $option): string
    {
        if (!isset($argv[$index]) || str_starts_with($argv[$index], '-')) {
            throw new InvalidArgumentException(sprintf('%s expects a value.', $option));
        }

        return $argv[$index];
    }
}
