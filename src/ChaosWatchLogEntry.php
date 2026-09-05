<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ChaosWatchLogEntry
{
    public function __construct(
        public float $at,
        public ChaosWatchLogLevel $level,
        public string $message,
        public ?int $eventId = null,
    ) {
    }

    public function timestamp(): string
    {
        return date('H:i:s', (int) $this->at);
    }
}
