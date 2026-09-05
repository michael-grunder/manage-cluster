<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

final class CommandLineParser
{
    /**
     * @var list<string>
     */
    private const array ACTIONS = ['start', 'stop', 'kill', 'rebalance', 'status', 'list', 'flush', 'fill', 'add-replica', 'restart-replica', 'chaos', 'completions', 'version'];

    /**
     * @var array<string, string>
     */
    private const array ACTION_SUMMARIES = [
        'start' => 'Start one local Redis Cluster from the given ports',
        'stop' => 'Stop every managed node in the cluster(s)',
        'kill' => 'Stop one primary, one replica, or selected replica groups from a seed node',
        'rebalance' => 'Rebalance slots across the selected cluster nodes',
        'status' => 'Show shard and node status for one cluster, or summarize all managed clusters',
        'list' => 'List managed clusters that appear to still be running',
        'flush' => 'Flush every primary in the selected cluster(s)',
        'fill' => 'Fill a cluster until its primaries reach a target size',
        'add-replica' => 'Add a new replica to a selected primary',
        'restart-replica' => 'Restart one or more failed replicas from cluster metadata',
        'chaos' => 'Run serialized replica and slot churn against a cluster for client testing',
        'completions' => 'Generate a shell completion script',
        'version' => 'Show the version, build date, and commit this build came from',
    ];

    /**
     * @var array<string, list<array{0:string,1:string}>>
     */
    private const array COMMAND_OPTIONS = [
        'start' => [
            ['--primaries N', 'Primary count for cluster creation (default: 3)'],
            ['--replicas N', 'Replicas per primary; one seed port expands automatically'],
            ['--gen-script PATH', 'Write a startup shell script instead of launching now'],
            ['--binary PATH', 'Path to redis-server or valkey-server'],
            ['--cluster-announce-ip IP', 'Advertise a fixed IP for all started nodes'],
            ['--tls', 'Start TLS-only nodes and generate local certificates'],
            ['--tls-days N', 'Certificate lifetime in days (default: 3650)'],
            ['--tls-rsa-bits N', 'RSA key size for generated certificates (default: 2048)'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
            ['-- NAME VALUE ...', 'Append Redis config directives to redis.conf and to every started node'],
        ],
        'stop' => [
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'kill' => [
            ['--replica PORT', 'Replica port to stop without opening the picker'],
            ['--primary PORT', 'Require --replica or --all targets to belong to this primary'],
            ['--all', 'Stop every replica, or every replica under --primary PORT'],
            ['--wait', 'Wait until Redis cluster state reports stopped replica targets as down'],
            ['--method METHOD', 'shutdown, nosave, or sigterm/sigquit/sigsegv/sigkill/sigabrt/sigbus'],
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
            ['--size SIZE', 'Required target size: bytes, k, m, g, or t; decimals accepted'],
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
            ['--replica PORT', 'Failed replica port to restart without opening the picker'],
            ['--primary PORT', 'Require --replica or --all targets to belong to this primary'],
            ['--all', 'Restart every failed replica, or every failed replica under --primary PORT'],
            ['--wait', 'Wait until Redis cluster state reports restarted replica targets as healthy'],
            ['--config NAME=VALUE', 'Apply and persist a Redis CONFIG SET override after restart; repeatable'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'chaos' => [
            ['--categories LIST', 'Allowed event categories, or all (default: %chaos-default-categories%)'],
            ['--interval SECONDS', 'Minimum time between completed chaos steps (default: 8)'],
            ['--max-events N', 'Stop after N completed events (default: unlimited)'],
            ['--max-failures N', 'Abort after N consecutive failures (default: unlimited)'],
            ['--abort-on-failure', 'Stop at the first failed event instead of reporting it and continuing'],
            ['--dry-run', 'Select and print events without mutating cluster state'],
            ['--watch', 'Open a fullscreen live view of topology and chaos events'],
            ['--seed N', 'PRNG seed for reproducible event selection'],
            ['--wait-timeout SECONDS', 'Maximum wait for event convergence (default: 60)'],
            ['--cooldown SECONDS', 'Quiet period after convergence (default: 2)'],
            ['--allow-slot-migration', 'Add slot-migration to the allowed event categories'],
            ['--allow-primary-failover', 'Add primary-failover to the allowed event categories'],
            ['--allow-replica-reparent', 'Add replica-reparent to the allowed event categories'],
            ['--allow-primary-add', 'Add primary-add to the allowed event categories'],
            ['--allow-primary-remove', 'Add primary-remove to the allowed event categories'],
            ['--slot-strategy NAME', 'Slot migration strategy: balanced (default) or random'],
            ['--slot-batch N', 'Maximum slots moved per slot-migration event (default: 16)'],
            ['--unsafe', 'Permit lower-redundancy actions normally avoided'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ],
        'completions' => [],
        'version' => [],
    ];

    /**
     * Example invocations without the leading command name; see {@see renderExamples()}.
     *
     * @var array<string, list<string>>
     */
    private const array COMMAND_EXAMPLES = [
        'start' => [
            'start 7000',
            'start 7000 --primaries 4',
            'start 7000 --replicas 1',
            'start 7000-7005 --tls',
            'start 7000 -- --enable-debug-command local',
            'start 7000 -- replica-serve-stale-data no',
        ],
        'stop' => [
            'stop 7000',
            'stop 7000-7005',
            'stop {7000..7008}',
        ],
        'kill' => [
            'kill 7000',
            'kill 7000 --replica 7002',
            'kill 7000 --replica 7002 --method sigsegv',
            'kill 7000 --primary 7000 --replica 7002',
            'kill 7000 --all --wait',
            'kill 7000 --primary 7000 --all --wait',
        ],
        'rebalance' => [
            'rebalance 7000',
            'rebalance 7000-7005',
        ],
        'status' => [
            'status',
            'status 7000',
            'status 7000 --watch',
        ],
        'list' => [
            'list',
        ],
        'flush' => [
            'flush 7000',
            'flush 7000-7005',
        ],
        'fill' => [
            'fill --size 1g',
            'fill --size 2.5g --keys 20000',
            'fill 7000 --size 256m --types string,set --members 32 --member-size 2048',
            'fill 7000 --size 512m --pin-primary 7003',
        ],
        'add-replica' => [
            'add-replica 7000',
            'add-replica 7000 --port 7010',
        ],
        'restart-replica' => [
            'restart-replica 7000',
            'restart-replica 7000 --replica 7002',
            'restart-replica 7000 --primary 7000 --replica 7002',
            'restart-replica 7000 --all --wait',
            'restart-replica 7000 --primary 7000 --all --wait',
            'restart-replica 7000 --replica 7002 --config replica-serve-stale-data=no',
        ],
        'chaos' => [
            'chaos 7000',
            'chaos 7000 --categories replica-kill,replica-restart',
            'chaos 7000 --categories all',
            'chaos 7000 --max-events 50',
            'chaos 7000 --interval 8 --watch',
            'chaos 7000 --dry-run',
            'chaos 7000 --allow-slot-migration --slot-batch 32',
            'chaos 7000 --categories slot-migration --slot-strategy random',
            'chaos 7000 --allow-primary-failover',
            'chaos 7000 --categories primary-failover --watch',
            'chaos 7000 --allow-replica-reparent',
            'chaos 7000 --allow-primary-add --allow-primary-remove',
        ],
        'version' => [
            'version',
            '--version',
        ],
        'completions' => [
            'completions bash',
            'completions zsh',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array COMMAND_NOTES = [
        'start' => [
            'A single seed port expands to contiguous ports based on --primaries and --replicas.',
            'With the defaults, one seed port expands to 3 ports.',
            'Arguments after -- must be Redis config directive pairs and are persisted to each generated redis.conf.',
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
            'The CLI only restarts failed replicas that can be recovered from saved metadata.',
            '--all is scoped to failed replicas only; healthy replicas are left running.',
        ],
        'chaos' => [
            'chaos executes replica kill, restart, add, and reparent, primary add and remove, bounded slot migration, and coordinated primary failover.',
            '--categories accepts %chaos-categories%, or all for every category.',
            'Without --categories, chaos runs %chaos-default-categories%; every other category is opt-in.',
            'When --dry-run is used without --max-events, the command prints one planned event and exits.',
            'slot-migration is opt-in through --categories or --allow-slot-migration.',
            'primary-failover is opt-in through --categories or --allow-primary-failover and promotes a caught-up replica with CLUSTER FAILOVER.',
            'replica-reparent is opt-in through --categories or --allow-replica-reparent and moves a live replica to another primary with CLUSTER REPLICATE.',
            'primary-add joins an empty primary and migrates slots into it; primary-remove drains a primary, reattaches its replicas, forgets it, and stops it.',
            '--slot-strategy balanced keeps ownership even; random ignores the distribution and fragments it.',
            'replica-remove is parsed but remains disabled in conservative v1 selection.',
        ],
    ];

    private const int DEFAULT_OPTION_COLUMN_WIDTH = 24;
    private const int DEFAULT_FILL_MEMBERS = 8;
    private const int DEFAULT_FILL_MEMBER_SIZE = 256;
    private const int DEFAULT_FILL_TARGET_KEYS = 5000;
    private const int DEFAULT_FILL_TARGET_MEMBER_BYTES = 4096;
    private const int DEFAULT_FILL_MAX_MEMBERS = 256;

    public function __construct(
        private readonly InvocationName $invocation = new InvocationName(InvocationName::DEFAULT_COMMAND, InvocationName::DEFAULT_COMMAND),
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function parse(array $argv): CommandLineOptions
    {
        $action = null;
        $portTokens = [];
        $replicaPort = null;
        $replicaPortOption = null;
        $primaryPort = null;
        $generatedScriptPath = null;
        $restartConfigOverrides = [];
        $restartConfigOverrideProvided = false;
        $all = false;
        $wait = false;
        $killMethod = KillMethod::Shutdown;
        $killMethodProvided = false;
        $completionShell = null;

        $primaries = 3;
        $primariesProvided = false;
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
        $types = self::defaultFillTypes();
        $members = self::DEFAULT_FILL_MEMBERS;
        $memberSize = self::DEFAULT_FILL_MEMBER_SIZE;
        $fillKeys = self::DEFAULT_FILL_TARGET_KEYS;
        $pinPrimaryPort = null;
        $chaosCategories = ChaosOptions::DEFAULT_CATEGORIES;
        $chaosInterval = 8;
        $chaosMaxEvents = null;
        $chaosMaxFailures = ChaosOptions::UNLIMITED_FAILURES;
        $chaosAbortOnFailure = false;
        $chaosDryRun = false;
        $chaosSeed = null;
        $chaosWaitTimeout = 60;
        $chaosCooldown = 2;
        $chaosAllowSlotMigration = false;
        $chaosAllowPrimaryFailover = false;
        $chaosAllowReplicaReparent = false;
        $chaosAllowPrimaryAdd = false;
        $chaosAllowPrimaryRemove = false;
        $chaosSlotStrategy = SlotMigrationStrategy::Balanced;
        $chaosSlotStrategyProvided = false;
        $chaosSlotBatch = ChaosOptions::DEFAULT_SLOT_MIGRATION_BATCH;
        $chaosSlotBatchProvided = false;
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
                    throw new InvalidArgumentException($this->usage());

                case '--version':
                case '-v':
                    if ($action !== null) {
                        throw new InvalidArgumentException(self::singleActionMessage());
                    }

                    $action = 'version';
                    break;

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
                case '--completions':
                    if ($action !== null) {
                        throw new InvalidArgumentException(self::singleActionMessage());
                    }

                    $action = ltrim($arg, '-');
                    break;

                case '--replicas':
                    $replicas = $this->parseIntOption($argv, ++$i, '--replicas');
                    break;

                case '--primaries':
                    $primaries = $this->parseIntOption($argv, ++$i, '--primaries');
                    $primariesProvided = true;
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

                case '--all':
                    $all = true;
                    break;

                case '--wait':
                    $wait = true;
                    break;

                case '--method':
                    $killMethod = KillMethod::parse($this->parseStringOption($argv, ++$i, '--method'));
                    $killMethodProvided = true;
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

                case '--abort-on-failure':
                    $chaosAbortOnFailure = true;
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

                case '--allow-primary-failover':
                    $chaosAllowPrimaryFailover = true;
                    break;

                case '--allow-replica-reparent':
                    $chaosAllowReplicaReparent = true;
                    break;

                case '--allow-primary-add':
                    $chaosAllowPrimaryAdd = true;
                    break;

                case '--allow-primary-remove':
                    $chaosAllowPrimaryRemove = true;
                    break;

                case '--slot-strategy':
                    $chaosSlotStrategy = SlotMigrationStrategy::parse($this->parseStringOption($argv, ++$i, '--slot-strategy'));
                    $chaosSlotStrategyProvided = true;
                    break;

                case '--slot-batch':
                    $chaosSlotBatch = $this->parseIntOption($argv, ++$i, '--slot-batch');
                    $chaosSlotBatchProvided = true;
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
                    if ($replicaPortOption !== null) {
                        throw new InvalidArgumentException(sprintf('%s and --port cannot be used together.', $replicaPortOption));
                    }
                    $replicaPort = $this->parseIntOption($argv, ++$i, '--port');
                    $replicaPortOption = '--port';
                    break;

                case '--replica':
                    if ($replicaPortOption !== null) {
                        throw new InvalidArgumentException(sprintf('%s and --replica cannot be used together.', $replicaPortOption));
                    }
                    $replicaPort = $this->parseIntOption($argv, ++$i, '--replica');
                    $replicaPortOption = '--replica';
                    break;

                case '--primary':
                    $primaryPort = $this->parseIntOption($argv, ++$i, '--primary');
                    break;

                case '--config':
                    [$name, $value] = $this->parseConfigOverride($this->parseStringOption($argv, ++$i, '--config'));
                    $restartConfigOverrides[$name] = $value;
                    $restartConfigOverrideProvided = true;
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

                        throw new InvalidArgumentException(sprintf('Specify %s before ports (got: %s).', self::actionChoiceMessage(), $arg));
                    }

                    $portTokens[] = $arg;
                    break;
            }
        }

        if ($action === null) {
            throw new InvalidArgumentException(sprintf('Missing action: use %s.', self::actionChoiceMessage()));
        }

        if ($action === 'start' && $replicas < 0) {
            throw new InvalidArgumentException('--replicas must be >= 0.');
        }

        if ($action === 'start' && $primaries < 3) {
            throw new InvalidArgumentException('--primaries must be >= 3.');
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

        if ($all && !in_array($action, ['kill', 'restart-replica'], true)) {
            throw new InvalidArgumentException('--all can only be used with kill or restart-replica.');
        }

        if ($wait && !in_array($action, ['kill', 'restart-replica'], true)) {
            throw new InvalidArgumentException('--wait can only be used with kill or restart-replica.');
        }

        if ($action !== 'kill' && $killMethodProvided) {
            throw new InvalidArgumentException('--method can only be used with kill.');
        }

        if ($startServerArgs !== [] && $action !== 'start') {
            throw new InvalidArgumentException('Arguments after -- can only be used with start.');
        }

        $startConfigDirectives = $action === 'start' ? $this->parseStartConfigDirectives($startServerArgs) : [];
        $startServerArgs = $this->buildStartServerArgs($startConfigDirectives);

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
            $option = $replicaPortOption ?? '--port';
            throw new InvalidArgumentException(sprintf('%s must be a valid TCP port.', $option));
        }

        if ($primaryPort !== null && ($primaryPort < 1 || $primaryPort > 65535)) {
            throw new InvalidArgumentException('--primary must be a valid TCP port.');
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

        if ($action !== 'chaos' && $chaosMaxFailures !== ChaosOptions::UNLIMITED_FAILURES) {
            throw new InvalidArgumentException('--max-failures can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosAbortOnFailure) {
            throw new InvalidArgumentException('--abort-on-failure can only be used with chaos.');
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

        if ($action !== 'chaos' && $chaosAllowPrimaryFailover) {
            throw new InvalidArgumentException('--allow-primary-failover can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosAllowReplicaReparent) {
            throw new InvalidArgumentException('--allow-replica-reparent can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosAllowPrimaryAdd) {
            throw new InvalidArgumentException('--allow-primary-add can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosAllowPrimaryRemove) {
            throw new InvalidArgumentException('--allow-primary-remove can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosSlotStrategyProvided) {
            throw new InvalidArgumentException('--slot-strategy can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosSlotBatchProvided) {
            throw new InvalidArgumentException('--slot-batch can only be used with chaos.');
        }

        if ($action !== 'chaos' && $chaosUnsafe) {
            throw new InvalidArgumentException('--unsafe can only be used with chaos.');
        }

        if ($replicaPortOption === '--port' && $action !== 'add-replica') {
            throw new InvalidArgumentException('--port can only be used with add-replica.');
        }

        if ($replicaPortOption === '--replica' && !in_array($action, ['kill', 'restart-replica'], true)) {
            throw new InvalidArgumentException('--replica can only be used with kill or restart-replica.');
        }

        if ($primaryPort !== null && !in_array($action, ['kill', 'restart-replica'], true)) {
            throw new InvalidArgumentException('--primary can only be used with kill or restart-replica.');
        }

        if ($all && $replicaPortOption === '--replica') {
            throw new InvalidArgumentException('--all and --replica cannot be used together.');
        }

        if ($primaryPort !== null && $replicaPortOption !== '--replica' && !$all) {
            throw new InvalidArgumentException('--primary requires --replica or --all.');
        }

        if ($action !== 'restart-replica' && $restartConfigOverrideProvided) {
            throw new InvalidArgumentException('--config can only be used with restart-replica.');
        }

        if ($action !== 'start' && $generatedScriptPath !== null) {
            throw new InvalidArgumentException('--gen-script can only be used with start.');
        }

        if ($action !== 'start' && $primariesProvided) {
            throw new InvalidArgumentException('--primaries can only be used with start.');
        }

        $ports = [];
        if ($action === 'completions') {
            if (count($portTokens) !== 1) {
                throw new InvalidArgumentException('completions expects exactly one shell: bash or zsh.');
            }

            $completionShell = strtolower($portTokens[0]);
            if (!in_array($completionShell, ShellCompletionGenerator::supportedShells(), true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported completion shell: %s. Expected one of: %s.',
                    $portTokens[0],
                    implode(', ', ShellCompletionGenerator::supportedShells()),
                ));
            }

            $ports = [];
        } elseif ($portTokens !== []) {
            $ports = PortParser::parse($portTokens);
        }

        if ($action === 'start' && count($ports) === 1) {
            $ports = $this->expandSingleStartPort($ports[0], $primaries, $replicas);
        } elseif ($action === 'start' && $ports !== [] && !$primariesProvided) {
            $groupSize = $replicas + 1;
            if (count($ports) % $groupSize === 0) {
                $primaries = (int) (count($ports) / $groupSize);
            }
        }

        if (!in_array($action, ['fill', 'status', 'list', 'completions', 'version'], true) && $ports === []) {
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

        if ($action === 'completions' && count($ports) !== 0) {
            throw new InvalidArgumentException('completions does not accept seed ports.');
        }

        if ($action === 'version' && count($ports) !== 0) {
            throw new InvalidArgumentException('version does not accept seed ports.');
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

        if ($chaosMaxFailures < ChaosOptions::UNLIMITED_FAILURES) {
            throw new InvalidArgumentException('--max-failures must be >= 0.');
        }

        if ($chaosWaitTimeout <= 0) {
            throw new InvalidArgumentException('--wait-timeout must be > 0.');
        }

        if ($chaosCooldown < 0) {
            throw new InvalidArgumentException('--cooldown must be >= 0.');
        }

        if ($chaosSlotBatch < 1 || $chaosSlotBatch > SlotRange::TOTAL_SLOTS) {
            throw new InvalidArgumentException(sprintf('--slot-batch must be between 1 and %d.', SlotRange::TOTAL_SLOTS));
        }

        if ($chaosAllowSlotMigration && !in_array(ChaosOptions::CATEGORY_SLOT_MIGRATION, $chaosCategories, true)) {
            $chaosCategories = [...$chaosCategories, ChaosOptions::CATEGORY_SLOT_MIGRATION];
        }

        if ($chaosAllowPrimaryFailover && !in_array(ChaosOptions::CATEGORY_PRIMARY_FAILOVER, $chaosCategories, true)) {
            $chaosCategories = [...$chaosCategories, ChaosOptions::CATEGORY_PRIMARY_FAILOVER];
        }

        if ($chaosAllowReplicaReparent && !in_array(ChaosOptions::CATEGORY_REPLICA_REPARENT, $chaosCategories, true)) {
            $chaosCategories = [...$chaosCategories, ChaosOptions::CATEGORY_REPLICA_REPARENT];
        }

        if ($chaosAllowPrimaryAdd && !in_array(ChaosOptions::CATEGORY_PRIMARY_ADD, $chaosCategories, true)) {
            $chaosCategories = [...$chaosCategories, ChaosOptions::CATEGORY_PRIMARY_ADD];
        }

        if ($chaosAllowPrimaryRemove && !in_array(ChaosOptions::CATEGORY_PRIMARY_REMOVE, $chaosCategories, true)) {
            $chaosCategories = [...$chaosCategories, ChaosOptions::CATEGORY_PRIMARY_REMOVE];
        }

        $chaosOptions = null;
        if ($action === 'chaos') {
            $chaosOptions = new ChaosOptions(
                categories: $chaosCategories,
                intervalSeconds: $chaosInterval,
                maxEvents: $chaosMaxEvents,
                maxFailures: $chaosMaxFailures,
                abortOnFailure: $chaosAbortOnFailure,
                dryRun: $chaosDryRun,
                watch: $watch,
                seed: $chaosSeed,
                waitTimeoutSeconds: $chaosWaitTimeout,
                cooldownSeconds: $chaosCooldown,
                allowSlotMigration: $chaosAllowSlotMigration,
                allowPrimaryFailover: $chaosAllowPrimaryFailover,
                allowReplicaReparent: $chaosAllowReplicaReparent,
                allowPrimaryAdd: $chaosAllowPrimaryAdd,
                allowPrimaryRemove: $chaosAllowPrimaryRemove,
                unsafe: $chaosUnsafe,
                slotMigrationStrategy: $chaosSlotStrategy,
                slotMigrationBatch: $chaosSlotBatch,
            );
        }

        return new CommandLineOptions(
            action: $action,
            ports: $ports,
            replicaPort: $replicaPort,
            primaryPort: $primaryPort,
            restartConfigOverrides: $restartConfigOverrides,
            generatedScriptPath: $generatedScriptPath,
            primaries: $primaries,
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
            startConfigDirectives: $startConfigDirectives,
            startServerArgs: $startServerArgs,
            all: $all,
            wait: $wait,
            killMethod: $killMethod,
            completionShell: $completionShell,
        );
    }

    /**
     * @return list<string>
     */
    public static function actionNames(): array
    {
        return self::ACTIONS;
    }

    /**
     * @return array<string, string>
     */
    public static function actionSummaries(): array
    {
        return self::ACTION_SUMMARIES;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function commandOptions(string $action): array
    {
        return array_values(array_map(
            static fn (array $specification): array => [$specification[0], self::expandHelpText($specification[1])],
            self::COMMAND_OPTIONS[$action] ?? [],
        ));
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function globalOptionSpecs(): array
    {
        return self::globalOptions();
    }

    public function invocation(): InvocationName
    {
        return $this->invocation;
    }

    public function usage(bool $interactive = false): string
    {
        $lines = [
            self::formatHeading('Usage', $interactive) . ':',
            '  ' . $this->command('[OPTIONS] <COMMAND> [ARGS]'),
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
        $lines = [...$lines, ...$this->renderExamples([
            'start 7000',
            'status',
            'status 7000 --watch',
            'list',
            'fill --size 1g',
            'completions zsh',
            'help start',
        ])];
        $lines[] = '';
        $lines[] = sprintf('Run `%s` for command-specific help.', $this->command('help <command>'));

        return implode(PHP_EOL, $lines);
    }

    public function contextualUsage(?string $action, bool $interactive = false): string
    {
        if ($action === null || !isset(self::ACTION_SUMMARIES[$action])) {
            return $this->usage($interactive);
        }

        $lines = [
            self::formatHeading('Usage', $interactive) . ':',
            '  ' . $this->command(self::commandSynopsis($action)),
            '',
            self::formatHeading('About', $interactive) . ':',
            '  ' . self::ACTION_SUMMARIES[$action],
            '',
            self::formatHeading('Options', $interactive) . ':',
        ];

        $options = self::commandOptions($action);
        $width = self::optionColumnWidth($options);
        foreach ($options as [$option, $description]) {
            $lines[] = self::formatAlignedRow($option, $description, $interactive, $width);
        }

        $lines[] = self::formatAlignedRow('-h, --help', 'Print help for this command', $interactive, $width);
        $lines[] = '';
        $lines[] = self::formatHeading('Examples', $interactive) . ':';
        $lines = [...$lines, ...$this->renderExamples(self::COMMAND_EXAMPLES[$action])];

        $notes = self::COMMAND_NOTES[$action] ?? [];
        if ($notes !== []) {
            $lines[] = '';
            $lines[] = self::formatHeading('Notes', $interactive) . ':';
            foreach ($notes as $note) {
                $lines[] = '  ' . self::expandHelpText($note);
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
            '--primaries' => true,
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
            '--replica' => true,
            '--primary' => true,
            '--categories' => true,
            '--interval' => true,
            '--max-events' => true,
            '--max-failures' => true,
            '--seed' => true,
            '--wait-timeout' => true,
            '--cooldown' => true,
            '--config' => true,
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

    /**
     * Both spellings of every action, so action errors stay in sync with {@see ACTIONS}.
     */
    private static function actionChoiceMessage(): string
    {
        return sprintf(
            '%s (or %s)',
            implode('/', self::ACTIONS),
            implode('/', array_map(static fn (string $action): string => '--' . $action, self::ACTIONS)),
        );
    }

    private static function singleActionMessage(): string
    {
        $options = array_map(static fn (string $action): string => '--' . $action, self::ACTIONS);
        $last = array_pop($options);

        return sprintf('Only one action may be used: %s, or %s.', implode(', ', $options), $last);
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

        if (!preg_match('/^(?<amount>(?:\d+(?:\.\d+)?|\.\d+))(?<unit>[kmgt]?b?)?$/', $normalized, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid --size value: %s', $value));
        }

        $amount = (float) $matches['amount'];
        $unit = $matches['unit'] ?? '';

        $multiplier = match ($unit) {
            '', 'b' => 1,
            'k', 'kb' => 1024,
            'm', 'mb' => 1024 ** 2,
            'g', 'gb' => 1024 ** 3,
            't', 'tb' => 1024 ** 4,
        };

        $bytes = $amount * $multiplier;
        if (!is_finite($bytes) || $bytes > PHP_INT_MAX) {
            throw new InvalidArgumentException(sprintf('--size is too large: %s', $value));
        }

        return (int) ceil($bytes);
    }

    /**
     * @return non-empty-list<string>
     */
    private static function defaultFillTypes(): array
    {
        return ['string', 'set', 'list', 'hash', 'zset'];
    }

    /**
     * @return non-empty-list<string>
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
    private function expandSingleStartPort(int $startPort, int $primaries, int $replicas): array
    {
        $nodeCount = $primaries * ($replicas + 1);
        $endPort = $startPort + $nodeCount - 1;
        if ($endPort > 65535) {
            throw new InvalidArgumentException(sprintf(
                'Seed port %d with --primaries %d and --replicas %d exceeds max port when expanded (%d).',
                $startPort,
                $primaries,
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
     * @return array{0: string, 1: string}
     */
    private function parseConfigOverride(string $raw): array
    {
        $separator = strpos($raw, '=');
        if ($separator === false) {
            throw new InvalidArgumentException('--config expects NAME=VALUE.');
        }

        $name = trim(substr($raw, 0, $separator));
        $value = substr($raw, $separator + 1);
        if ($name === '' || $value === '') {
            throw new InvalidArgumentException('--config expects NAME=VALUE with a non-empty name and value.');
        }

        return [RedisConfigDirectiveFormatter::normalizeName($name), $value];
    }

    /**
     * @param list<string> $args
     * @return list<array{0: string, 1: string}>
     */
    private function parseStartConfigDirectives(array $args): array
    {
        if ($args === []) {
            return [];
        }

        if (count($args) % 2 !== 0) {
            throw new InvalidArgumentException('Arguments after -- must be Redis config directive pairs: NAME VALUE.');
        }

        $directives = [];
        for ($i = 0; $i < count($args); $i += 2) {
            $directives[] = [
                RedisConfigDirectiveFormatter::normalizeName($args[$i]),
                $args[$i + 1],
            ];
        }

        return $directives;
    }

    /**
     * @param list<array{0: string, 1: string}> $directives
     * @return list<string>
     */
    private function buildStartServerArgs(array $directives): array
    {
        $args = [];
        foreach ($directives as [$name, $value]) {
            $args[] = sprintf('--%s', $name);
            $args[] = $value;
        }

        return $args;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function globalOptions(): array
    {
        return [
            ['-h, --help', 'Print help'],
            ['-v, --version', 'Print version, build date, and commit'],
            ['--binary PATH', 'Path to redis-server or valkey-server'],
            ['--redis-cli PATH', 'Path to redis-cli (default: redis-cli)'],
            ['--state-dir PATH', 'Cluster metadata root (default: /tmp/manage-cluster)'],
        ];
    }

    /**
     * @param list<string> $examples
     * @return list<string>
     */
    private function renderExamples(array $examples): array
    {
        return array_map(fn (string $example): string => '  ' . $this->command($example), $examples);
    }

    private function command(string $arguments): string
    {
        return $arguments === ''
            ? $this->invocation->display
            : sprintf('%s %s', $this->invocation->display, $arguments);
    }

    /**
     * Argument synopsis without the leading command name; see {@see command()}.
     */
    private static function commandSynopsis(string $action): string
    {
        return match ($action) {
            'start' => 'start PORT [PORT ...] [--primaries N] [--replicas N] [--tls] [--gen-script PATH] [-- NAME VALUE ...]',
            'stop' => 'stop PORT [PORT ...]',
            'kill' => 'kill SEED_PORT [--replica PORT] [--primary PORT] [--all] [--wait] [--method METHOD]',
            'rebalance' => 'rebalance PORT [PORT ...]',
            'status' => 'status [PORT] [--watch]',
            'list' => 'list',
            'flush' => 'flush PORT [PORT ...]',
            'fill' => 'fill [PORT] --size SIZE [--types CSV] [--members N] [--member-size N] [--keys N] [--pin-primary PORT]',
            'add-replica' => 'add-replica SEED_PORT [--port PORT]',
            'restart-replica' => 'restart-replica SEED_PORT [--replica PORT] [--primary PORT] [--all] [--wait] [--config NAME=VALUE]',
            'chaos' => 'chaos SEED_PORT [--categories LIST] [--interval SECONDS] [--max-events N] [--dry-run] [--watch]',
            'completions' => 'completions bash|zsh',
            'version' => 'version',
            default => '[OPTIONS] <COMMAND> [ARGS]',
        };
    }

    /**
     * @return list<string>
     */
    private function parseChaosCategories(string $value): array
    {
        $tokens = array_values(array_filter(array_map('trim', explode(',', strtolower($value))), static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            throw new InvalidArgumentException('--categories must contain at least one event category.');
        }

        $categories = [];
        foreach ($tokens as $token) {
            if ($token === ChaosOptions::CATEGORY_ALIAS_ALL) {
                $categories = [...$categories, ...ChaosOptions::SUPPORTED_CATEGORIES];

                continue;
            }

            if (!in_array($token, ChaosOptions::SUPPORTED_CATEGORIES, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported chaos category: %s', $token));
            }

            $categories[] = $token;
        }

        return array_values(array_unique($categories));
    }

    /**
     * Expand help placeholders so option and note text stays in sync with the
     * chaos category constants.
     */
    private static function expandHelpText(string $text): string
    {
        return strtr($text, [
            '%chaos-categories%' => implode(', ', ChaosOptions::SUPPORTED_CATEGORIES),
            '%chaos-default-categories%' => implode(',', ChaosOptions::DEFAULT_CATEGORIES),
        ]);
    }

    private static function formatHeading(string $text, bool $interactive): string
    {
        return $interactive ? sprintf("\033[1m%s\033[0m", $text) : $text;
    }

    /**
     * Keep every description in one command's help aligned, even when an option
     * name is wider than the default column.
     *
     * @param list<array{0:string,1:string}> $options
     */
    private static function optionColumnWidth(array $options): int
    {
        $width = self::DEFAULT_OPTION_COLUMN_WIDTH;
        foreach ($options as [$option]) {
            $width = max($width, strlen($option) + 2);
        }

        return $width;
    }

    private static function formatAlignedRow(string $left, string $right, bool $interactive, int $width = self::DEFAULT_OPTION_COLUMN_WIDTH): string
    {
        $plainLeft = $left;
        $formattedLeft = $interactive ? sprintf("\033[36m%s\033[0m", $left) : $left;
        $padding = max(2, $width - strlen($plainLeft));

        return sprintf('  %s%s%s', $formattedLeft, str_repeat(' ', $padding), $right);
    }
}
