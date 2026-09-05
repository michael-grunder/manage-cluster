<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\CommandLineParser;
use Mgrunder\CreateCluster\InvocationName;
use Mgrunder\CreateCluster\KillMethod;
use Mgrunder\CreateCluster\SlotMigrationStrategy;
use PHPUnit\Framework\TestCase;

final class CommandLineParserTest extends TestCase
{
    private static function parserNamed(string $display = 'bin/manage-cluster'): CommandLineParser
    {
        return new CommandLineParser(new InvocationName($display, 'manage-cluster'));
    }

    public function testUsageIncludesCommandsSection(): void
    {
        $usage = self::parserNamed()->usage();

        self::assertStringContainsString('bin/manage-cluster [OPTIONS] <COMMAND> [ARGS]', $usage);
        self::assertStringContainsString('Commands:', $usage);
        self::assertStringContainsString('start', $usage);
        self::assertStringContainsString('list', $usage);
        self::assertStringContainsString('chaos', $usage);
        self::assertStringContainsString('completions', $usage);
        self::assertStringContainsString('help', $usage);
    }

    public function testContextualUsageForStatusAllowsOptionalPort(): void
    {
        $usage = self::parserNamed()->contextualUsage('status');

        self::assertStringContainsString('bin/manage-cluster status [PORT] [--watch]', $usage);
        self::assertStringContainsString('bin/manage-cluster status', $usage);
    }

    public function testContextualUsageForFillIncludesExamples(): void
    {
        $usage = self::parserNamed()->contextualUsage('fill');

        self::assertStringContainsString('bin/manage-cluster fill [PORT] --size SIZE', $usage);
        self::assertStringContainsString('Options:', $usage);
        self::assertStringContainsString('--pin-primary PORT', $usage);
        self::assertStringContainsString('bin/manage-cluster fill --size 1g', $usage);
        self::assertStringContainsString('bin/manage-cluster fill 7000 --size 512m --pin-primary 7003', $usage);
    }

    public function testContextualUsageForChaosIncludesExamples(): void
    {
        $usage = self::parserNamed()->contextualUsage('chaos');

        self::assertStringContainsString('bin/manage-cluster chaos SEED_PORT', $usage);
        self::assertStringContainsString('--categories LIST', $usage);
        self::assertStringContainsString('bin/manage-cluster chaos 7000 --dry-run', $usage);
    }

    public function testContextualUsageForCompletionsIncludesExamples(): void
    {
        $usage = self::parserNamed()->contextualUsage('completions');

        self::assertStringContainsString('bin/manage-cluster completions bash|zsh', $usage);
        self::assertStringContainsString('bin/manage-cluster completions bash', $usage);
        self::assertStringContainsString('bin/manage-cluster completions zsh', $usage);
    }

    public function testUsageRendersTheInvokedCommandName(): void
    {
        $usage = self::parserNamed('manage-cluster')->usage();

        self::assertStringContainsString('manage-cluster [OPTIONS] <COMMAND> [ARGS]', $usage);
        self::assertStringContainsString('manage-cluster start 7000', $usage);
        self::assertStringContainsString('Run `manage-cluster help <command>` for command-specific help.', $usage);
        self::assertStringNotContainsString('bin/manage-cluster', $usage);
    }

    public function testContextualUsageRendersTheInvokedCommandName(): void
    {
        $usage = self::parserNamed('./manage-cluster.phar')->contextualUsage('fill');

        self::assertStringContainsString('./manage-cluster.phar fill [PORT] --size SIZE', $usage);
        self::assertStringContainsString('./manage-cluster.phar fill --size 1g', $usage);
        self::assertStringNotContainsString('bin/manage-cluster', $usage);
    }

    public function testHelpOptionThrowsUsageForTheInvokedCommandName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#^Usage:\n  manage-cluster \[OPTIONS\]#');

        self::parserNamed('manage-cluster')->parse(['manage-cluster', '--help']);
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
        self::assertSame(KillMethod::Shutdown, $options->killMethod);
    }

    public function testParsesKillReplicaTarget(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'kill', '7000', '--replica', '7002']);

        self::assertSame('kill', $options->action);
        self::assertSame([7000], $options->ports);
        self::assertSame(7002, $options->replicaPort);
    }

    public function testParsesKillNosaveMethod(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'kill', '7000', '--replica', '7002', '--method', 'nosave']);

        self::assertSame('kill', $options->action);
        self::assertSame(KillMethod::NoSave, $options->killMethod);
    }

    public function testParsesKillSignalMethod(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'kill', '7000', '--replica', '7002', '--method', 'sigsegv']);

        self::assertSame('kill', $options->action);
        self::assertSame(KillMethod::SigSegv, $options->killMethod);
    }

    public function testParsesKillReplicaTargetWithPrimaryConstraint(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'kill', '7000', '--primary', '7000', '--replica', '7002']);

        self::assertSame('kill', $options->action);
        self::assertSame([7000], $options->ports);
        self::assertSame(7002, $options->replicaPort);
        self::assertSame(7000, $options->primaryPort);
    }

    public function testParsesKillAllWithWait(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'kill', '7000', '--all', '--wait']);

        self::assertSame('kill', $options->action);
        self::assertTrue($options->all);
        self::assertTrue($options->wait);
    }

    public function testParsesKillAllWithPrimaryScope(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'kill', '7000', '--primary', '7000', '--all']);

        self::assertSame('kill', $options->action);
        self::assertTrue($options->all);
        self::assertSame(7000, $options->primaryPort);
    }

    public function testParsesRestartReplicaTarget(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'restart-replica', '7000', '--replica', '7002']);

        self::assertSame('restart-replica', $options->action);
        self::assertSame([7000], $options->ports);
        self::assertSame(7002, $options->replicaPort);
    }

    public function testParsesRestartReplicaTargetWithPrimaryConstraint(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'restart-replica', '7000', '--primary', '7000', '--replica', '7002']);

        self::assertSame('restart-replica', $options->action);
        self::assertSame([7000], $options->ports);
        self::assertSame(7002, $options->replicaPort);
        self::assertSame(7000, $options->primaryPort);
    }

    public function testParsesRestartReplicaAllWithPrimaryScopeAndWait(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'restart-replica', '7000', '--primary', '7000', '--all', '--wait']);

        self::assertSame('restart-replica', $options->action);
        self::assertTrue($options->all);
        self::assertTrue($options->wait);
        self::assertSame(7000, $options->primaryPort);
    }

    public function testParsesRestartReplicaConfigOverrides(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            'restart-replica',
            '7000',
            '--replica',
            '7002',
            '--config',
            'replica-serve-stale-data=no',
            '--config',
            'loglevel=debug',
        ]);

        self::assertSame('restart-replica', $options->action);
        self::assertSame([
            'replica-serve-stale-data' => 'no',
            'loglevel' => 'debug',
        ], $options->restartConfigOverrides);
    }

    public function testReplicaTargetIsRejectedForUnrelatedCommands(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--replica can only be used with kill or restart-replica.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--replica', '7002']);
    }

    public function testPrimaryConstraintIsRejectedForUnrelatedCommands(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--primary can only be used with kill or restart-replica.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--primary', '7000']);
    }

    public function testPrimaryConstraintRequiresReplicaTargetOrAll(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--primary requires --replica or --all.');

        $parser->parse(['bin/manage-cluster', 'kill', '7000', '--primary', '7000']);
    }

    public function testAllConflictsWithReplicaTarget(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--all and --replica cannot be used together.');

        $parser->parse(['bin/manage-cluster', 'kill', '7000', '--all', '--replica', '7002']);
    }

    public function testAllIsRejectedForUnrelatedCommands(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--all can only be used with kill or restart-replica.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--all']);
    }

    public function testWaitIsRejectedForUnrelatedCommands(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--wait can only be used with kill or restart-replica.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--wait']);
    }

    public function testConfigOverrideIsRejectedForUnrelatedCommands(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--config can only be used with restart-replica.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--config', 'replica-serve-stale-data=no']);
    }

    public function testConfigOverrideRequiresNameValuePair(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--config expects NAME=VALUE.');

        $parser->parse(['bin/manage-cluster', 'restart-replica', '7000', '--config', 'replica-serve-stale-data']);
    }

    public function testConfigOverrideRejectsInvalidName(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Redis config name: replica serve stale data');

        $parser->parse(['bin/manage-cluster', 'restart-replica', '7000', '--config', 'replica serve stale data=no']);
    }

    public function testParsesListActionWithoutPorts(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'list']);

        self::assertSame('list', $options->action);
        self::assertSame([], $options->ports);
    }

    public function testParsesCompletionsActionWithRequiredShell(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'completions', 'zsh']);

        self::assertSame('completions', $options->action);
        self::assertSame([], $options->ports);
        self::assertSame('zsh', $options->completionShell);
    }

    public function testCompletionsRequiresSupportedShell(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported completion shell: powershell. Expected one of: bash, zsh.');

        $parser->parse(['bin/manage-cluster', 'completions', 'powershell']);
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

    public function testParsesFillWithFractionalGigabyteSize(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'fill', '--size', '2.5g']);

        self::assertNotNull($options->fill);
        self::assertSame((int) (2.5 * (1024 ** 3)), $options->fill->sizeBytes);
    }

    public function testParsesFillWithFractionalMegabyteSize(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'fill', '--size', '18.5m']);

        self::assertNotNull($options->fill);
        self::assertSame((int) (18.5 * (1024 ** 2)), $options->fill->sizeBytes);
    }

    public function testParsesFillWithFractionalSizeRoundedUpToByte(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'fill', '--size', '.1k']);

        self::assertNotNull($options->fill);
        self::assertSame(103, $options->fill->sizeBytes);
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
        self::assertSame(SlotMigrationStrategy::Balanced, $options->chaos->slotMigrationStrategy);
        self::assertSame(16, $options->chaos->slotMigrationBatch);
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
            '--slot-strategy',
            'random',
            '--slot-batch',
            '48',
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
        self::assertSame(SlotMigrationStrategy::Random, $options->chaos->slotMigrationStrategy);
        self::assertSame(48, $options->chaos->slotMigrationBatch);
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
        self::assertSame([
            ['enable-debug-command', 'local'],
            ['save', ''],
        ], $options->startConfigDirectives);
    }

    public function testParsesUnprefixedStartConfigDirectivesAfterDoubleDash(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            'start',
            '7000',
            '--replicas',
            '2',
            '--',
            'replica-serve-stale-data',
            'no',
        ]);

        self::assertSame(['--replica-serve-stale-data', 'no'], $options->startServerArgs);
        self::assertSame([
            ['replica-serve-stale-data', 'no'],
        ], $options->startConfigDirectives);
    }

    public function testRejectsIncompleteStartConfigDirectiveAfterDoubleDash(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Arguments after -- must be Redis config directive pairs: NAME VALUE.');

        $parser->parse([
            'bin/manage-cluster',
            'start',
            '7000',
            '--',
            'replica-serve-stale-data',
        ]);
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

    public function testMethodOptionIsRejectedOutsideKill(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--method can only be used with kill.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--method', 'sigterm']);
    }

    public function testKillRejectsUnknownMethod(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported kill method: sigusr1');

        $parser->parse(['bin/manage-cluster', 'kill', '7000', '--method', 'sigusr1']);
    }

    public function testChaosRejectsUnknownCategory(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported chaos category: bogus');

        $parser->parse(['bin/manage-cluster', 'chaos', '7000', '--categories', 'bogus']);
    }

    public function testAllowSlotMigrationAddsTheCategoryToTheDefaults(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'chaos', '7000', '--allow-slot-migration']);

        self::assertNotNull($options->chaos);
        self::assertTrue($options->chaos->allowSlotMigration);
        self::assertSame(
            ['replica-kill', 'replica-restart', 'replica-add', 'slot-migration'],
            $options->chaos->categories,
        );
    }

    public function testAllowSlotMigrationDoesNotDuplicateAnExplicitCategory(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            'chaos',
            '7000',
            '--categories',
            'slot-migration',
            '--allow-slot-migration',
        ]);

        self::assertNotNull($options->chaos);
        self::assertSame(['slot-migration'], $options->chaos->categories);
    }

    public function testAllowPrimaryFailoverAddsTheCategoryToTheDefaults(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'chaos', '7000', '--allow-primary-failover']);

        self::assertNotNull($options->chaos);
        self::assertTrue($options->chaos->allowPrimaryFailover);
        self::assertSame(
            ['replica-kill', 'replica-restart', 'replica-add', 'primary-failover'],
            $options->chaos->categories,
        );
    }

    public function testAllowPrimaryFailoverDoesNotDuplicateAnExplicitCategory(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            'chaos',
            '7000',
            '--categories',
            'primary-failover',
            '--allow-primary-failover',
        ]);

        self::assertNotNull($options->chaos);
        self::assertSame(['primary-failover'], $options->chaos->categories);
    }

    public function testAllowPrimaryFailoverRequiresChaos(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--allow-primary-failover can only be used with chaos.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--allow-primary-failover']);
    }

    public function testAllowReplicaReparentAddsTheCategoryToTheDefaults(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse(['bin/manage-cluster', 'chaos', '7000', '--allow-replica-reparent']);

        self::assertNotNull($options->chaos);
        self::assertTrue($options->chaos->allowReplicaReparent);
        self::assertSame(
            ['replica-kill', 'replica-restart', 'replica-add', 'replica-reparent'],
            $options->chaos->categories,
        );
    }

    public function testAllowReplicaReparentDoesNotDuplicateAnExplicitCategory(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            'chaos',
            '7000',
            '--categories',
            'replica-reparent',
            '--allow-replica-reparent',
        ]);

        self::assertNotNull($options->chaos);
        self::assertSame(['replica-reparent'], $options->chaos->categories);
    }

    public function testAllowReplicaReparentRequiresChaos(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--allow-replica-reparent can only be used with chaos.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--allow-replica-reparent']);
    }

    public function testAllowPrimaryAddAndRemoveAddTheCategoriesToTheDefaults(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            'chaos',
            '7000',
            '--allow-primary-add',
            '--allow-primary-remove',
        ]);

        self::assertNotNull($options->chaos);
        self::assertTrue($options->chaos->allowPrimaryAdd);
        self::assertTrue($options->chaos->allowPrimaryRemove);
        self::assertSame(
            ['replica-kill', 'replica-restart', 'replica-add', 'primary-add', 'primary-remove'],
            $options->chaos->categories,
        );
    }

    public function testAllowPrimaryAddDoesNotDuplicateAnExplicitCategory(): void
    {
        $parser = new CommandLineParser();

        $options = $parser->parse([
            'bin/manage-cluster',
            'chaos',
            '7000',
            '--categories',
            'primary-add,primary-remove',
            '--allow-primary-add',
        ]);

        self::assertNotNull($options->chaos);
        self::assertSame(['primary-add', 'primary-remove'], $options->chaos->categories);
    }

    public function testAllowPrimaryAddRequiresChaos(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--allow-primary-add can only be used with chaos.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--allow-primary-add']);
    }

    public function testAllowPrimaryRemoveRequiresChaos(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--allow-primary-remove can only be used with chaos.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--allow-primary-remove']);
    }

    public function testChaosRejectsUnknownSlotStrategy(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported slot migration strategy: sideways. Expected one of: balanced, random.');

        $parser->parse(['bin/manage-cluster', 'chaos', '7000', '--slot-strategy', 'sideways']);
    }

    public function testChaosRejectsOutOfRangeSlotBatch(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--slot-batch must be between 1 and 16384.');

        $parser->parse(['bin/manage-cluster', 'chaos', '7000', '--slot-batch', '0']);
    }

    public function testSlotStrategyOptionIsRejectedOutsideChaos(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--slot-strategy can only be used with chaos.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--slot-strategy', 'random']);
    }

    public function testSlotBatchOptionIsRejectedOutsideChaos(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--slot-batch can only be used with chaos.');

        $parser->parse(['bin/manage-cluster', 'status', '7000', '--slot-batch', '8']);
    }
}
