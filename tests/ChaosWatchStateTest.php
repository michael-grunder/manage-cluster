<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosClusterView;
use Mgrunder\CreateCluster\ChaosEventRecord;
use Mgrunder\CreateCluster\ChaosCategorySelection;
use Mgrunder\CreateCluster\ChaosOptions;
use Mgrunder\CreateCluster\ChaosRuntimeState;
use Mgrunder\CreateCluster\ChaosWatchLogEntry;
use Mgrunder\CreateCluster\ChaosWatchLogLevel;
use Mgrunder\CreateCluster\ChaosWatchState;
use Mgrunder\CreateCluster\SlotMigrationStrategy;
use PHPUnit\Framework\TestCase;

final class ChaosWatchStateTest extends TestCase
{
    public function testLogKeepsNewestEntriesAndCountsWhatItDropped(): void
    {
        $state = $this->state(maxLogEntries: 3);

        foreach (range(1, 5) as $index) {
            $state->log(ChaosWatchLogLevel::Waiting, sprintf('poll %d', $index));
        }

        self::assertCount(3, $state->entries());
        self::assertSame(2, $state->droppedEntryCount());
        self::assertSame(['poll 3', 'poll 4', 'poll 5'], $this->messages($state->entries()));
    }

    public function testLogTagsEntriesWithTheInflightEvent(): void
    {
        $runtime = $this->runtime();
        $state = new ChaosWatchState($runtime, $this->options());

        $state->log(ChaosWatchLogLevel::Info, 'before the event');
        $runtime->inflightEvent = $this->event(7);
        $state->log(ChaosWatchLogLevel::Event, 'during the event');

        $entries = $state->entries();
        self::assertNull($entries[0]->eventId);
        self::assertSame(7, $entries[1]->eventId);
    }

    public function testVisibleEntriesShowsTheNewestLinesThatFit(): void
    {
        $state = $this->state();
        foreach (range(1, 10) as $index) {
            $state->log(ChaosWatchLogLevel::Waiting, sprintf('poll %d', $index));
        }

        self::assertSame(['poll 8', 'poll 9', 'poll 10'], $this->messages($state->visibleEntries(3)));
        self::assertSame([], $state->visibleEntries(0));
    }

    public function testScrollingBackHoldsThePositionWhileNewEntriesArrive(): void
    {
        $state = $this->state();
        foreach (range(1, 10) as $index) {
            $state->log(ChaosWatchLogLevel::Waiting, sprintf('poll %d', $index));
        }

        $state->scrollOlder(4);

        self::assertFalse($state->followingLatest());
        self::assertSame(['poll 4', 'poll 5', 'poll 6'], $this->messages($state->visibleEntries(3)));

        $state->log(ChaosWatchLogLevel::Waiting, 'poll 11');

        self::assertSame(['poll 4', 'poll 5', 'poll 6'], $this->messages($state->visibleEntries(3)));
    }

    public function testScrollingBackStopsAtTheOldestEntry(): void
    {
        $state = $this->state();
        foreach (range(1, 4) as $index) {
            $state->log(ChaosWatchLogLevel::Waiting, sprintf('poll %d', $index));
        }

        $state->scrollOlder(50);

        self::assertSame(3, $state->scrollBack());
        self::assertSame(['poll 1'], $this->messages($state->visibleEntries(3)));

        $state->scrollToOldest();
        self::assertSame(3, $state->scrollBack());
    }

    public function testScrollingForwardResumesFollowingTheLatestEntries(): void
    {
        $state = $this->state();
        foreach (range(1, 10) as $index) {
            $state->log(ChaosWatchLogLevel::Waiting, sprintf('poll %d', $index));
        }

        $state->scrollOlder(5);
        $state->scrollNewer(2);
        self::assertSame(3, $state->scrollBack());

        $state->scrollNewer(99);
        self::assertTrue($state->followingLatest());

        $state->scrollOlder(3);
        $state->scrollToLatest();
        self::assertTrue($state->followingLatest());
    }

    public function testCountersFollowTheRuntimeHistory(): void
    {
        $runtime = $this->runtime();
        $state = new ChaosWatchState($runtime, $this->options());

        self::assertSame(0, $state->completedEventCount());
        self::assertSame(0, $state->failedEventCount());

        $runtime->rememberHistory($this->event(1)->withStatus('completed', 100.0));
        $runtime->rememberHistory($this->event(2)->withStatus('failed', 101.0));
        $runtime->rememberHistory($this->event(3)->withStatus('completed', 102.0));
        $runtime->markFailure();
        $runtime->inflightEvent = $this->event(4);

        self::assertSame(2, $state->completedEventCount());
        self::assertSame(1, $state->failedEventCount());
        self::assertSame(1, $state->consecutiveFailureCount());
        self::assertSame(4, $state->inflightEvent()?->id);
    }

    public function testElapsedSecondsNeverGoesNegative(): void
    {
        $runtime = new ChaosRuntimeState('cluster-1', 7000, 1_000.0, ['replica-kill']);
        $state = new ChaosWatchState($runtime, $this->options());

        self::assertSame(30.0, $state->elapsedSeconds(1_030.0));
        self::assertSame(0.0, $state->elapsedSeconds(900.0));
    }

    public function testViewAndQuitRequestAreExposedToTheRenderer(): void
    {
        $state = $this->state();

        self::assertNull($state->view());
        self::assertFalse($state->quitRequested());
        self::assertSame(7000, $state->seedPort());

        $view = new ChaosClusterView(
            clusterId: 'cluster-1',
            seedPort: 7000,
            topologyHash: 'hash',
            clusterDown: false,
            broadlyHealthy: true,
            nodeStateByPort: [],
            primaryStateByPort: [],
            replicaStateByPort: [],
            degradedPrimaryPorts: [],
        );
        $state->updateView($view);
        $state->requestQuit();

        self::assertSame($view, $state->view());
        self::assertTrue($state->quitRequested());
    }

    private function state(int $maxLogEntries = ChaosWatchState::DEFAULT_MAX_LOG_ENTRIES): ChaosWatchState
    {
        return new ChaosWatchState($this->runtime(), $this->options(), $maxLogEntries);
    }

    private function runtime(): ChaosRuntimeState
    {
        return new ChaosRuntimeState('cluster-1', 7000, microtime(true), ['replica-kill']);
    }

    private function options(): ChaosOptions
    {
        return new ChaosOptions(
            categories: ChaosCategorySelection::fromCategories(['replica-kill']),
            intervalSeconds: 5,
            maxEvents: null,
            maxFailures: 3,
            abortOnFailure: false,
            dryRun: false,
            watch: true,
            seed: null,
            waitTimeoutSeconds: 60,
            cooldownSeconds: 2,
            allowSlotMigration: false,
            allowPrimaryFailover: false,
            allowReplicaReparent: false,
            allowPrimaryAdd: false,
            allowPrimaryRemove: false,
            unsafe: false,
            slotMigrationStrategy: SlotMigrationStrategy::Balanced,
        );
    }

    private function event(int $id): ChaosEventRecord
    {
        return new ChaosEventRecord(
            id: $id,
            category: ChaosOptions::CATEGORY_REPLICA_KILL,
            status: 'planned',
            targetPort: 7005,
            targetPrimaryPort: 7000,
            startedAt: 100.0,
            completedAt: null,
            summary: sprintf('replica-kill target=7005 primary=7000 (#%d)', $id),
            postcondition: 'replica 7005 is gone',
        );
    }

    /**
     * @param list<ChaosWatchLogEntry> $entries
     * @return list<string>
     */
    private function messages(array $entries): array
    {
        return array_map(
            static fn (ChaosWatchLogEntry $entry): string => $entry->message,
            $entries,
        );
    }
}
