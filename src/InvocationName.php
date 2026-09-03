<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use InvalidArgumentException;

/**
 * How the CLI was invoked, so help text and completion scripts can echo the
 * command the user actually typed instead of a hardcoded path.
 *
 * The same code runs from `bin/manage-cluster` in a checkout and from a built
 * PHAR installed on `PATH` as `manage-cluster`, so the name is derived from
 * `$argv[0]` at runtime.
 */
final readonly class InvocationName
{
    public const string DEFAULT_COMMAND = 'manage-cluster';

    /**
     * @param string $display Command as it should be shown in help output; always re-runnable as printed.
     * @param string $command Bare command name, used where only a name is valid such as completion registration.
     */
    public function __construct(
        public string $display,
        public string $command,
    ) {
        if ($display === '') {
            throw new InvalidArgumentException('Invocation display name must not be empty.');
        }

        if ($command === '') {
            throw new InvalidArgumentException('Invocation command name must not be empty.');
        }
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_COMMAND, self::DEFAULT_COMMAND);
    }

    /**
     * @param list<string> $argv
     * @param string|null $workingDirectory Directory help output should be relative to; defaults to the current one.
     * @param string|null $path PATH to test installed commands against; defaults to the environment's.
     */
    public static function fromArgv(array $argv, ?string $workingDirectory = null, ?string $path = null): self
    {
        $script = trim($argv[0] ?? '');
        if ($script === '') {
            return self::default();
        }

        $command = basename($script);
        if ($command === '') {
            return self::default();
        }

        if ($workingDirectory === null) {
            $currentDirectory = getcwd();
            $workingDirectory = $currentDirectory === false ? null : $currentDirectory;
        }

        if ($path === null) {
            $environmentPath = getenv('PATH');
            $path = is_string($environmentPath) ? $environmentPath : null;
        }

        return new self(self::displayFor($script, $command, $workingDirectory, $path), $command);
    }

    private static function displayFor(string $script, string $command, ?string $workingDirectory, ?string $path): string
    {
        // No directory separator means the shell already resolved it through PATH.
        if (!str_contains($script, '/')) {
            return $script;
        }

        // A `#!` script started from PATH still arrives here as the absolute path
        // the shell resolved, so ask PATH whether the bare name runs this file.
        $resolvedScript = self::resolve($script, $workingDirectory);
        if ($resolvedScript !== null && self::isFirstOnPath($resolvedScript, $command, $path)) {
            return $command;
        }

        return self::relativeToWorkingDirectory($script, $workingDirectory) ?? $script;
    }

    /**
     * Whether typing the bare command name would run this exact file, rather than
     * another executable of the same name earlier on PATH.
     */
    private static function isFirstOnPath(string $resolvedScript, string $command, ?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/') . '/' . $command;
            if (!is_file($candidate) || !is_executable($candidate)) {
                continue;
            }

            return realpath($candidate) === $resolvedScript;
        }

        return false;
    }

    private static function resolve(string $script, ?string $workingDirectory): ?string
    {
        if (str_starts_with($script, '/')) {
            $resolved = realpath($script);

            return $resolved === false ? null : $resolved;
        }

        if ($workingDirectory === null) {
            return null;
        }

        // Relative script paths are resolved against the directory the help text
        // is being written for, which is the current directory at runtime.
        $resolved = realpath(rtrim($workingDirectory, '/') . '/' . $script);

        return $resolved === false ? null : $resolved;
    }

    private static function relativeToWorkingDirectory(string $script, ?string $workingDirectory): ?string
    {
        if ($workingDirectory === null) {
            return null;
        }

        $resolvedWorkingDirectory = realpath($workingDirectory);
        if ($resolvedWorkingDirectory === false) {
            return null;
        }

        $resolvedScript = self::resolve($script, $resolvedWorkingDirectory);
        if ($resolvedScript === null) {
            return null;
        }

        $prefix = rtrim($resolvedWorkingDirectory, '/') . '/';
        if (!str_starts_with($resolvedScript, $prefix)) {
            return null;
        }

        $relative = substr($resolvedScript, strlen($prefix));
        if ($relative === '') {
            return null;
        }

        // A bare relative name would read as a PATH lookup, so keep it explicit.
        return str_contains($relative, '/') ? $relative : './' . $relative;
    }
}
