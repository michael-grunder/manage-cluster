<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosCandidateEvent
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public string $category,
        public ?int $targetPort,
        public ?int $targetPrimaryPort,
        public int $score,
        public string $summary,
        public string $postcondition,
        public array $reasons,
    ) {
    }
}
