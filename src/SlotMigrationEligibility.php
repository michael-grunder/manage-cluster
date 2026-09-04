<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

final readonly class SlotMigrationEligibility
{
    /**
     * @return list<string>
     */
    public function blockers(ChaosClusterView $view, bool $unsafe): array
    {
        if ($view->clusterDown) {
            return ['cluster is down'];
        }

        if ($unsafe) {
            return [];
        }

        $blockers = [];
        if ($view->degradedPrimaryPorts !== []) {
            $blockers[] = sprintf(
                'degraded primaries=%s',
                implode(',', array_map('strval', $view->degradedPrimaryPorts)),
            );
        }

        $unreachable = [];
        $failed = [];
        $syncing = [];
        $loading = [];

        foreach ($view->nodeStateByPort as $port => $node) {
            if (!$node->knownByCluster) {
                continue;
            }

            if (!$node->reachable) {
                $unreachable[] = $port;
            }
            if ($node->isFailed) {
                $failed[] = $port;
            }
            if ($node->isSyncing) {
                $syncing[] = $port;
            }
            if ($node->isLoading) {
                $loading[] = $port;
            }
        }

        foreach ([
            'unreachable nodes' => $unreachable,
            'failed nodes' => $failed,
            'syncing nodes' => $syncing,
            'loading nodes' => $loading,
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
