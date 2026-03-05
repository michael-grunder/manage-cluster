<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\CommandLineParser;
use PHPUnit\Framework\TestCase;

final class CommandLineParserTest extends TestCase
{
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

    public function testWatchIsRejectedForFlush(): void
    {
        $parser = new CommandLineParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--watch can only be used with status.');

        $parser->parse(['bin/manage-cluster', 'flush', '7000', '--watch']);
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
}
