<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class CommandLineOptions
{
    /**
     * @param list<int> $ports
     * @param array<string, string> $restartConfigOverrides
     * @param list<array{0: string, 1: string}> $startConfigDirectives
     * @param list<string> $startServerArgs
     */
    public function __construct(
        public string $action,
        public array $ports,
        public ?int $replicaPort,
        public ?int $primaryPort,
        public array $restartConfigOverrides,
        public ?string $generatedScriptPath,
        public int $primaries,
        public int $replicas,
        public string $redisBinary,
        public string $redisCliBinary,
        public ?string $announceIp,
        public bool $tls,
        public int $tlsDays,
        public int $tlsRsaBits,
        public string $stateDir,
        public bool $watch,
        public ?FillOptions $fill,
        public ?ChaosOptions $chaos,
        public array $startConfigDirectives,
        public array $startServerArgs,
        public bool $all = false,
        public bool $wait = false,
    ) {
    }
}
