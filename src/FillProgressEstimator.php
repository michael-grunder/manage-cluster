<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final class FillProgressEstimator
{
    private int $lastUsedBytes;
    private float $lastElapsedSeconds = 0.0;
    private int $observedBytes = 0;
    private float $observedSeconds = 0.0;

    public function __construct(
        int $startUsedBytes,
        private readonly int $targetUsedBytes,
    ) {
        $this->lastUsedBytes = $startUsedBytes;
    }

    public function estimateRemainingSeconds(int $currentUsedBytes, float $elapsedSeconds): ?int
    {
        $elapsedDelta = $elapsedSeconds - $this->lastElapsedSeconds;
        if ($elapsedDelta > 0) {
            $this->observedSeconds += $elapsedDelta;
            $this->observedBytes += max(0, $currentUsedBytes - $this->lastUsedBytes);
            $this->lastElapsedSeconds = $elapsedSeconds;
            $this->lastUsedBytes = $currentUsedBytes;
        }

        if ($currentUsedBytes >= $this->targetUsedBytes) {
            return 0;
        }

        if ($this->observedSeconds <= 0.0 || $this->observedBytes <= 0) {
            return null;
        }

        $remainingBytes = max(0, $this->targetUsedBytes - $currentUsedBytes);
        $bytesPerSecond = $this->observedBytes / $this->observedSeconds;
        if ($bytesPerSecond <= 0.0) {
            return null;
        }

        return (int) ceil($remainingBytes / $bytesPerSecond);
    }
}
