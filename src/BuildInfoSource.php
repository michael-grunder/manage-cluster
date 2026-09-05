<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

/**
 * Where the reported build information came from, which decides how much of it
 * can be trusted as a real release identity.
 */
enum BuildInfoSource: string
{
    /** Metadata embedded by `bin/build-phar` when the archive was built. */
    case Build = 'build';

    /** A source checkout, described from the working tree at runtime. */
    case Checkout = 'checkout';

    public function label(): string
    {
        return match ($this) {
            self::Build => 'PHAR build',
            self::Checkout => 'source checkout',
        };
    }
}
