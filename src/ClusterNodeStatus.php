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
}
