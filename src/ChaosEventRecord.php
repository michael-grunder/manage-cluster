<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosEventRecord
{
    /**
     * @param list<string> $notes
     * @param list<string> $reasons
     */
    public function __construct(
        public int $id,
        public string $category,
        public string $status,
        public ?int $targetPort,
        public ?int $targetPrimaryPort,
        public float $startedAt,
        public ?float $completedAt,
        public string $summary,
        public string $postcondition,
        public array $notes = [],
        public array $reasons = [],
        public ?SlotMigrationPlan $slotMigrationPlan = null,
        public ?PrimaryFailoverPlan $primaryFailoverPlan = null,
        public ?ReplicaReparentPlan $replicaReparentPlan = null,
        public ?PrimaryAddPlan $primaryAddPlan = null,
        public ?PrimaryRemovePlan $primaryRemovePlan = null,
    ) {
    }

    /**
     * Build the initial record for a freshly selected candidate. Centralised so
     * every category's plan travels with the event instead of each call site
     * remembering to copy one more field.
     */
    public static function fromCandidate(int $id, ChaosCandidateEvent $candidate, float $startedAt): self
    {
        return new self(
            id: $id,
            category: $candidate->category,
            status: 'planned',
            targetPort: $candidate->targetPort,
            targetPrimaryPort: $candidate->targetPrimaryPort,
            startedAt: $startedAt,
            completedAt: null,
            summary: $candidate->summary,
            postcondition: $candidate->postcondition,
            reasons: $candidate->reasons,
            slotMigrationPlan: $candidate->slotMigrationPlan,
            primaryFailoverPlan: $candidate->primaryFailoverPlan,
            replicaReparentPlan: $candidate->replicaReparentPlan,
            primaryAddPlan: $candidate->primaryAddPlan,
            primaryRemovePlan: $candidate->primaryRemovePlan,
        );
    }

    /**
     * @param list<string>|null $notes
     */
    public function withStatus(string $status, ?float $completedAt = null, ?array $notes = null): self
    {
        return new self(
            id: $this->id,
            category: $this->category,
            status: $status,
            targetPort: $this->targetPort,
            targetPrimaryPort: $this->targetPrimaryPort,
            startedAt: $this->startedAt,
            completedAt: $completedAt,
            summary: $this->summary,
            postcondition: $this->postcondition,
            notes: $notes ?? $this->notes,
            reasons: $this->reasons,
            slotMigrationPlan: $this->slotMigrationPlan,
            primaryFailoverPlan: $this->primaryFailoverPlan,
            replicaReparentPlan: $this->replicaReparentPlan,
            primaryAddPlan: $this->primaryAddPlan,
            primaryRemovePlan: $this->primaryRemovePlan,
        );
    }
}
