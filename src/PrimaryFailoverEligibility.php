<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Cluster-wide preconditions for a coordinated primary failover.
 *
 * A normal `CLUSTER FAILOVER` needs authorization from a majority of the
 * slot-owning primaries, so the voting checks here hold even with `--unsafe`.
 * The shard being promoted is vetted separately by PrimaryFailoverPlanner;
 * chaos deliberately does not require the rest of the cluster to be free of
 * intentionally killed replicas, otherwise failover would never fire in a run
 * that also churns replicas.
 */
final readonly class PrimaryFailoverEligibility
{
    public const int MINIMUM_VOTING_PRIMARIES = 3;

    /**
     * @return list<string>
     */
    public function blockers(ChaosClusterView $view, bool $unsafe): array
    {
        if ($view->clusterDown) {
            return ['cluster is down'];
        }

        $blockers = [];
        $votingPrimaries = 0;
        $reachableVoters = 0;

        foreach ($view->primaryStateByPort as $primary) {
            if (!$primary->ownsSlots()) {
                continue;
            }

            $votingPrimaries++;
            if ($primary->reachable) {
                $reachableVoters++;
            }
        }

        if ($votingPrimaries < self::MINIMUM_VOTING_PRIMARIES) {
            $blockers[] = sprintf(
                'needs at least %d slot-owning primaries, found %d',
                self::MINIMUM_VOTING_PRIMARIES,
                $votingPrimaries,
            );
        } elseif ($reachableVoters < intdiv($votingPrimaries, 2) + 1) {
            $blockers[] = sprintf(
                'no voting majority: %d of %d slot-owning primaries reachable',
                $reachableVoters,
                $votingPrimaries,
            );
        }

        if ($unsafe) {
            return $blockers;
        }

        $unreachablePrimaries = [];
        $failedPrimaries = [];
        $handshaking = [];

        foreach ($view->nodeStateByPort as $port => $node) {
            if (!$node->knownByCluster) {
                continue;
            }

            if ($node->isHandshake) {
                $handshaking[] = $port;
            }

            if ($node->role !== 'primary') {
                continue;
            }

            if (!$node->reachable) {
                $unreachablePrimaries[] = $port;
            }

            if ($node->isFailed) {
                $failedPrimaries[] = $port;
            }
        }

        foreach ([
            'unreachable primaries' => $unreachablePrimaries,
            'failed primaries' => $failedPrimaries,
            'handshaking nodes' => $handshaking,
        ] as $label => $ports) {
            if ($ports === []) {
                continue;
            }

            sort($ports, SORT_NUMERIC);
            $blockers[] = sprintf('%s=%s', $label, implode(',', array_map('strval', $ports)));
        }

        return $blockers;
    }
}
