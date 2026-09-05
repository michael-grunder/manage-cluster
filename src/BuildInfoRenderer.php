<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use DateTimeZone;

/**
 * Plain-text rendering of {@see BuildInfo} for the `version` command.
 */
final class BuildInfoRenderer
{
    private const int LABEL_WIDTH = 10;

    public function render(BuildInfo $buildInfo, InvocationName $invocation): string
    {
        $lines = [sprintf('%s %s', $invocation->command, $buildInfo->version)];

        if ($buildInfo->commit !== null) {
            $lines[] = self::row('commit', $buildInfo->commit);
        }

        if ($buildInfo->builtAt !== null) {
            $lines[] = self::row('built', $buildInfo->builtAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC');
        }

        $lines[] = self::row('source', $buildInfo->source->label());
        $lines[] = self::row('php', PHP_VERSION);

        return implode(PHP_EOL, $lines);
    }

    private static function row(string $label, string $value): string
    {
        return sprintf('%s%s', str_pad($label, self::LABEL_WIDTH), $value);
    }
}
