<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\CommandLineParser;
use PHPUnit\Framework\TestCase;

final class CommandLineParserTest extends TestCase
{
    public function testUsageIncludesCommandsSection(): void
    {
        $usage = CommandLineParser::usage();

        self::assertStringContainsString('bin/manage-cluster [OPTIONS] <COMMAND> [ARGS]', $usage);
        self::assertStringContainsString('Commands:', $usage);
        self::assertStringContainsString('start', $usage);
        self::assertStringContainsString('list', $usage);
        self::assertStringContainsString('chaos', $usage);
        self::assertStringContainsString('help', $usage);
    }

    public function testContextualUsageForStatusAllowsOptionalPort(): void
    {
        $usage = CommandLineParser::contextualUsage('status');

        self::assertStringContainsString('bin/manage-cluster status [PORT] [--watch]', $usage);
        self::assertStringContainsString('bin/manage-cluster status', $usage);
    }

    public function testContextualUsageForFillIncludesExamples(): void
    {
        $usage = CommandLineParser::contextualUsage('fill');

        self::assertStringContainsString('bin/manage-cluster fill [PORT] --size SIZE', $usage);
        self::assertStringContainsString('Options:', $usage);
        self::assertStringContainsString('--pin-primary PORT', $usage);
        self::assertStringContainsString('bin/manage-cluster fill --size 1g', $usage);
        self::assertStringContainsString('bin/manage-cluster fill 7000 --size 512m --pin-primary 7003', $usage);
    }

    public function testContextualUsageForChaosIncludesExamples(): void
    {
        $usage = CommandLineParser::contextualUsage('chaos');

        self::assertStringContainsString('bin/manage-cluster chaos SEED_PORT', $usage);
        self::assertStringContainsString('--categories LIST', $usage);
        self::assertStringContainsString('bin/manage-cluster chaos 7000 --dry-run', $usage);
    }

    public function testInferRequestedActionFindsPositionalAction(): void
    {
        $action = CommandLineParser::inferRequestedAction(['bin/manage-cluster', 'fill', '--size', '1g']);

        self::assertSame('fill', $action);
    }

    public function testInferRequestedActionFindsLongFlagAction(): void
    {
        $action = CommandLineParser::inferRequestedAction(['bin/manage-cluster', '--fill', '7000', '--size', '1g']);

        self::assertSame('fill', $action);
    }

    public function testInferRequestedActionSkipsOptionValues(): void
    {
        $action = CommandLineParser::inferRequestedAction(['bin/manage-cluster', '--gen-script', 'fill', 'start', '7000']);

        self::assertSame('start', $action);
    }

    public function testInferHelpActionFindsHelpTarget(): void
    {
        $action = CommandLineParser::inferHelpAction(['bin/manage-cluster', 'help', 'status']);

        self::assertSame('status', $action);
    }

    public function testParsesFlushActionWithLongFlag(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', '--flush', '7000', '7001']);

        self::assertSame('flush', $options->action);
        self::assertSame([7000, 7001], $options->ports);
    }

    public function testParsesFlushActionAsPositionalToken(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'flush', '7000']);

        self::assertSame('flush', $options->action);
        self::assertSame([7000], $options->ports);
    }

    public function testParsesKillActionAsPositionalToken(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'kill', '7000']);

        self::assertSame('kill', $options->action);
        self::assertSame([7000], $options->ports);
    }

    public function testParsesListActionWithoutPorts(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'list']);

        self::assertSame('list', $options->action);
        self::assertSame([], $options->ports);
    }

    public function testParsesStatusWithoutPorts(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'status']);

        self::assertSame('status', $options->action);
        self::assertSame([], $options->ports);
    }

    public function testKillRequiresExactlyOneSeedPort(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kill expects exactly one seed port.');

        $parser->parse(['bin/manage-cluster', 'kill', '7000', '7001']);
    }

    public function testWatchIsRejectedForFlush(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--watch can only be used with status or chaos.');

        $parser->parse(['bin/manage-cluster', 'flush', '7000', '--watch']);
    }

    public function testListRejectsPorts(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('list does not accept seed ports.');

        $parser->parse(['bin/manage-cluster', 'list', '7000']);
    }

    public function testParsesFillWithRequiredSizeAndNoPort(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'fill', '--size', '1g']);

        self::assertSame('fill', $options->action);
        self::assertSame([], $options->ports);
        self::assertNotNull($options->fill);
        self::assertSame(1024 ** 3, $options->fill->sizeBytes);
        self::assertSame(['string', 'set', 'list', 'hash', 'zset'], $options->fill->types);
        self::assertSame(53, $options->fill->members);
        self::assertSame(214749, $options->fill->memberSize);
        self::assertNull($options->fill->pinPrimaryPort);
    }

    public function testParsesFillWithAllKnobs(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            '--fill',
            '7000',
            '--size',
            '256m',
            '--types',
            'set,zset',
            '--members',
            '64',
            '--member-size',
            '2048',
            '--pin-primary',
            '7003',
        ]);

        self::assertSame('fill', $options->action);
        self::assertSame([7000], $options->ports);
        self::assertNotNull($options->fill);
        self::assertSame(256 * (1024 ** 2), $options->fill->sizeBytes);
        self::assertSame(['set', 'zset'], $options->fill->types);
        self::assertSame(64, $options->fill->members);
        self::assertSame(2048, $options->fill->memberSize);
        self::assertSame(7003, $options->fill->pinPrimaryPort);
    }

    public function testFillDerivesAdaptiveShapeFromCustomKeyTarget(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'fill', '--size', '1g', '--keys', '20000']);

        self::assertNotNull($options->fill);
        self::assertSame(14, $options->fill->members);
        self::assertSame(53688, $options->fill->memberSize);
    }

    public function testFillRequiresSize(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fill requires --size');

        $parser->parse(['bin/manage-cluster', 'fill', '7000']);
    }

    public function testFillKeepsFixedDefaultsWhenOnlyMembersIsProvided(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'fill', '--size', '1g', '--members', '32']);

        self::assertNotNull($options->fill);
        self::assertSame(32, $options->fill->members);
        self::assertSame(256, $options->fill->memberSize);
    }

    public function testSizeIsRejectedOutsideFill(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--size can only be used with fill.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--size', '1g']);
    }

    public function testStatusRejectsMoreThanOneSeedPort(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('status expects zero or one seed port.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '7001']);
    }

    public function testKeysIsRejectedOutsideFill(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--keys can only be used with fill.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--keys', '20000']);
    }

    public function testParsesAddReplicaWithAutoPortSelection(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'add-replica', '7000']);

        self::assertSame('add-replica', $options->action);
        self::assertSame([7000], $options->ports);
        self::assertNull($options->replicaPort);
    }

    public function testParsesRestartReplicaActionAsPositionalToken(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'restart-replica', '7000']);

        self::assertSame('restart-replica', $options->action);
        self::assertSame([7000], $options->ports);
    }

    public function testParsesChaosWithDefaults(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'chaos', '7000']);

        self::assertSame('chaos', $options->action);
        self::assertSame([7000], $options->ports);
        self::assertNotNull($options->chaos);
        self::assertSame(['replica-kill', 'replica-restart', 'replica-add'], $options->chaos->categories);
        self::assertSame(8, $options->chaos->intervalSeconds);
        self::assertFalse($options->chaos->dryRun);
    }

    public function testParsesChaosWithExplicitOptions(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            '--chaos',
            '7000',
            '--categories',
            'replica-kill,replica-restart',
            '--interval',
            '5',
            '--max-events',
            '10',
            '--max-failures',
            '3',
            '--dry-run',
            '--watch',
            '--seed',
            '123',
            '--wait-timeout',
            '90',
            '--cooldown',
            '4',
            '--unsafe',
        ]);

        self::assertNotNull($options->chaos);
        self::assertSame(['replica-kill', 'replica-restart'], $options->chaos->categories);
        self::assertSame(5, $options->chaos->intervalSeconds);
        self::assertSame(10, $options->chaos->maxEvents);
        self::assertSame(3, $options->chaos->maxFailures);
        self::assertTrue($options->chaos->dryRun);
        self::assertTrue($options->chaos->watch);
        self::assertSame(123, $options->chaos->seed);
        self::assertSame(90, $options->chaos->waitTimeoutSeconds);
        self::assertSame(4, $options->chaos->cooldownSeconds);
        self::assertTrue($options->chaos->unsafe);
    }

    public function testParsesStartServerArgsAfterDoubleDash(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            'start',
            '7000',
            '--',
            '--enable-debug-command',
            'local',
            '--save',
            '',
        ]);

        self::assertSame('start', $options->action);
        self::assertSame([7000, 7001, 7002], $options->ports);
        self::assertSame(3, $options->primaries);
        self::assertNull($options->generatedScriptPath);
        self::assertSame(['--enable-debug-command', 'local', '--save', ''], $options->startServerArgs);
    }

    public function testParsesStartPrimariesForSingleSeedPortExpansion(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'start', '7000', '--primaries', '4']);

        self::assertSame('start', $options->action);
        self::assertSame(4, $options->primaries);
        self::assertSame([7000, 7001, 7002, 7003], $options->ports);
    }

    public function testParsesStartPrimariesWithReplicasForSingleSeedPortExpansion(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'start', '7000', '--primaries', '4', '--replicas', '1']);

        self::assertSame('start', $options->action);
        self::assertSame(4, $options->primaries);
        self::assertSame(1, $options->replicas);
        self::assertSame([7000, 7001, 7002, 7003, 7004, 7005, 7006, 7007], $options->ports);
    }

    public function testRejectsStartPrimariesBelowClusterMinimum(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--primaries must be >= 3.');

        $parser->parse(['bin/manage-cluster', 'start', '7000', '--primaries', '2']);
    }

    public function testRejectsPrimariesOutsideStart(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--primaries can only be used with start.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--primaries', '4']);
    }

    public function testParsesGeneratedStartScriptPath(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            '--gen-script',
            'start-cluster.sh',
            'start',
            '{7000..7002}',
        ]);

        self::assertSame('start', $options->action);
        self::assertSame([7000, 7001, 7002], $options->ports);
        self::assertSame('start-cluster.sh', $options->generatedScriptPath);
    }

    public function testGenScriptIsRejectedOutsideStart(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--gen-script can only be used with start.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--gen-script', 'start.sh']);
    }

    public function testDoubleDashArgsAreRejectedOutsideStart(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Arguments after -- can only be used with start.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--', '--enable-debug-command', 'local']);
    }

    public function testParsesAddReplicaWithExplicitReplicaPort(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', '--add-replica', '7000', '--port', '7010']);

        self::assertSame('add-replica', $options->action);
        self::assertSame([7000], $options->ports);
        self::assertSame(7010, $options->replicaPort);
    }

    public function testAddReplicaRequiresExactlyOneSeedPort(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('add-replica expects exactly one seed port.');

        $parser->parse(['bin/manage-cluster', 'add-replica', '7000', '7001']);
    }

    public function testRestartReplicaRequiresExactlyOneSeedPort(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('restart-replica expects exactly one seed port.');

        $parser->parse(['bin/manage-cluster', 'restart-replica', '7000', '7001']);
    }

    public function testChaosRequiresExactlyOneSeedPort(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('chaos expects exactly one seed port.');

        $parser->parse(['bin/manage-cluster', 'chaos', '7000', '7001']);
    }

    public function testPortOptionIsRejectedOutsideAddReplica(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--port can only be used with add-replica.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--port', '7010']);
    }

    public function testCategoriesOptionIsRejectedOutsideChaos(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--categories can only be used with chaos.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--categories', 'replica-kill']);
    }

    public function testChaosRejectsUnknownCategory(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported chaos category: bogus');

        $parser->parse(['bin/manage-cluster', 'chaos', '7000', '--categories', 'bogus']);
    }
}
