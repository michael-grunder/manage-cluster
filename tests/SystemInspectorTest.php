<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\SystemInspector;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RequiresFunction('chmod')]
final class SystemInspectorTest extends TestCase
{
    public function testEnsureExecutableExistsAcceptsExplicitExecutablePath(): void
    {
        $path = $this->createTempExecutable();
        $inspector = new SystemInspector();

        try {
            $inspector->ensureExecutableExists($path, 'redis-server');
            self::assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function testEnsureExecutableExistsRejectsNonExecutableExplicitPath(): void
    {
        $path = $this->createTempFile();
        chmod($path, 0644);
        $inspector = new SystemInspector();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('redis-server executable not found: %s', $path));

        try {
            $inspector->ensureExecutableExists($path, 'redis-server');
        } finally {
            @unlink($path);
        }
    }

    public function testDescribeServerBinaryParsesRedisVersionSummary(): void
    {
        $path = $this->createVersionExecutable(
            "#!/bin/sh\nprintf 'Redis server v=8.0.0 sha=e91a340e:0 malloc=jemalloc-5.3.0 bits=64 build=266d33df6ad406c0\\n'\n",
        );
        $inspector = new SystemInspector();

        try {
            self::assertSame('Redis 8.0.0 (e91a340e)', $inspector->describeServerBinary($path));
        } finally {
            @unlink($path);
        }
    }

    public function testDescribeServerBinaryParsesValkeyVersionSummary(): void
    {
        $path = $this->createVersionExecutable(
            "#!/bin/sh\nprintf 'Valkey server v=8.1.0 sha=67c86837:0 malloc=jemalloc-5.3.0 bits=64 build=44a3b245605a8226\\n'\n",
        );
        $inspector = new SystemInspector();

        try {
            self::assertSame('Valkey 8.1.0 (67c86837)', $inspector->describeServerBinary($path));
        } finally {
            @unlink($path);
        }
    }

    public function testDescribeServerBinaryFallsBackToBasenameWhenOutputIsUnexpected(): void
    {
        $path = $this->createVersionExecutable("#!/bin/sh\nprintf 'unexpected version output\\n'\n");
        $inspector = new SystemInspector();

        try {
            self::assertSame(basename($path), $inspector->describeServerBinary($path));
        } finally {
            @unlink($path);
        }
    }

    private function createTempExecutable(): string
    {
        $path = $this->createTempFile();
        chmod($path, 0755);

        return $path;
    }

    private function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'system-inspector-');
        self::assertIsString($path);
        file_put_contents($path, "#!/bin/sh\nexit 0\n");

        return $path;
    }

    private function createVersionExecutable(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'system-inspector-version-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        chmod($path, 0755);

        return $path;
    }
}
