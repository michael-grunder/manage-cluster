<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * The subset of chaos settings a `--config-file` supplied. Every field is
 * nullable and null means "the file said nothing about this", so the parser can
 * layer command line options on top without having to know which defaults the
 * file happened to repeat.
 */
final readonly class ChaosConfig
{
    public function __construct(
        public ?ChaosCategorySelection $categories = null,
        public ?int $intervalSeconds = null,
        public ?int $maxEvents = null,
        public ?int $maxFailures = null,
        public ?bool $abortOnFailure = null,
        public ?bool $dryRun = null,
        public ?bool $watch = null,
        public ?int $seed = null,
        public ?int $waitTimeoutSeconds = null,
        public ?int $cooldownSeconds = null,
        public ?bool $unsafe = null,
        public ?SlotMigrationStrategy $slotMigrationStrategy = null,
        public ?int $slotMigrationBatch = null,
    ) {
    }
}
