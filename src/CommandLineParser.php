<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final class CommandLineParser
{
    private const int DEFAULT_FILL_MEMBERS = 8;
    private const int DEFAULT_FILL_MEMBER_SIZE = 256;
    private const int DEFAULT_FILL_TARGET_KEYS = 5000;
    private const int DEFAULT_FILL_TARGET_MEMBER_BYTES = 4096;
    private const int DEFAULT_FILL_MAX_MEMBERS = 256;

    /**
     * @param list<string> $argv
     */
    public function parse(array $argv): CommandLineOptions
    {
        $action = null;
        $portTokens = [];
        $replicaPort = null;
        $generatedScriptPath = null;

        $replicas = 0;
        $redisBinary = getenv('BIN_REDIS') ?: 'redis-server';
        $redisCliBinary = 'redis-cli';
        $announceIp = null;
        $tls = false;
        $tlsDays = 3650;
        $tlsRsaBits = 2048;
        $stateDir = sprintf('%s/manage-cluster', sys_get_temp_dir());
        $watch = false;
        $startServerArgs = [];
        $size = null;
        $types = ['string', 'set', 'list', 'hash', 'zset'];
        $members = self::DEFAULT_FILL_MEMBERS;
        $memberSize = self::DEFAULT_FILL_MEMBER_SIZE;
        $fillKeys = self::DEFAULT_FILL_TARGET_KEYS;
        $pinPrimaryPort = null;
        $typesProvided = false;
        $membersProvided = false;
        $memberSizeProvided = false;
        $fillKeysProvided = false;

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            switch ($arg) {
                case '--':
                    $startServerArgs = array_slice($argv, $i + 1);
                    $i = count($argv);
                    break;

                case '--help':
                case '-h':
                    throw new InvalidArgumentException(self::usage());

                case '--start':
                case '--stop':
                case '--kill':
                case '--rebalance':
                case '--status':
                case '--flush':
                case '--fill':
                case '--add-replica':
                case '--restart-replica':
                    if ($action !== null) {
                        throw new InvalidArgumentException('Only one action may be used: --start, --stop, --kill, --rebalance, --status, --flush, --fill, --add-replica, or --restart-replica.');
                    }

                    $action = ltrim($arg, '-');
                    break;

                case '--replicas':
                    $replicas = $this->parseIntOption($argv, ++$i, '--replicas');
                    break;

                case '--gen-script':
                    $generatedScriptPath = $this->parseStringOption($argv, ++$i, '--gen-script');
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

                case '--size':
                    $size = $this->parseStringOption($argv, ++$i, '--size');
                    break;

                case '--types':
                    $types = $this->parseTypes($this->parseStringOption($argv, ++$i, '--types'));
                    $typesProvided = true;
                    break;

                case '--members':
                    $members = $this->parseIntOption($argv, ++$i, '--members');
                    $membersProvided = true;
                    break;

                case '--member-size':
                    $memberSize = $this->parseIntOption($argv, ++$i, '--member-size');
                    $memberSizeProvided = true;
                    break;

                case '--keys':
                    $fillKeys = $this->parseIntOption($argv, ++$i, '--keys');
                    $fillKeysProvided = true;
                    break;

                case '--pin-primary':
                    $pinPrimaryPort = $this->parseIntOption($argv, ++$i, '--pin-primary');
                    break;

                case '--port':
                    $replicaPort = $this->parseIntOption($argv, ++$i, '--port');
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

                        throw new InvalidArgumentException(sprintf('Specify start/stop/kill/rebalance/status/flush/fill/add-replica/restart-replica (or --start/--stop/--kill/--rebalance/--status/--flush/--fill/--add-replica/--restart-replica) before ports (got: %s).', $arg));
                    }

                    $portTokens[] = $arg;
                    break;
            }
        }

        if ($action === null) {
            throw new InvalidArgumentException('Missing action: use start/stop/kill/rebalance/status/flush/fill/add-replica/restart-replica (or --start/--stop/--kill/--rebalance/--status/--flush/--fill/--add-replica/--restart-replica).');
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

        if ($startServerArgs !== [] && $action !== 'start') {
            throw new InvalidArgumentException('Arguments after -- can only be used with start.');
        }

        if ($members <= 0) {
            throw new InvalidArgumentException('--members must be > 0.');
        }

        if ($memberSize <= 0) {
            throw new InvalidArgumentException('--member-size must be > 0.');
        }

        if ($fillKeys <= 0) {
            throw new InvalidArgumentException('--keys must be > 0.');
        }

        if ($pinPrimaryPort !== null && ($pinPrimaryPort < 1 || $pinPrimaryPort > 65535)) {
            throw new InvalidArgumentException('--pin-primary must be a valid TCP port.');
        }

        if ($replicaPort !== null && ($replicaPort < 1 || $replicaPort > 65535)) {
            throw new InvalidArgumentException('--port must be a valid TCP port.');
        }

        if ($action !== 'fill' && $size !== null) {
            throw new InvalidArgumentException('--size can only be used with fill.');
        }

        if ($action !== 'fill' && $typesProvided) {
            throw new InvalidArgumentException('--types can only be used with fill.');
        }

        if ($action !== 'fill' && $membersProvided) {
            throw new InvalidArgumentException('--members can only be used with fill.');
        }

        if ($action !== 'fill' && $memberSizeProvided) {
            throw new InvalidArgumentException('--member-size can only be used with fill.');
        }

        if ($action !== 'fill' && $fillKeysProvided) {
            throw new InvalidArgumentException('--keys can only be used with fill.');
        }

        if ($action !== 'fill' && $pinPrimaryPort !== null) {
            throw new InvalidArgumentException('--pin-primary can only be used with fill.');
        }

        if ($action !== 'add-replica' && $replicaPort !== null) {
            throw new InvalidArgumentException('--port can only be used with add-replica.');
        }

        if ($action !== 'start' && $generatedScriptPath !== null) {
            throw new InvalidArgumentException('--gen-script can only be used with start.');
        }

        $ports = [];
        if ($portTokens !== []) {
            $ports = PortParser::parse($portTokens);
        }
        if ($action === 'start' && count($ports) === 1) {
            $ports = $this->expandSingleStartPort($ports[0], $replicas);
        }

        if ($action !== 'fill' && $ports === []) {
            throw new InvalidArgumentException('No ports provided');
        }

        if ($action === 'status' && count($ports) !== 1) {
            throw new InvalidArgumentException('status expects exactly one seed port.');
        }

        if ($action === 'kill' && count($ports) !== 1) {
            throw new InvalidArgumentException('kill expects exactly one seed port.');
        }

        if ($action === 'fill' && count($ports) > 1) {
            throw new InvalidArgumentException('fill expects zero or one seed port.');
        }

        if ($action === 'add-replica' && count($ports) !== 1) {
            throw new InvalidArgumentException('add-replica expects exactly one seed port.');
        }

        if ($action === 'restart-replica' && count($ports) !== 1) {
            throw new InvalidArgumentException('restart-replica expects exactly one seed port.');
        }

        $fillOptions = null;
        if ($action === 'fill') {
            if ($size === null || trim($size) === '') {
                throw new InvalidArgumentException('fill requires --size (for example: --size 1g).');
            }

            $sizeBytes = $this->parseSizeBytes($size);
            if (!$membersProvided && !$memberSizeProvided) {
                [$members, $memberSize] = $this->deriveAdaptiveFillShape($sizeBytes, $fillKeys);
            }

            $fillOptions = new FillOptions(
                sizeBytes: $sizeBytes,
                types: $types,
                members: $members,
                memberSize: $memberSize,
                pinPrimaryPort: $pinPrimaryPort,
            );
        }

        return new CommandLineOptions(
            action: $action,
            ports: $ports,
            replicaPort: $replicaPort,
            generatedScriptPath: $generatedScriptPath,
            replicas: $replicas,
            redisBinary: $redisBinary,
            redisCliBinary: $redisCliBinary,
            announceIp: $announceIp,
            tls: $tls,
            tlsDays: $tlsDays,
            tlsRsaBits: $tlsRsaBits,
            stateDir: $stateDir,
            watch: $watch,
            fill: $fillOptions,
            startServerArgs: $startServerArgs,
        );
    }

    public static function usage(): string
    {
        return <<<'TXT'
Usage:
  bin/manage-cluster start PORT [PORT ...] [--replicas N] [--tls] [--gen-script PATH] [-- REDIS_SERVER_ARG ...]
  bin/manage-cluster stop PORT [PORT ...]
  bin/manage-cluster kill PORT
  bin/manage-cluster rebalance PORT [PORT ...]
  bin/manage-cluster status PORT [--watch]
  bin/manage-cluster flush PORT [PORT ...]
  bin/manage-cluster fill [PORT] --size SIZE [--types CSV] [--members N] [--member-size N] [--keys N] [--pin-primary PORT]
  bin/manage-cluster add-replica SEED_PORT [--port PORT]
  bin/manage-cluster restart-replica SEED_PORT
  bin/manage-cluster --start PORT [PORT ...] [--replicas N] [--tls] [--gen-script PATH] [-- REDIS_SERVER_ARG ...]
  bin/manage-cluster --stop PORT [PORT ...]
  bin/manage-cluster --kill PORT
  bin/manage-cluster --rebalance PORT [PORT ...]
  bin/manage-cluster --status PORT [--watch]
  bin/manage-cluster --flush PORT [PORT ...]
  bin/manage-cluster --fill [PORT] --size SIZE [--types CSV] [--members N] [--member-size N] [--keys N] [--pin-primary PORT]
  bin/manage-cluster --add-replica SEED_PORT [--port PORT]
  bin/manage-cluster --restart-replica SEED_PORT

Options:
  --binary PATH                Path to redis-server (default: redis-server)
  --redis-cli PATH             Path to redis-cli (default: redis-cli)
  --replicas N                 Number of replicas per master for start
  --gen-script PATH            Write a shell script for start instead of launching now
  --cluster-announce-ip IP     Announce IP for the cluster nodes
  --tls                        Enable TLS-only redis instances
  --tls-days N                 TLS certificate validity in days (default: 3650)
  --tls-rsa-bits N             RSA key size (default: 2048)
  --state-dir PATH             Cluster metadata root (default: /tmp/manage-cluster)
  --watch                      Refresh status output every second (status only)
  --size SIZE                  Fill target memory (bytes, kb, mb, gb, tb)
  --types CSV                  Fill key types: string,set,list,hash,zset
  --members N                  Members per container key for fill (adaptive default from --size when both size knobs are omitted, otherwise 8)
  --member-size N              String size or per-key payload size in bytes (adaptive default from --size when both size knobs are omitted, otherwise 256)
  --keys N                     Adaptive key-count target for fill sizing (used only when both --members and --member-size are omitted; default: 5000)
  --pin-primary PORT           Pin generated keys to one primary node
  --port PORT                  Replica port for add-replica (otherwise auto-selected outside current cluster range)
  -h, --help                   Show this help text

Port tokens can be provided as:
  7000 7001 7002
  7000-7008
  {7000..7008}

For start only:
  A single seed port auto-expands to contiguous ports based on replicas.
  Default replicas (0) expands to 4 ports.
  Example: start 7000 --replicas 2 => {7000..7008}
  Additional redis-server/valkey-server args can be passed after `--`.
  Example: start 7000 -- --enable-debug-command local
TXT;
    }

    private static function isActionToken(string $value): bool
    {
        return in_array($value, ['start', 'stop', 'kill', 'rebalance', 'status', 'flush', 'fill', 'add-replica', 'restart-replica'], true);
    }

    /**
     * @return array{int,int}
     */
    private function deriveAdaptiveFillShape(int $sizeBytes, int $targetKeys): array
    {
        $targetBytesPerKey = max(1, (int) ceil($sizeBytes / $targetKeys));
        $members = max(
            1,
            min(
                self::DEFAULT_FILL_MAX_MEMBERS,
                (int) ceil($targetBytesPerKey / self::DEFAULT_FILL_TARGET_MEMBER_BYTES),
            ),
        );

        return [$members, max(8, $targetBytesPerKey)];
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

    /**
     * @return list<string>
     */
    private function parseTypes(string $csv): array
    {
        $tokens = array_map('trim', explode(',', strtolower($csv)));
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            throw new InvalidArgumentException('--types expects a non-empty CSV list.');
        }

        $allowed = ['string' => true, 'set' => true, 'list' => true, 'hash' => true, 'zset' => true];
        $types = [];
        foreach ($tokens as $token) {
            if (!isset($allowed[$token])) {
                throw new InvalidArgumentException(sprintf('Unsupported fill type: %s', $token));
            }

            $types[$token] = true;
        }

        /** @var list<string> $normalized */
        $normalized = array_keys($types);

        return $normalized;
    }

    private function parseSizeBytes(string $raw): int
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            throw new InvalidArgumentException('--size expects a value.');
        }

        if (preg_match('/^(\d+)([kmgt]?)(b)?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('--size expects formats like 100m, 1g, 512k, or 1048576.');
        }

        $base = (int) $matches[1];
        $unit = $matches[2];

        $multiplier = match ($unit) {
            '' => 1,
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
            default => throw new InvalidArgumentException(sprintf('Unsupported --size unit: %s', $unit)),
        };

        $bytes = $base * $multiplier;
        if ($bytes <= 0) {
            throw new InvalidArgumentException('--size must be > 0.');
        }

        return $bytes;
    }
}
