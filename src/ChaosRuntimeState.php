<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class ChaosRuntimeState
{
    /**
     * How many recent events repetition damping looks back over. Inspecting
     * only the previous event is not enough: a two-shard cluster can alternate
     * between its shards and never trip a one-event check, which is how a
     * single category ends up owning an entire run.
     */
    public const int RECENCY_WINDOW = 6;

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

    /**
     * The newest event matching a category, optionally narrowed to one target
     * port and to the recency window instead of the whole run.
     */
    public function mostRecentMatching(string $category, ?int $targetPort = null, ?int $window = null): ?ChaosEventRecord
    {
        $history = $window === null ? $this->history : $this->recentHistory($window);

        for ($index = count($history) - 1; $index >= 0; $index--) {
            $event = $history[$index];
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
     * The tail of the run that repetition damping looks at, oldest first.
     *
     * @return list<ChaosEventRecord>
     */
    public function recentHistory(int $window = self::RECENCY_WINDOW): array
    {
        if ($window < 1) {
            return [];
        }

        return array_slice($this->history, -$window);
    }

    /**
     * How many of the recent events belong to a category, optionally narrowed
     * to the ones that aimed at a single port.
     */
    public function recentEventCount(string $category, ?int $targetPort = null, int $window = self::RECENCY_WINDOW): int
    {
        $count = 0;
        foreach ($this->recentHistory($window) as $event) {
            if ($event->category !== $category) {
                continue;
            }

            if ($targetPort !== null && $event->targetPort !== $targetPort) {
                continue;
            }

            $count++;
        }

        return $count;
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

    public function completedEventCount(): int
    {
        return count(array_filter(
            $this->history,
            static fn (ChaosEventRecord $event): bool => $event->status === 'completed',
        ));
    }

    public function failedEventCount(): int
    {
        return count(array_filter(
            $this->history,
            static fn (ChaosEventRecord $event): bool => $event->status === 'failed',
        ));
    }
}
