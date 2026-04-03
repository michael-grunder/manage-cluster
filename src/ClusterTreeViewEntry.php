<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ClusterTreeViewEntry
{
    public function __construct(
        public ClusterNodeStatus $node,
        public ?string $slotRange,
        public int $depth,
        public bool $selectable,
    ) {
    }

    public function nodeLabel(): string
    {
        $prefix = str_repeat('  ', max(0, $this->depth));

        return sprintf('%s%s', $prefix, $this->node->address());
    }

    public function roleLabel(): string
    {
        return $this->depth === 0 ? 'primary' : 'replica';
    }
}
