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
        $host = $this->host();

        return sprintf('%s:%d', $host, $this->port);
    }

    public function displayAddress(bool $collapseLoopbackHost = false): string
    {
        if ($collapseLoopbackHost && $this->isLoopbackHost()) {
            return (string) $this->port;
        }

        return $this->address();
    }

    public function isLoopbackHost(): bool
    {
        $host = strtolower(trim($this->host(), '[]'));
        if ($host === 'localhost') {
            return true;
        }

        $packed = @inet_pton($host);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ord($packed[0]) === 127;
        }

        return $packed === inet_pton('::1');
    }

    private function host(): string
    {
        return $this->endpoint !== '' ? $this->endpoint : $this->ip;
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
