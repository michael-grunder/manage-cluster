<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\CommandLineOptions;
use Mgrunder\CreateCluster\StartScriptGenerator;
use PHPUnit\Framework\TestCase;

final class StartScriptGeneratorTest extends TestCase
{
    public function testGeneratesScriptWithPreflightChecksAndClusterCreateCommand(): void
    {
        $generator = new StartScriptGenerator();

        $script = $generator->generate(new CommandLineOptions(
            action: 'start',
            ports: [7000, 7001, 7002],
            replicaPort: null,
            generatedScriptPath: 'start-cluster.sh',
            replicas: 0,
            redisBinary: '/opt/redis/bin/redis-server',
            redisCliBinary: '/opt/redis/bin/redis-cli',
            announceIp: null,
            tls: false,
            tlsDays: 3650,
            tlsRsaBits: 2048,
            stateDir: '/tmp/manage-cluster',
            watch: false,
            fill: null,
            chaos: null,
            startServerArgs: ['--save', '', '--enable-debug-command', 'local'],
        ));

        self::assertStringContainsString("REQUESTED_REDIS_SERVER='/opt/redis/bin/redis-server'", $script);
        self::assertStringContainsString("REQUESTED_REDIS_CLI='/opt/redis/bin/redis-cli'", $script);
        self::assertStringContainsString('step "Validating required executables and runtime prerequisites"', $script);
        self::assertStringContainsString('assert_port_available "$port"', $script);
        self::assertStringContainsString('START_SERVER_ARGS=(', $script);
        self::assertStringContainsString("  '--save'", $script);
        self::assertStringContainsString("  ''", $script);
        self::assertStringContainsString('cluster_create_command+=(--cluster create)', $script);
        self::assertStringContainsString('cluster_create_command+=(--cluster-replicas "$REPLICAS" --cluster-yes)', $script);
        self::assertStringContainsString('show_node_log_tail "$port"', $script);
    }

    public function testRejectsGenerationForNonStartAction(): void
    {
        $generator = new StartScriptGenerator();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--gen-script can only be used with start.');

        $generator->generate(new CommandLineOptions(
            action: 'status',
            ports: [7000],
            replicaPort: null,
            generatedScriptPath: 'status.sh',
            replicas: 0,
            redisBinary: 'redis-server',
            redisCliBinary: 'redis-cli',
            announceIp: null,
            tls: false,
            tlsDays: 3650,
            tlsRsaBits: 2048,
            stateDir: '/tmp/manage-cluster',
            watch: false,
            fill: null,
            chaos: null,
            startServerArgs: [],
        ));
    }
}
