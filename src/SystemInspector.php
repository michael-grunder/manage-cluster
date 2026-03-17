<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SystemInspector
{
    public function ensureExecutableExists(string $binary, string $name): void
    {
        $this->resolveExecutablePath($binary, $name);
    }

    public function describeServerBinary(string $binary): string
    {
        $resolvedBinary = $this->resolveExecutablePath($binary, 'redis-server');
        $process = new Process([$resolvedBinary, '--version']);
        $process->run();

        $versionOutput = trim($process->getOutput() . $process->getErrorOutput());
        if ($process->isSuccessful()) {
            $parsedSummary = $this->parseServerVersionSummary($versionOutput);
            if ($parsedSummary !== null) {
                return $parsedSummary;
            }
        }

        return basename($resolvedBinary);
    }

    private function resolveExecutablePath(string $binary, string $name): string
    {
        if ($this->isExplicitPath($binary)) {
            if (is_file($binary) && is_executable($binary)) {
                return $binary;
            }

            throw new RuntimeException(sprintf('%s executable not found: %s', $name, $binary));
        }

        $finder = new ExecutableFinder();
        $path = $finder->find($binary);

        if ($path === null) {
            throw new RuntimeException(sprintf('%s executable not found: %s', $name, $binary));
        }

        return $path;
    }

    private function parseServerVersionSummary(string $versionOutput): ?string
    {
        if (!preg_match('/^(Redis|Valkey)\s+server\s+v=([^\s]+)\s+sha=([0-9a-fA-F]+):/mi', $versionOutput, $matches)) {
            return null;
        }

        return sprintf('%s %s (%s)', $matches[1], $matches[2], $matches[3]);
    }

    private function isExplicitPath(string $binary): bool
    {
        return str_contains($binary, DIRECTORY_SEPARATOR)
            || (DIRECTORY_SEPARATOR !== '\\' && str_contains($binary, '\\'))
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $binary) === 1;
    }

    public function isPortListening(int $port): bool
    {
        $stream = @stream_socket_client(
            sprintf('tcp://127.0.0.1:%d', $port),
            $errorCode,
            $errorMessage,
            0.2,
        );

        if (is_resource($stream)) {
            fclose($stream);

            return true;
        }

        return false;
    }

    /**
     * @param list<int> $ports
     */
    public function waitForPortsToClose(array $ports, float $seconds = 5.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $open = array_filter($ports, fn (int $port): bool => $this->isPortListening($port));
            if ($open === []) {
                return;
            }

            usleep(100_000);
        }
    }
}
