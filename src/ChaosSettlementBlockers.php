<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Shared "is the cluster reorganizing itself right now" checks.
 *
 * Events that keep every process running, such as primary failover and replica
 * reparenting, tolerate an intentionally killed replica elsewhere but must not
 * start while a primary is missing or a node is still joining, because those
 * states suggest an election or a membership change is already underway.
 */
final class ChaosSettlementBlockers
{
    /**
     * @return list<string>
     */
    public static function unsettledMembership(ChaosClusterView $view): array
    {
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

        $blockers = [];
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
