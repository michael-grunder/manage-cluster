<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class FillOptions
{
    /**
     * @param list<string> $types
     */
    public function __construct(
        public int $sizeBytes,
        public array $types,
        public int $members,
        public int $memberSize,
        public ?int $pinPrimaryPort,
    ) {
    }
}
