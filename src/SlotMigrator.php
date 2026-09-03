<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use RuntimeException;
use Throwable;

/**
 * Executes a {@see SlotMigrationPlan} one slot at a time using the standard
 * IMPORTING/MIGRATING/NODE handshake, moving any keys the slot still holds.
 */
final readonly class SlotMigrator
{
    private const int KEY_BATCH = 128;
    private const int MIGRATE_TIMEOUT_MS = 15_000;

    public function __construct(
        private RedisNodeClient $redisNodeClient,
        private ConsoleOutput $output,
    ) {
    }

    /**
     * @param list<int> $notifyPorts reachable primaries told about the new owner
     */
    public function migrate(
        SlotMigrationPlan $plan,
        string $destinationHost,
        array $notifyPorts,
        bool $tls,
        ?string $caCert,
    ): void {
        $slots = $plan->slots();

        $this->output->step(sprintf(
            'Migrating %d slot%s (%s) from primary %d to primary %d',
            count($slots),
            count($slots) === 1 ? '' : 's',
            $plan->describeRanges(),
            $plan->sourcePort,
            $plan->destinationPort,
        ));

        $movedKeys = 0;
        foreach ($slots as $slot) {
            $movedKeys += $this->migrateSlot($plan, $slot, $destinationHost, $notifyPorts, $tls, $caCert);
        }

        $this->output->success(sprintf(
            'Migrated %d slot%s and %d key%s to primary %d',
            count($slots),
            count($slots) === 1 ? '' : 's',
            $movedKeys,
            $movedKeys === 1 ? '' : 's',
            $plan->destinationPort,
        ));
    }

    /**
     * @param list<int> $notifyPorts
     * @return int number of keys moved out of this slot
     */
    private function migrateSlot(
        SlotMigrationPlan $plan,
        int $slot,
        string $destinationHost,
        array $notifyPorts,
        bool $tls,
        ?string $caCert,
    ): int {
        try {
            $this->redisNodeClient->clusterSetSlotImporting(
                $plan->destinationPort,
                $tls,
                $caCert,
                $slot,
                $plan->sourceNodeId,
            );
            $this->redisNodeClient->clusterSetSlotMigrating(
                $plan->sourcePort,
                $tls,
                $caCert,
                $slot,
                $plan->destinationNodeId,
            );

            $movedKeys = $this->drainSlotKeys($plan, $slot, $destinationHost, $tls, $caCert);

            // The destination is told first so it owns the slot before the source
            // stops serving it, then every other primary learns the new owner
            // instead of waiting for gossip.
            $this->redisNodeClient->clusterSetSlotNode($plan->destinationPort, $tls, $caCert, $slot, $plan->destinationNodeId);
            $this->redisNodeClient->clusterSetSlotNode($plan->sourcePort, $tls, $caCert, $slot, $plan->destinationNodeId);

            foreach ($notifyPorts as $notifyPort) {
                if ($notifyPort === $plan->sourcePort || $notifyPort === $plan->destinationPort) {
                    continue;
                }

                $this->redisNodeClient->clusterSetSlotNode($notifyPort, $tls, $caCert, $slot, $plan->destinationNodeId);
            }

            return $movedKeys;
        } catch (Throwable $exception) {
            $this->clearMigrationState($plan, $slot, $tls, $caCert);

            throw new RuntimeException(sprintf(
                'Failed to migrate slot %d from primary %d to primary %d: %s',
                $slot,
                $plan->sourcePort,
                $plan->destinationPort,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    private function drainSlotKeys(
        SlotMigrationPlan $plan,
        int $slot,
        string $destinationHost,
        bool $tls,
        ?string $caCert,
    ): int {
        $movedKeys = 0;

        while (true) {
            $keys = $this->redisNodeClient->clusterGetKeysInSlot($plan->sourcePort, $tls, $caCert, $slot, self::KEY_BATCH);
            if ($keys === []) {
                return $movedKeys;
            }

            $this->redisNodeClient->migrateKeys(
                port: $plan->sourcePort,
                tls: $tls,
                caCert: $caCert,
                destinationHost: $destinationHost,
                destinationPort: $plan->destinationPort,
                keys: $keys,
                timeoutMs: self::MIGRATE_TIMEOUT_MS,
            );

            $movedKeys += count($keys);

            if (count($keys) < self::KEY_BATCH) {
                return $movedKeys;
            }
        }
    }

    /**
     * Best-effort cleanup so a failed migration does not leave the slot pinned
     * in an importing or migrating state.
     */
    private function clearMigrationState(SlotMigrationPlan $plan, int $slot, bool $tls, ?string $caCert): void
    {
        foreach ([$plan->destinationPort, $plan->sourcePort] as $port) {
            try {
                $this->redisNodeClient->clusterSetSlotStable($port, $tls, $caCert, $slot);
            } catch (Throwable $exception) {
                $this->output->warning(sprintf(
                    'Could not clear migration state for slot %d on port %d: %s',
                    $slot,
                    $port,
                    $exception->getMessage(),
                ));
            }
        }
    }
}
