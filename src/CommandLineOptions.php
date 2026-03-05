<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class CommandLineOptions
{
    /**
     * @param list<int> $ports
     */
    public function __construct(
        public string $action,
        public array $ports,
        public int $replicas,
        public string $redisBinary,
        public string $redisCliBinary,
        public ?string $announceIp,
        public bool $tls,
        public int $tlsDays,
        public int $tlsRsaBits,
        public string $stateDir,
        public bool $watch,
    ) {
    }
}
