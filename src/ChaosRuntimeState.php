<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ChaosRuntimeState
{
    public int $eventCounter = 0;
    public int $consecutiveFailures = 0;
    public ?ChaosEventRecord $inflightEvent = null;
    public ?string $lastStableTopologyHash = null;

    /**
     * @var list<ChaosEventRecord>
     */
    public array $history = [];

    /**
     * @var array<int, int>
     */
    public array $lastKnownPrimaryByReplicaPort = [];

    /**
     * @param list<string> $allowedCategories
     */
    public function __construct(
        public readonly string $clusterId,
        public readonly int $seedPort,
        public readonly float $startedAt,
        public readonly array $allowedCategories,
    ) {
    }

    public function nextEventId(): int
    {
        $this->eventCounter++;

        return $this->eventCounter;
    }

    public function rememberReplicaPrimary(int $replicaPort, int $primaryPort): void
    {
        $this->lastKnownPrimaryByReplicaPort[$replicaPort] = $primaryPort;
    }

    public function lastKnownPrimaryForReplica(int $replicaPort): ?int
    {
        return $this->lastKnownPrimaryByReplicaPort[$replicaPort] ?? null;
    }

    public function markFailure(): void
    {
        $this->consecutiveFailures++;
    }

    public function resetFailures(): void
    {
        $this->consecutiveFailures = 0;
    }

    public function rememberHistory(ChaosEventRecord $event): void
    {
        $this->history[] = $event;
    }

    public function mostRecentMatching(string $category, ?int $targetPort = null): ?ChaosEventRecord
    {
        for ($index = count($this->history) - 1; $index >= 0; $index--) {
            $event = $this->history[$index];
            if ($event->category !== $category) {
                continue;
            }

            if ($targetPort !== null && $event->targetPort !== $targetPort) {
                continue;
            }

            return $event;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function intentionallyDownReplicaPorts(): array
    {
        $down = [];
        foreach ($this->history as $event) {
            if ($event->category === ChaosOptions::CATEGORY_REPLICA_KILL && $event->status === 'completed' && $event->targetPort !== null) {
                $down[$event->targetPort] = true;
            }

            if ($event->category === ChaosOptions::CATEGORY_REPLICA_RESTART && $event->status === 'completed' && $event->targetPort !== null) {
                unset($down[$event->targetPort]);
            }
        }

        return array_map('intval', array_keys($down));
    }

    public function lastEventTargeted(string $category, int $port): bool
    {
        $lastEvent = $this->history[count($this->history) - 1] ?? null;

        return $lastEvent instanceof ChaosEventRecord
            && $lastEvent->category === $category
            && $lastEvent->targetPort === $port;
    }

    public function completedEventCount(): int
    {
        return count(array_filter(
            $this->history,
            static fn (ChaosEventRecord $event): bool => $event->status === 'completed',
        ));
    }
}
