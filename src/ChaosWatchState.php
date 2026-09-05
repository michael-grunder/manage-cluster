<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * View model for the fullscreen chaos watch: the latest cluster topology plus
 * a bounded log of the events the run has produced. The chaos loop pushes into
 * it, the renderer reads from it, and neither has to know about the other.
 */
final class ChaosWatchState
{
    public const int DEFAULT_MAX_LOG_ENTRIES = 500;

    private ?ChaosClusterView $view = null;

    /**
     * @var list<ChaosWatchLogEntry>
     */
    private array $entries = [];

    private int $droppedEntryCount = 0;

    /**
     * Lines the log is scrolled back from the newest entry. Zero means the
     * view follows new entries as they arrive.
     */
    private int $scrollBack = 0;

    private bool $quitRequested = false;

    public function __construct(
        private readonly ChaosRuntimeState $runtime,
        private readonly ChaosOptions $chaos,
        private readonly int $maxLogEntries = self::DEFAULT_MAX_LOG_ENTRIES,
    ) {
    }

    public function log(ChaosWatchLogLevel $level, string $message, ?float $at = null): void
    {
        $this->entries[] = new ChaosWatchLogEntry(
            at: $at ?? microtime(true),
            level: $level,
            message: $message,
            eventId: $this->runtime->inflightEvent?->id,
        );

        $overflow = count($this->entries) - $this->maxLogEntries;
        if ($overflow > 0) {
            $this->entries = array_slice($this->entries, $overflow);
            $this->droppedEntryCount += $overflow;
        }

        // Scrolled-back readers keep looking at the same lines instead of
        // being dragged along by every new arrival.
        if ($this->scrollBack > 0) {
            $this->scrollBack = min($this->scrollBack + 1, max(0, count($this->entries) - 1));
        }
    }

    public function updateView(ChaosClusterView $view): void
    {
        $this->view = $view;
    }

    public function view(): ?ChaosClusterView
    {
        return $this->view;
    }

    public function options(): ChaosOptions
    {
        return $this->chaos;
    }

    public function seedPort(): int
    {
        return $this->runtime->seedPort;
    }

    public function elapsedSeconds(float $now): float
    {
        return max(0.0, $now - $this->runtime->startedAt);
    }

    public function inflightEvent(): ?ChaosEventRecord
    {
        return $this->runtime->inflightEvent;
    }

    public function completedEventCount(): int
    {
        return $this->runtime->completedEventCount();
    }

    public function failedEventCount(): int
    {
        return $this->runtime->failedEventCount();
    }

    public function consecutiveFailureCount(): int
    {
        return $this->runtime->consecutiveFailures;
    }

    /**
     * @return list<ChaosWatchLogEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function droppedEntryCount(): int
    {
        return $this->droppedEntryCount;
    }

    public function followingLatest(): bool
    {
        return $this->scrollBack === 0;
    }

    public function scrollBack(): int
    {
        return $this->scrollBack;
    }

    public function scrollOlder(int $lines): void
    {
        $this->scrollBack = min(max(0, count($this->entries) - 1), $this->scrollBack + max(0, $lines));
    }

    public function scrollNewer(int $lines): void
    {
        $this->scrollBack = max(0, $this->scrollBack - max(0, $lines));
    }

    public function scrollToOldest(): void
    {
        $this->scrollBack = max(0, count($this->entries) - 1);
    }

    public function scrollToLatest(): void
    {
        $this->scrollBack = 0;
    }

    /**
     * The window of entries that fits a log pane $height lines tall, honouring
     * how far the reader has scrolled back.
     *
     * @return list<ChaosWatchLogEntry>
     */
    public function visibleEntries(int $height): array
    {
        if ($height <= 0 || $this->entries === []) {
            return [];
        }

        $total = count($this->entries);
        $scrollBack = min($this->scrollBack, max(0, $total - 1));
        $end = $total - $scrollBack;
        $start = max(0, $end - $height);

        return array_slice($this->entries, $start, $end - $start);
    }

    public function requestQuit(): void
    {
        $this->quitRequested = true;
    }

    public function quitRequested(): bool
    {
        return $this->quitRequested;
    }
}
