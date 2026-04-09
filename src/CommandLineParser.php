<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final class CommandLineParser
{
    /**
     * @var list<string>
     */
    private const array ACTIONS = ['start', 'stop', 'kill', 'rebalance', 'status', 'list', 'flush', 'fill', 'add-replica', 'restart-replica', 'chaos'];

    /**
     * @var array<string, string>
     */
    private const array ACTION_SUMMARIES = [
        'start' => 'Start one local Redis Cluster from the given ports',
        'stop' => 'Stop every managed node in the cluster(s)',
        'kill' => 'Interactively stop one primary or replica from a seed node',
        'rebalance' => 'Rebalance slots across the selected cluster nodes',
        'status' => 'Show shard and node status for one cluster, or summarize all managed clusters',
        'list' => 'List managed clusters that appear to still be running',
        'flush' => 'Flush every primary in the selected cluster(s)',
        'fill' => 'Fill a cluster until its primaries reach a target size',
        'add-replica' => 'Add a new replica to a selected primary',
        'restart-replica' => 'Restart one failed replica from cluster metadata',
        'chaos' => 'Run serialized replica-focused cluster churn for client testing',
    ];

    /**
     * @var array<string, list<array{0:string,1:string}>>
     */
    private const array COMMAND_OPTIONS = [
        'start' => [
            ['--replicas N', 'Replicas per primary; one seed port expands automatically'],
            ['--gen-script PATH', 'Write a startup shell script instead of launching now'],
            ['--binary PATH', 'Path to redis-server or valkey-server'],
            ['--cluster-announce-ip IP', 'Advertise a fixed IP for all started nodes'],
            ['--tls', 'Start TLS-only nodes and generate local certificates'],
            ['--tls-days N', 'Certificate lifetime in days (default: 3650)'],
            ['--tls-rsa-bits N', 'RSA key size for generated certificates (default: 2048)'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
            ['-- REDIS_SERVER_ARG ...', 'Pass raw server arguments to every started node'],
        ],
        'stop' => [
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'kill' => [
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'rebalance' => [
            ['--redis-cli PATH', 'Path to redis-cli (default: redis-cli)'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'status' => [
            ['--redis-cli PATH', 'Path to redis-cli (default: redis-cli)'],
            ['--watch', 'Refresh the view every second'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'list' => [
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'flush' => [
            ['--redis-cli PATH', 'Path to redis-cli (default: redis-cli)'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'fill' => [
            ['--size SIZE', 'Required target size: bytes, k, m, g, or t'],
            ['--types CSV', 'Limit generated keys to string,set,list,hash,zset'],
            ['--members N', 'Members per composite key (default: 8 when set explicitly)'],
            ['--member-size N', 'Bytes per member or string payload (default: 256 when set explicitly)'],
            ['--keys N', 'Adaptive key-count target when both size knobs are omitted'],
            ['--pin-primary PORT', 'Restrict generated keys to one primary node'],
            ['--redis-cli PATH', 'Path to redis-cli (default: redis-cli)'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'add-replica' => [
            ['--port PORT', 'Replica port; otherwise the next free port is chosen'],
            ['--binary PATH', 'Path to redis-server or valkey-server'],
            ['--redis-cli PATH', 'Path to redis-cli (default: redis-cli)'],
            ['--cluster-announce-ip IP', 'Advertise a fixed IP for the new replica'],
            ['--tls', 'Start the replica in TLS mode'],
            ['--tls-days N', 'Certificate lifetime in days (default: 3650)'],
            ['--tls-rsa-bits N', 'RSA key size for generated certificates (default: 2048)'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'restart-replica' => [
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'chaos' => [
            ['--categories LIST', 'Allowed events: replica-kill,replica-restart,replica-remove,replica-add,slot-migration'],
            ['--interval SECONDS', 'Minimum time between completed chaos steps (default: 8)'],
            ['--max-events N', 'Stop after N completed events (default: unlimited)'],
            ['--max-failures N', 'Abort after N consecutive failures (default: 5)'],
            ['--dry-run', 'Select and print events without mutating cluster state'],
            ['--watch', 'Print compact state and wait-loop progress'],
            ['--seed N', 'PRNG seed for reproducible event selection'],
            ['--wait-timeout SECONDS', 'Maximum wait for event convergence (default: 60)'],
            ['--cooldown SECONDS', 'Quiet period after convergence (default: 2)'],
            ['--allow-slot-migration', 'Allow slot-migration selection when implemented'],
            ['--unsafe', 'Permit lower-redundancy actions normally avoided'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array COMMAND_EXAMPLES = [
        'start' => [
            'bin/manage-cluster start 7000',
            'bin/manage-cluster start 7000 --replicas 1',
            'bin/manage-cluster start 7000-7005 --tls',
            'bin/manage-cluster start 7000 -- --enable-debug-command local',
        ],
        'stop' => [
            'bin/manage-cluster stop 7000',
            'bin/manage-cluster stop 7000-7005',
            'bin/manage-cluster stop {7000..7008}',
        ],
        'kill' => [
            'bin/manage-cluster kill 7000',
        ],
        'rebalance' => [
            'bin/manage-cluster rebalance 7000',
            'bin/manage-cluster rebalance 7000-7005',
        ],
        'status' => [
            'bin/manage-cluster status',
            'bin/manage-cluster status 7000',
            'bin/manage-cluster status 7000 --watch',
        ],
        'list' => [
            'bin/manage-cluster list',
        ],
        'flush' => [
            'bin/manage-cluster flush 7000',
            'bin/manage-cluster flush 7000-7005',
        ],
        'fill' => [
            'bin/manage-cluster fill --size 1g',
            'bin/manage-cluster fill --size 5g --keys 20000',
            'bin/manage-cluster fill 7000 --size 256m --types string,set --members 32 --member-size 2048',
            'bin/manage-cluster fill 7000 --size 512m --pin-primary 7003',
        ],
        'add-replica' => [
            'bin/manage-cluster add-replica 7000',
            'bin/manage-cluster add-replica 7000 --port 7010',
        ],
        'restart-replica' => [
            'bin/manage-cluster restart-replica 7000',
        ],
        'chaos' => [
            'bin/manage-cluster chaos 7000',
            'bin/manage-cluster chaos 7000 --categories replica-kill,replica-restart',
            'bin/manage-cluster chaos 7000 --max-events 50',
            'bin/manage-cluster chaos 7000 --interval 8 --watch',
            'bin/manage-cluster chaos 7000 --dry-run',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array COMMAND_NOTES = [
        'start' => [
            'A single seed port expands to contiguous ports based on --replicas.',
            'With the default replica count (0), one seed port expands to 4 ports.',
        ],
        'fill' => [
            'PORT is optional when exactly one managed cluster exists in the state store.',
            'When both --members and --member-size are omitted, they are derived from --size.',
        ],
        'status' => [
            'Without PORT, status summarizes every managed cluster found in the state store.',
        ],
        'add-replica' => [
            'The CLI opens an interactive primary picker before attaching the new replica.',
        ],
        'restart-replica' => [
            'The CLI only offers failed replicas that can be recovered from saved metadata.',
        ],
        'chaos' => [
            'v1 focuses on serialized replica churn: kill, restart, and add.',
            'When --dry-run is used without --max-events, the command prints one planned event and exits.',
            'slot-migration and replica-remove are parsed but remain disabled in conservative v1 selection.',
        ],
    ];

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
        $chaosCategories = ChaosOptions::DEFAULT_CATEGORIES;
        $chaosInterval = 8;
        $chaosMaxEvents = null;
        $chaosMaxFailures = 5;
        $chaosDryRun = false;
        $chaosSeed = null;
        $chaosWaitTimeout = 60;
        $chaosCooldown = 2;
        $chaosAllowSlotMigration = false;
        $chaosUnsafe = false;
        $typesProvided = false;
        $membersProvided = false;
        $memberSizeProvided = false;
        $fillKeysProvided = false;
        $chaosCategoriesProvided = false;

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
                case '--list':
                case '--flush':
                case '--fill':
                case '--add-replica':
                case '--restart-replica':
                case '--chaos':
                    if ($action !== null) {
                        throw new InvalidArgumentException('Only one action may be used: --start, --stop, --kill, --rebalance, --status, --list, --flush, --fill, --add-replica, --restart-replica, or --chaos.');
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

                case '--categories':
                    $chaosCategories = $this->parseChaosCategories($this->parseStringOption($argv, ++$i, '--categories'));
                    $chaosCategoriesProvided = true;
                    break;

                case '--interval':
                    $chaosInterval = $this->parseIntOption($argv, ++$i, '--interval');
                    break;

                case '--max-events':
                    $chaosMaxEvents = $this->parseIntOption($argv, ++$i, '--max-events');
                    break;

                case '--max-failures':
                    $chaosMaxFailures = $this->parseIntOption($argv, ++$i, '--max-failures');
                    break;

                case '--dry-run':
                    $chaosDryRun = true;
                    break;

                case '--seed':
                    $chaosSeed = $this->parseIntOption($argv, ++$i, '--seed');
                    break;

                case '--wait-timeout':
                    $chaosWaitTimeout = $this->parseIntOption($argv, ++$i, '--wait-timeout');
                    break;

                case '--cooldown':
                    $chaosCooldown = $this->parseIntOption($argv, ++$i, '--cooldown');
                    break;

                case '--allow-slot-migration':
                    $chaosAllowSlotMigration = true;
                    break;

                case '--unsafe':
                    $chaosUnsafe = true;
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

                        throw new InvalidArgumentException(sprintf('Specify start/stop/kill/rebalance/status/list/flush/fill/add-replica/restart-replica/chaos (or --start/--stop/--kill/--rebalance/--status/--list/--flush/--fill/--add-replica/--restart-replica/--chaos) before ports (got: %s).', $arg));
                    }

                    $portTokens[] = $arg;
                    break;
            }
        }

        if ($action === null) {
            throw new InvalidArgumentException('Missing action: use start/stop/kill/rebalance/status/list/flush/fill/add-replica/restart-replica/chaos (or --start/--stop/--kill/--rebalance/--status/--list/--flush/--fill/--add-replica/--restart-replica/--chaos).');
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

        if ($watch && !in_array($action, ['status', 'chaos'], true)) {
            throw new InvalidArgumentException('--watch can only be used with status or chaos.');
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

        if ($action !== 'chaos' && $chaosCategoriesProvided) {
            throw new InvalidArgumentException('--categories can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosInterval !== 8) {
            throw new InvalidArgumentException('--interval can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosMaxEvents !== null) {
            throw new InvalidArgumentException('--max-events can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosMaxFailures !== 5) {
            throw new InvalidArgumentException('--max-failures can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosDryRun) {
            throw new InvalidArgumentException('--dry-run can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosSeed !== null) {
            throw new InvalidArgumentException('--seed can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosWaitTimeout !== 60) {
            throw new InvalidArgumentException('--wait-timeout can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosCooldown !== 2) {
            throw new InvalidArgumentException('--cooldown can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosAllowSlotMigration) {
            throw new InvalidArgumentException('--allow-slot-migration can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosUnsafe) {
            throw new InvalidArgumentException('--unsafe can only be used with chaos.');
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

        if (!in_array($action, ['fill', 'status', 'list'], true) && $ports === []) {
            throw new InvalidArgumentException('No ports provided');
        }

        if ($action === 'status' && count($ports) > 1) {
            throw new InvalidArgumentException('status expects zero or one seed port.');
        }

        if ($action === 'list' && count($ports) !== 0) {
            throw new InvalidArgumentException('list does not accept seed ports.');
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

        if ($action === 'chaos' && count($ports) !== 1) {
            throw new InvalidArgumentException('chaos expects exactly one seed port.');
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

        if ($chaosInterval <= 0) {
            throw new InvalidArgumentException('--interval must be > 0.');
        }

        if ($chaosMaxEvents !== null && $chaosMaxEvents <= 0) {
            throw new InvalidArgumentException('--max-events must be > 0.');
        }

        if ($chaosMaxFailures <= 0) {
            throw new InvalidArgumentException('--max-failures must be > 0.');
        }

        if ($chaosWaitTimeout <= 0) {
            throw new InvalidArgumentException('--wait-timeout must be > 0.');
        }

        if ($chaosCooldown < 0) {
            throw new InvalidArgumentException('--cooldown must be >= 0.');
        }

        $chaosOptions = null;
        if ($action === 'chaos') {
            $chaosOptions = new ChaosOptions(
                categories: $chaosCategories,
                intervalSeconds: $chaosInterval,
                maxEvents: $chaosMaxEvents,
                maxFailures: $chaosMaxFailures,
                dryRun: $chaosDryRun,
                watch: $watch,
                seed: $chaosSeed,
                waitTimeoutSeconds: $chaosWaitTimeout,
                cooldownSeconds: $chaosCooldown,
                allowSlotMigration: $chaosAllowSlotMigration,
                unsafe: $chaosUnsafe,
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
            chaos: $chaosOptions,
            startServerArgs: $startServerArgs,
        );
    }

    public static function usage(bool $interactive = false): string
    {
        $lines = [
            self::formatHeading('Usage', $interactive) . ':',
            '  bin/manage-cluster [OPTIONS] <COMMAND> [ARGS]',
            '',
            self::formatHeading('Options', $interactive) . ':',
        ];

        foreach (self::globalOptions() as [$option, $description]) {
            $lines[] = self::formatAlignedRow($option, $description, $interactive);
        }

        $lines[] = '';
        $lines[] = self::formatHeading('Commands', $interactive) . ':';
        foreach (self::ACTIONS as $action) {
            $lines[] = self::formatAlignedRow($action, self::ACTION_SUMMARIES[$action], $interactive, 18);
        }

        $lines[] = self::formatAlignedRow('help', 'Print this message or the help of a given command', $interactive, 18);
        $lines[] = '';
        $lines[] = self::formatHeading('Examples', $interactive) . ':';
        $lines[] = '  bin/manage-cluster start 7000';
        $lines[] = '  bin/manage-cluster status';
        $lines[] = '  bin/manage-cluster status 7000 --watch';
        $lines[] = '  bin/manage-cluster list';
        $lines[] = '  bin/manage-cluster fill --size 1g';
        $lines[] = '  bin/manage-cluster help start';
        $lines[] = '';
        $lines[] = 'Run `bin/manage-cluster help <command>` for command-specific help.';

        return implode(PHP_EOL, $lines);
    }

    public static function contextualUsage(?string $action, bool $interactive = false): string
    {
        if ($action === null || !isset(self::ACTION_SUMMARIES[$action])) {
            return self::usage($interactive);
        }

        $lines = [
            self::formatHeading('Usage', $interactive) . ':',
            '  ' . self::commandSynopsis($action),
            '',
            self::formatHeading('About', $interactive) . ':',
            '  ' . self::ACTION_SUMMARIES[$action],
            '',
            self::formatHeading('Options', $interactive) . ':',
        ];

        foreach (self::COMMAND_OPTIONS[$action] as [$option, $description]) {
            $lines[] = self::formatAlignedRow($option, $description, $interactive);
        }

        $lines[] = self::formatAlignedRow('-h, --help', 'Print help for this command', $interactive);
        $lines[] = '';
        $lines[] = self::formatHeading('Examples', $interactive) . ':';
        foreach (self::COMMAND_EXAMPLES[$action] as $example) {
            $lines[] = '  ' . $example;
        }

        $notes = self::COMMAND_NOTES[$action] ?? [];
        if ($notes !== []) {
            $lines[] = '';
            $lines[] = self::formatHeading('Notes', $interactive) . ':';
            foreach ($notes as $note) {
                $lines[] = '  ' . $note;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param list<string> $argv
     */
    public static function inferRequestedAction(array $argv): ?string
    {
        $optionsWithValues = [
            '--replicas' => true,
            '--gen-script' => true,
            '--binary' => true,
            '--redis-cli' => true,
            '--cluster-announce-ip' => true,
            '--tls-days' => true,
            '--tls-rsa-bits' => true,
            '--state-dir' => true,
            '--size' => true,
            '--types' => true,
            '--members' => true,
            '--member-size' => true,
            '--keys' => true,
            '--pin-primary' => true,
            '--port' => true,
            '--categories' => true,
            '--interval' => true,
            '--max-events' => true,
            '--max-failures' => true,
            '--seed' => true,
            '--wait-timeout' => true,
            '--cooldown' => true,
        ];

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '--') {
                break;
            }

            if ($arg === 'help') {
                $candidate = $argv[$i + 1] ?? null;
                return is_string($candidate) && self::isActionToken($candidate) ? $candidate : null;
            }

            if (isset($optionsWithValues[$arg])) {
                $i++;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                $candidate = ltrim($arg, '-');
                if (self::isActionToken($candidate)) {
                    return $candidate;
                }

                continue;
            }

            if (self::isActionToken($arg)) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * @param list<string> $argv
     */
    public static function inferHelpAction(array $argv): ?string
    {
        if (count($argv) < 2) {
            return null;
        }

        if ($argv[1] === 'help') {
            $candidate = $argv[2] ?? null;
            return is_string($candidate) && self::isActionToken($candidate) ? $candidate : null;
        }

        return self::inferRequestedAction($argv);
    }

    private static function isActionToken(string $value): bool
    {
        return in_array($value, self::ACTIONS, true);
    }

    /**
     * @return array{int,int}
     */
    private function deriveAdaptiveFillShape(int $sizeBytes, int $targetKeys): array
    {
        $targetKeys = max(1, $targetKeys);
        $bytesPerKey = max(1, (int) ceil($sizeBytes / $targetKeys));
        $members = (int) max(
            1,
            min(self::DEFAULT_FILL_MAX_MEMBERS, (int) ceil($bytesPerKey / self::DEFAULT_FILL_TARGET_MEMBER_BYTES)),
        );
        $memberSize = (int) max(
            1,
            $bytesPerKey,
        );

        return [$members, $memberSize];
    }

    private function parseSizeBytes(string $value): int
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            throw new InvalidArgumentException('--size must not be empty.');
        }

        if (!preg_match('/^(?<amount>\d+)(?<unit>[kmgt]?b?)?$/', $normalized, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid --size value: %s', $value));
        }

        $amount = (int) $matches['amount'];
        $unit = $matches['unit'] ?? '';

        $multiplier = match ($unit) {
            '', 'b' => 1,
            'k', 'kb' => 1024,
            'm', 'mb' => 1024 ** 2,
            'g', 'gb' => 1024 ** 3,
            't', 'tb' => 1024 ** 4,
            default => throw new InvalidArgumentException(sprintf('Invalid --size unit: %s', $value)),
        };

        return $amount * $multiplier;
    }

    /**
     * @return list<string>
     */
    private function parseTypes(string $value): array
    {
        $types = array_values(array_filter(array_map('trim', explode(',', strtolower($value))), static fn (string $type): bool => $type !== ''));
        if ($types === []) {
            throw new InvalidArgumentException('--types must contain at least one key type.');
        }

        $allowed = ['string', 'set', 'list', 'hash', 'zset'];
        foreach ($types as $type) {
            if (!in_array($type, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported fill type: %s', $type));
            }
        }

        return $types;
    }

    /**
     * @return list<int>
     */
    private function expandSingleStartPort(int $startPort, int $replicas): array
    {
        $nodeCount = $replicas === 0 ? 4 : 3 * ($replicas + 1);
        $endPort = $startPort + $nodeCount - 1;
        if ($endPort > 65535) {
            throw new InvalidArgumentException(sprintf(
                'Seed port %d with --replicas %d exceeds max port when expanded (%d).',
                $startPort,
                $replicas,
                $endPort,
            ));
        }

        $ports = [];
        for ($port = $startPort; $port < $startPort + $nodeCount; $port++) {
            $ports[] = $port;
        }

        return $ports;
    }

    /**
     * @param list<string> $argv
     */
    private function parseIntOption(array $argv, int $index, string $option): int
    {
        $value = $this->parseStringOption($argv, $index, $option);
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException(sprintf('%s expects an integer, got: %s', $option, $value));
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
     * @return list<array{0:string,1:string}>
     */
    private static function globalOptions(): array
    {
        return [
            ['-h, --help', 'Print help'],
            ['--binary PATH', 'Path to redis-server or valkey-server'],
            ['--redis-cli PATH', 'Path to redis-cli (default: redis-cli)'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ];
    }

    private static function commandSynopsis(string $action): string
    {
        return match ($action) {
            'start' => 'bin/manage-cluster start PORT [PORT ...] [--replicas N] [--tls] [--gen-script PATH] [-- REDIS_SERVER_ARG ...]',
            'stop' => 'bin/manage-cluster stop PORT [PORT ...]',
            'kill' => 'bin/manage-cluster kill PORT',
            'rebalance' => 'bin/manage-cluster rebalance PORT [PORT ...]',
            'status' => 'bin/manage-cluster status [PORT] [--watch]',
            'list' => 'bin/manage-cluster list',
            'flush' => 'bin/manage-cluster flush PORT [PORT ...]',
            'fill' => 'bin/manage-cluster fill [PORT] --size SIZE [--types CSV] [--members N] [--member-size N] [--keys N] [--pin-primary PORT]',
            'add-replica' => 'bin/manage-cluster add-replica SEED_PORT [--port PORT]',
            'restart-replica' => 'bin/manage-cluster restart-replica SEED_PORT',
            'chaos' => 'bin/manage-cluster chaos SEED_PORT [--categories LIST] [--interval SECONDS] [--max-events N] [--dry-run] [--watch]',
            default => 'bin/manage-cluster [OPTIONS] <COMMAND> [ARGS]',
        };
    }

    /**
     * @return list<string>
     */
    private function parseChaosCategories(string $value): array
    {
        $categories = array_values(array_filter(array_map('trim', explode(',', strtolower($value))), static fn (string $category): bool => $category !== ''));
        if ($categories === []) {
            throw new InvalidArgumentException('--categories must contain at least one event category.');
        }

        foreach ($categories as $category) {
            if (!in_array($category, ChaosOptions::SUPPORTED_CATEGORIES, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported chaos category: %s', $category));
            }
        }

        return array_values(array_unique($categories));
    }

    private static function formatHeading(string $text, bool $interactive): string
    {
        return $interactive ? sprintf("\033[1m%s\033[0m", $text) : $text;
    }

    private static function formatAlignedRow(string $left, string $right, bool $interactive, int $width = 24): string
    {
        $plainLeft = $left;
        $formattedLeft = $interactive ? sprintf("\033[36m%s\033[0m", $left) : $left;
        $padding = max(2, $width - strlen($plainLeft));

        return sprintf('  %s%s%s', $formattedLeft, str_repeat(' ', $padding), $right);
    }
}
