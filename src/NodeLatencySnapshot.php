<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class NodeLatencySnapshot
{
    public function __construct(
        public NodeLatencyState $state,
        public ?float $milliseconds = null,
    ) {
    }

    public function displayValue(): string
    {
        return match ($this->state) {
            NodeLatencyState::Pending => 'pending',
            NodeLatencyState::Timeout => 'timeout',
            NodeLatencyState::Error => 'down',
            NodeLatencyState::Ok => $this->formatMilliseconds(),
        };
    }

    private function formatMilliseconds(): string
    {
        if ($this->milliseconds === null) {
            return 'pending';
        }

        if ($this->milliseconds >= 100) {
            return sprintf('%.0f ms', $this->milliseconds);
        }

        if ($this->milliseconds >= 10) {
            return sprintf('%.1f ms', $this->milliseconds);
        }

        return sprintf('%.2f ms', $this->milliseconds);
    }
}
