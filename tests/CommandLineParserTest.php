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
}
