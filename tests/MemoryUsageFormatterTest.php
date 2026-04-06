<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\MemoryUsageFormatter;
use PHPUnit\Framework\TestCase;

final class MemoryUsageFormatterTest extends TestCase
{
    public function testFormatsNullAsDash(): void
    {
        self::assertSame('-', MemoryUsageFormatter::format(null));
    }

    public function testFormatsBytesUsingBinaryUnits(): void
    {
        self::assertSame('512 B', MemoryUsageFormatter::format(512));
        self::assertSame('1.0 KiB', MemoryUsageFormatter::format(1024));
        self::assertSame('1.5 KiB', MemoryUsageFormatter::format(1536));
        self::assertSame('2.0 MiB', MemoryUsageFormatter::format(2 * 1024 * 1024));
    }
}
