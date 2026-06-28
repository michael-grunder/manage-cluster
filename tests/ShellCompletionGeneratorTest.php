<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ShellCompletionGenerator;
use PHPUnit\Framework\TestCase;

final class ShellCompletionGeneratorTest extends TestCase
{
    public function testGeneratesBashCompletionScript(): void
    {
        $script = (new ShellCompletionGenerator())->generate('bash', 'manage-cluster');

        self::assertStringContainsString('complete -F _manage_cluster manage-cluster', $script);
        self::assertStringContainsString('start stop kill rebalance status list flush fill add-replica restart-replica chaos completions help', $script);
        self::assertStringContainsString('--primaries', $script);
        self::assertStringContainsString('--replica', $script);
        self::assertStringContainsString('bash zsh', $script);
    }

    public function testGeneratesZshCompletionScript(): void
    {
        $script = (new ShellCompletionGenerator())->generate('zsh', 'manage-cluster');

        self::assertStringContainsString('#compdef manage-cluster', $script);
        self::assertStringContainsString("'completions:Generate a shell completion script'", $script);
        self::assertStringContainsString("'--primaries'", $script);
        self::assertStringContainsString("'bash'", $script);
        self::assertStringContainsString("'zsh'", $script);
    }
}
