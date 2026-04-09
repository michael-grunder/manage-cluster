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
    ) {
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
        );
    }
}
