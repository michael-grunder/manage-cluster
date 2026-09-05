<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\DirectoryCreator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DirectoryCreatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $tempDir = tempnam(sys_get_temp_dir(), 'directory-creator-');
        self::assertIsString($tempDir);
        unlink($tempDir);
        self::assertTrue(mkdir($tempDir, 0o755));

        $this->tempDir = $tempDir;
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->tempDir);
    }

    public function testEnsureCreatesNestedDirectories(): void
    {
        $path = sprintf('%s/node-7000/tls', $this->tempDir);

        DirectoryCreator::ensure($path, 0o755, 'node');

        self::assertDirectoryExists($path);
    }

    public function testEnsureIsQuietWhenDirectoryAlreadyExists(): void
    {
        $path = sprintf('%s/node-7000', $this->tempDir);
        DirectoryCreator::ensure($path, 0o755, 'node');

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            DirectoryCreator::ensure($path, 0o755, 'node');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertDirectoryExists($path);
    }

    public function testEnsureThrowsWhenPathIsAFile(): void
    {
        $path = sprintf('%s/node-7000', $this->tempDir);
        self::assertNotFalse(file_put_contents($path, ''));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Failed to create node directory: %s', $path));

        DirectoryCreator::ensure($path, 0o755, 'node');
    }

    public function testCreateNewThrowsWhenDirectoryAlreadyExists(): void
    {
        $path = sprintf('%s/cluster-1', $this->tempDir);
        DirectoryCreator::createNew($path, 0o755, 'cluster');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Failed to create cluster directory: %s', $path));

        DirectoryCreator::createNew($path, 0o755, 'cluster');
    }

    public function testCreateNewReportsTheUnderlyingReason(): void
    {
        $path = sprintf('%s/cluster-1', $this->tempDir);
        DirectoryCreator::createNew($path, 0o755, 'cluster');

        try {
            DirectoryCreator::createNew($path, 0o755, 'cluster');
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('File exists', $exception->getMessage());
        }
    }

    private function removeRecursively(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeRecursively(sprintf('%s/%s', $path, $entry));
        }

        rmdir($path);
    }
}
