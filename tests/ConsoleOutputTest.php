<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ConsoleOutput;
use PHPUnit\Framework\TestCase;

final class ConsoleOutputTest extends TestCase
{
    public function testPlainOutputUsesLogStylePrefixes(): void
    {
        $stdout = fopen('php://temp', 'r+');
        $stderr = fopen('php://temp', 'r+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $output = new ConsoleOutput(interactive: false, stdout: $stdout, stderr: $stderr);

        $output->step('Starting node');
        $output->info('Using state directory');
        $output->success('Node ready');
        $output->warning('Retrying');
        $output->detail('State', '/tmp/manage-cluster/cluster-1');
        $output->error('Startup failed');

        rewind($stdout);
        rewind($stderr);

        self::assertSame(
            "[..] Starting node\n" .
            "[i] Using state directory\n" .
            "[ok] Node ready\n" .
            "[!] Retrying\n" .
            "State: /tmp/manage-cluster/cluster-1\n",
            stream_get_contents($stdout),
        );
        self::assertSame("[error] Startup failed\n", stream_get_contents($stderr));
    }

    public function testInteractiveProgressUsesAnsiAndFinishesCleanly(): void
    {
        $stdout = fopen('php://temp', 'r+');
        $stderr = fopen('php://temp', 'r+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $output = new ConsoleOutput(interactive: true, stdout: $stdout, stderr: $stderr);

        $output->progress('[00:00:01 50%] 128.00 MiB/256.00 MiB, 1,000 keys', true);
        $output->finishProgress();

        rewind($stdout);
        $written = stream_get_contents($stdout);

        self::assertStringContainsString("\r\033[2K", $written);
        self::assertStringContainsString("\033[36;1m●\033[0m [00:00:01 50%] 128.00 MiB/256.00 MiB, 1,000 keys", $written);
        self::assertStringEndsWith(PHP_EOL, $written);
    }

    public function testInteractiveLineOutputFlushesExistingProgressLine(): void
    {
        $stdout = fopen('php://temp', 'r+');
        $stderr = fopen('php://temp', 'r+');

        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $output = new ConsoleOutput(interactive: true, stdout: $stdout, stderr: $stderr);

        $output->progress('Working', true);
        $output->success('Done');

        rewind($stdout);
        $written = stream_get_contents($stdout);

        self::assertStringContainsString("\r\033[2K", $written);
        self::assertStringContainsString("\n\033[32;1m✓\033[0m Done\n", $written);
    }
}
