<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\SlotMigrationStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlotMigrationStrategyTest extends TestCase
{
    #[DataProvider('parseProvider')]
    public function testParseAcceptsSupportedNames(string $value, SlotMigrationStrategy $expected): void
    {
        self::assertSame($expected, SlotMigrationStrategy::parse($value));
    }

    /**
     * @return iterable<string, array{value: string, expected: SlotMigrationStrategy}>
     */
    public static function parseProvider(): iterable
    {
        yield 'balanced' => [
            'value' => 'balanced',
            'expected' => SlotMigrationStrategy::Balanced,
        ];

        yield 'random' => [
            'value' => 'random',
            'expected' => SlotMigrationStrategy::Random,
        ];

        yield 'mixed case and padding' => [
            'value' => '  Random ',
            'expected' => SlotMigrationStrategy::Random,
        ];
    }

    public function testParseRejectsUnknownStrategies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported slot migration strategy: chaotic. Expected one of: balanced, random.');

        SlotMigrationStrategy::parse('chaotic');
    }

    public function testNamesMatchTheDocumentedOptions(): void
    {
        self::assertSame(['balanced', 'random'], SlotMigrationStrategy::names());
    }
}
