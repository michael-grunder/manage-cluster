<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosOptions
{
    public const string CATEGORY_REPLICA_KILL = 'replica-kill';
    public const string CATEGORY_REPLICA_RESTART = 'replica-restart';
    public const string CATEGORY_REPLICA_REMOVE = 'replica-remove';
    public const string CATEGORY_REPLICA_ADD = 'replica-add';
    public const string CATEGORY_REPLICA_REPARENT = 'replica-reparent';
    public const string CATEGORY_PRIMARY_ADD = 'primary-add';
    public const string CATEGORY_PRIMARY_REMOVE = 'primary-remove';
    public const string CATEGORY_SLOT_MIGRATION = 'slot-migration';
    public const string CATEGORY_PRIMARY_FAILOVER = 'primary-failover';

    /**
     * Alias accepted by `--categories` that expands to every supported category.
     */
    public const string CATEGORY_ALIAS_ALL = 'all';

    /**
     * @var list<string>
     */
    public const array SUPPORTED_CATEGORIES = [
        self::CATEGORY_REPLICA_KILL,
        self::CATEGORY_REPLICA_RESTART,
        self::CATEGORY_REPLICA_REMOVE,
        self::CATEGORY_REPLICA_ADD,
        self::CATEGORY_REPLICA_REPARENT,
        self::CATEGORY_PRIMARY_ADD,
        self::CATEGORY_PRIMARY_REMOVE,
        self::CATEGORY_SLOT_MIGRATION,
        self::CATEGORY_PRIMARY_FAILOVER,
    ];

    public const int DEFAULT_SLOT_MIGRATION_BATCH = 16;

    /**
     * `--max-failures 0` means "never give up". A chaos run is a soak test, so
     * a single event that cannot be planned or executed is a fact to report,
     * not a reason to stop churning the cluster.
     */
    public const int UNLIMITED_FAILURES = 0;

    /**
     * @var list<string>
     */
    public const array DEFAULT_CATEGORIES = [
        self::CATEGORY_REPLICA_KILL,
        self::CATEGORY_REPLICA_RESTART,
        self::CATEGORY_REPLICA_ADD,
    ];

    /**
     * @param list<string> $categories
     */
    public function __construct(
        public array $categories,
        public int $intervalSeconds,
        public ?int $maxEvents,
        public int $maxFailures,
        public bool $abortOnFailure,
        public bool $dryRun,
        public bool $watch,
        public ?int $seed,
        public int $waitTimeoutSeconds,
        public int $cooldownSeconds,
        public bool $allowSlotMigration,
        public bool $allowPrimaryFailover,
        public bool $allowReplicaReparent,
        public bool $allowPrimaryAdd,
        public bool $allowPrimaryRemove,
        public bool $unsafe,
        public SlotMigrationStrategy $slotMigrationStrategy = SlotMigrationStrategy::Balanced,
        public int $slotMigrationBatch = self::DEFAULT_SLOT_MIGRATION_BATCH,
    ) {
    }

    /**
     * Decide whether a failed event ends the run. `--abort-on-failure` stops at
     * the first one; otherwise only a `--max-failures` ceiling does, and the
     * default ceiling is unlimited.
     */
    public function shouldAbortAfterFailures(int $consecutiveFailures): bool
    {
        if ($consecutiveFailures < 1) {
            return false;
        }

        if ($this->abortOnFailure) {
            return true;
        }

        return $this->maxFailures !== self::UNLIMITED_FAILURES
            && $consecutiveFailures >= $this->maxFailures;
    }
}
