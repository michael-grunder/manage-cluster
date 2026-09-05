<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use RuntimeException;

/**
 * Creates directories without letting PHP's mkdir() warnings escape to output.
 *
 * A raw mkdir() emits an E_WARNING when the target already exists, which
 * corrupts the TUI renderers, so every failure is turned into a
 * RuntimeException carrying the underlying reason instead.
 */
final class DirectoryCreator
{
    /**
     * Create $path (and any missing parents) unless it already exists.
     */
    public static function ensure(string $path, int $mode, string $description): void
    {
        if (is_dir($path)) {
            return;
        }

        error_clear_last();
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException(self::failureMessage($description, $path));
        }
    }

    /**
     * Create $path (and any missing parents), failing if it already exists.
     */
    public static function createNew(string $path, int $mode, string $description): void
    {
        error_clear_last();
        if (!@mkdir($path, $mode, true)) {
            throw new RuntimeException(self::failureMessage($description, $path));
        }
    }

    private static function failureMessage(string $description, string $path): string
    {
        $reason = error_get_last()['message'] ?? null;
        if ($reason === null) {
            return sprintf('Failed to create %s directory: %s', $description, $path);
        }

        return sprintf('Failed to create %s directory: %s (%s)', $description, $path, $reason);
    }
}
