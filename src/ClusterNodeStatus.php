<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class ClusterNodeStatus
{
    public function __construct(
        public string $id,
        public string $ip,
        public int $port,
        public string $endpoint,
        public string $role,
        public int $replicationOffset,
        public string $health,
        public ?int $usedMemoryBytes = null,
    ) {
    }

    public function shortId(int $length = 8): string
    {
        return substr($this->id, 0, $length);
    }

    public function address(): string
    {
        $host = $this->endpoint !== '' ? $this->endpoint : $this->ip;

        return sprintf('%s:%d', $host, $this->port);
    }

    public function withUsedMemoryBytes(?int $usedMemoryBytes): self
    {
        return new self(
            id: $this->id,
            ip: $this->ip,
            port: $this->port,
            endpoint: $this->endpoint,
            role: $this->role,
            replicationOffset: $this->replicationOffset,
            health: $this->health,
            usedMemoryBytes: $usedMemoryBytes,
        );
    }
}
