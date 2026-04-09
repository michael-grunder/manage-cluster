<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosOptions
{
    public const string CATEGORY_REPLICA_KILL = 'replica-kill';
    public const string CATEGORY_REPLICA_RESTART = 'replica-restart';
    public const string CATEGORY_REPLICA_REMOVE = 'replica-remove';
    public const string CATEGORY_REPLICA_ADD = 'replica-add';
    public const string CATEGORY_SLOT_MIGRATION = 'slot-migration';

    /**
     * @var list<string>
     */
    public const array SUPPORTED_CATEGORIES = [
        self::CATEGORY_REPLICA_KILL,
        self::CATEGORY_REPLICA_RESTART,
        self::CATEGORY_REPLICA_REMOVE,
        self::CATEGORY_REPLICA_ADD,
        self::CATEGORY_SLOT_MIGRATION,
    ];

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
        public bool $dryRun,
        public bool $watch,
        public ?int $seed,
        public int $waitTimeoutSeconds,
        public int $cooldownSeconds,
        public bool $allowSlotMigration,
        public bool $unsafe,
    ) {
    }
}
