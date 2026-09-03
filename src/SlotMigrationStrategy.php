<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

enum SlotMigrationStrategy: string
{
    /**
     * Weighted selection: primaries owning more slots are likelier to give slots
     * away, primaries owning fewer are likelier to receive them, so repeated
     * migrations keep the cluster roughly balanced.
     */
    case Balanced = 'balanced';

    /**
     * Uniform selection with no regard for the overall distribution, which is
     * expected to produce fragmented and lopsided topologies over time.
     */
    case Random = 'random';

    public static function parse(string $value): self
    {
        $normalized = strtolower(trim($value));
        $strategy = self::tryFrom($normalized);
        if ($strategy instanceof self) {
            return $strategy;
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported slot migration strategy: %s. Expected one of: %s.',
            $value,
            implode(', ', self::names()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $strategy): string => $strategy->value, self::cases());
    }

    public function description(): string
    {
        return match ($this) {
            self::Balanced => 'weighted so slots drift toward an even distribution',
            self::Random => 'uniformly random, ignoring the current distribution',
        };
    }
}
