<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\InvocationName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvocationNameTest extends TestCase
{
    private string $root = '';

    /**
     * @var list<string>
     */
    private const array LAYOUT = ['bin/manage-cluster', 'manage-cluster.phar', 'sbin/manage-cluster', 'shadow/manage-cluster'];

    protected function setUp(): void
    {
        $root = sprintf('%s/manage-cluster-invocation-%s', sys_get_temp_dir(), bin2hex(random_bytes(4)));
        foreach (['bin', 'sbin', 'shadow'] as $directory) {
            self::assertTrue(mkdir($root . '/' . $directory, 0o755, true));
        }

        $this->root = $root;

        foreach (self::LAYOUT as $relativePath) {
            $path = $root . '/' . $relativePath;
            self::assertNotFalse(file_put_contents($path, "#!/usr/bin/env php\n"));
            self::assertTrue(chmod($path, 0o755));
        }
    }

    protected function tearDown(): void
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return;
        }

        foreach (self::LAYOUT as $relativePath) {
            $path = $this->root . '/' . $relativePath;
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach (['bin', 'sbin', 'shadow'] as $directory) {
            @rmdir($this->root . '/' . $directory);
        }

        @rmdir($this->root);
    }

    /**
     * @param list<string> $argv
     * @param list<string> $pathDirectories
     */
    #[DataProvider('displayNameCases')]
    public function testDisplayNameFollowsHowTheCommandWasInvoked(array $argv, array $pathDirectories, string $expectedDisplay, string $expectedCommand): void
    {
        $invocation = InvocationName::fromArgv(
            $this->expandPaths($argv),
            $this->root,
            implode(PATH_SEPARATOR, $this->expandPaths($pathDirectories)),
        );

        self::assertSame($expectedDisplay, $invocation->display);
        self::assertSame($expectedCommand, $invocation->command);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, string, string}>
     */
    public static function displayNameCases(): iterable
    {
        yield 'bare name typed by the user' => [['manage-cluster', 'status'], [], 'manage-cluster', 'manage-cluster'];
        yield 'absolute path that PATH resolves to' => [['{dir}/sbin/manage-cluster'], ['{dir}/sbin'], 'manage-cluster', 'manage-cluster'];
        yield 'absolute path shadowed by an earlier PATH entry' => [['{dir}/sbin/manage-cluster'], ['{dir}/shadow', '{dir}/sbin'], 'sbin/manage-cluster', 'manage-cluster'];
        yield 'relative path inside the working directory' => [['bin/manage-cluster'], [], 'bin/manage-cluster', 'manage-cluster'];
        yield 'dot-slash relative path' => [['./bin/manage-cluster'], [], 'bin/manage-cluster', 'manage-cluster'];
        yield 'absolute path inside the working directory' => [['{dir}/bin/manage-cluster'], [], 'bin/manage-cluster', 'manage-cluster'];
        yield 'phar in the working directory keeps ./ so it is not read as a PATH lookup' => [['{dir}/manage-cluster.phar'], [], './manage-cluster.phar', 'manage-cluster.phar'];
        yield 'path outside the working directory stays as typed' => [['/opt/tools/manage-cluster'], [], '/opt/tools/manage-cluster', 'manage-cluster'];
        yield 'missing script path stays as typed' => [['../nowhere/manage-cluster'], [], '../nowhere/manage-cluster', 'manage-cluster'];
    }

    public function testEmptyArgvFallsBackToTheDefaultCommandName(): void
    {
        $invocation = InvocationName::fromArgv([], $this->root, '');

        self::assertSame(InvocationName::DEFAULT_COMMAND, $invocation->display);
        self::assertSame(InvocationName::DEFAULT_COMMAND, $invocation->command);
    }

    public function testBlankScriptNameFallsBackToTheDefaultCommandName(): void
    {
        $invocation = InvocationName::fromArgv(['   '], $this->root, '');

        self::assertSame(InvocationName::DEFAULT_COMMAND, $invocation->display);
        self::assertSame(InvocationName::DEFAULT_COMMAND, $invocation->command);
    }

    public function testEmptyDisplayNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation display name must not be empty.');

        new InvocationName('', 'manage-cluster');
    }

    public function testEmptyCommandNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invocation command name must not be empty.');

        new InvocationName('manage-cluster', '');
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function expandPaths(array $values): array
    {
        return array_map(fn (string $value): string => str_replace('{dir}', $this->root, $value), $values);
    }
}
