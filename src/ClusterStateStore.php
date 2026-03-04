<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster;

use RuntimeException;

final class ClusterStateStore
{
    public function __construct(private readonly string $stateDir)
    {
    }

    public function ensureStateDirectory(): void
    {
        if (is_dir($this->stateDir)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->stateDir, 0o755, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Failed to create state directory: %s', $this->stateDir));
        }
    }

    public function createClusterDirectory(): string
    {
        $this->ensureStateDirectory();

        $path = sprintf('%s/cluster-%s-%s', rtrim($this->stateDir, '/'), date('Ymd-His'), bin2hex(random_bytes(3)));
        if (!mkdir($path, 0o755, true)) {
            throw new RuntimeException(sprintf('Failed to create cluster directory: %s', $path));
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function persistClusterMetadata(array $metadata): void
    {
        $clusterDir = $this->readRequiredString($metadata, 'cluster_dir');
        $clusterId = $this->readRequiredString($metadata, 'id');
        $ports = $this->readRequiredPorts($metadata, 'ports');

        $metadataPath = sprintf('%s/cluster.json', $clusterDir);
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($metadataPath, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write cluster metadata: %s', $metadataPath));
        }

        $this->mutateIndex(function (array $index) use ($clusterId, $clusterDir, $ports): array {
            $index['clusters'][$clusterId] = $clusterDir;
            foreach ($ports as $port) {
                $index['ports'][(string) $port] = $clusterId;
            }

            return $index;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClusterByPort(int $port): ?array
    {
        $index = $this->loadIndex();
        $clusterId = $index['ports'][(string) $port] ?? null;
        if (!is_string($clusterId)) {
            return null;
        }

        $clusterDir = $index['clusters'][$clusterId] ?? null;
        if (!is_string($clusterDir)) {
            return null;
        }

        return $this->loadClusterMetadata($clusterDir);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function removeClusterMetadata(array $metadata): void
    {
        $clusterId = $this->readRequiredString($metadata, 'id');
        $ports = $this->readRequiredPorts($metadata, 'ports');

        $this->mutateIndex(function (array $index) use ($clusterId, $ports): array {
            unset($index['clusters'][$clusterId]);
            foreach ($ports as $port) {
                if (($index['ports'][(string) $port] ?? null) === $clusterId) {
                    unset($index['ports'][(string) $port]);
                }
            }

            return $index;
        });

        $clusterDir = $this->readRequiredString($metadata, 'cluster_dir');
        $this->removeDirectory($clusterDir);
    }

    private function indexPath(): string
    {
        return sprintf('%s/index.json', rtrim($this->stateDir, '/'));
    }

    /**
     * @return array{clusters: array<string, string>, ports: array<string, string>}
     */
    private function loadIndex(): array
    {
        $this->ensureStateDirectory();

        $path = $this->indexPath();
        if (!file_exists($path)) {
            return [
                'clusters' => [],
                'ports' => [],
            ];
        }

        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException(sprintf('Failed to read index file: %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return [
                'clusters' => [],
                'ports' => [],
            ];
        }

        return [
            'clusters' => is_array($decoded['clusters'] ?? null) ? $decoded['clusters'] : [],
            'ports' => is_array($decoded['ports'] ?? null) ? $decoded['ports'] : [],
        ];
    }

    /**
     * @param callable(array{clusters: array<string, string>, ports: array<string, string>}): array{clusters: array<string, string>, ports: array<string, string>} $mutator
     */
    private function mutateIndex(callable $mutator): void
    {
        $this->ensureStateDirectory();

        $path = $this->indexPath();
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Failed to open index file: %s', $path));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Failed to lock index file: %s', $path));
            }

            $content = stream_get_contents($handle);
            if (!is_string($content)) {
                throw new RuntimeException(sprintf('Failed to read index file: %s', $path));
            }

            $index = ['clusters' => [], 'ports' => []];
            if (trim($content) !== '') {
                /** @var mixed $decoded */
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $index = [
                        'clusters' => is_array($decoded['clusters'] ?? null) ? $decoded['clusters'] : [],
                        'ports' => is_array($decoded['ports'] ?? null) ? $decoded['ports'] : [],
                    ];
                }
            }

            $next = $mutator($index);
            $encoded = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

            rewind($handle);
            if (!ftruncate($handle, 0)) {
                throw new RuntimeException(sprintf('Failed to truncate index file: %s', $path));
            }

            if (fwrite($handle, $encoded) === false) {
                throw new RuntimeException(sprintf('Failed to write index file: %s', $path));
            }

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadClusterMetadata(string $clusterDir): ?array
    {
        $path = sprintf('%s/cluster.json', $clusterDir);
        if (!file_exists($path)) {
            return null;
        }

        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException(sprintf('Failed to read cluster metadata: %s', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $array
     */
    private function readRequiredString(array $array, string $key): string
    {
        $value = $array[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Missing required metadata value: %s', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $array
     * @return list<int>
     */
    private function readRequiredPorts(array $array, string $key): array
    {
        $value = $array[$key] ?? null;
        if (!is_array($value) || $value === []) {
            throw new RuntimeException(sprintf('Missing required metadata value: %s', $key));
        }

        $ports = [];
        foreach ($value as $port) {
            if (!is_int($port) && !is_string($port)) {
                throw new RuntimeException(sprintf('Invalid port in metadata key: %s', $key));
            }

            $ports[] = (int) $port;
        }

        return $ports;
    }

    private function removeDirectory(string $path): void
    {
        $stateDirReal = realpath($this->stateDir);
        $targetReal = realpath($path);

        if ($stateDirReal === false || $targetReal === false) {
            return;
        }

        if (!str_starts_with($targetReal . '/', $stateDirReal . '/')) {
            throw new RuntimeException(sprintf('Refusing to remove path outside state dir: %s', $targetReal));
        }

        $items = scandir($targetReal);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = sprintf('%s/%s', $targetReal, $item);
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($targetReal);
    }
}
