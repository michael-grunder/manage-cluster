<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use Mgrunder\CreateCluster\ChaosWatchLogLevel;
use Mgrunder\CreateCluster\ConsoleOutputLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChaosWatchLogLevelTest extends TestCase
{
    #[DataProvider('plainPrefixCases')]
    public function testPlainPrefixOnlyDecoratesWholeEventLines(ChaosWatchLogLevel $level, string $expected): void
    {
        self::assertSame($expected, $level->plainPrefix());
    }

    /**
     * @return iterable<string, array{ChaosWatchLogLevel, string}>
     */
    public static function plainPrefixCases(): iterable
    {
        yield 'event' => [ChaosWatchLogLevel::Event, '[chaos] '];
        yield 'plan' => [ChaosWatchLogLevel::Plan, '[plan ] '];
        yield 'done' => [ChaosWatchLogLevel::Done, '[done ] '];
        yield 'waiting' => [ChaosWatchLogLevel::Waiting, ''];
        yield 'progress' => [ChaosWatchLogLevel::Progress, ''];
        yield 'failure' => [ChaosWatchLogLevel::Failure, ''];
    }

    #[DataProvider('verbosityCases')]
    public function testVerboseLevelsAreTheOnesWatchOnly(ChaosWatchLogLevel $level, bool $expected): void
    {
        self::assertSame($expected, $level->isVerbose());
    }

    /**
     * @return iterable<string, array{ChaosWatchLogLevel, bool}>
     */
    public static function verbosityCases(): iterable
    {
        yield 'waiting is verbose' => [ChaosWatchLogLevel::Waiting, true];
        yield 'progress is verbose' => [ChaosWatchLogLevel::Progress, true];
        yield 'info is verbose' => [ChaosWatchLogLevel::Info, true];
        yield 'event is always shown' => [ChaosWatchLogLevel::Event, false];
        yield 'plan is always shown' => [ChaosWatchLogLevel::Plan, false];
        yield 'done is always shown' => [ChaosWatchLogLevel::Done, false];
        yield 'warning is always shown' => [ChaosWatchLogLevel::Warning, false];
        yield 'failure is always shown' => [ChaosWatchLogLevel::Failure, false];
    }

    #[DataProvider('plainSeverityCases')]
    public function testWatchLevelsMapOntoPlainConsoleSeverities(
        ChaosWatchLogLevel $level,
        ConsoleOutputLevel $expected,
    ): void {
        self::assertSame($expected, $level->toConsoleOutputLevel());
    }

    /**
     * @return iterable<string, array{ChaosWatchLogLevel, ConsoleOutputLevel}>
     */
    public static function plainSeverityCases(): iterable
    {
        yield 'failure prints as an error' => [ChaosWatchLogLevel::Failure, ConsoleOutputLevel::Error];
        yield 'warning prints as a warning' => [ChaosWatchLogLevel::Warning, ConsoleOutputLevel::Warning];
        yield 'event prints as info' => [ChaosWatchLogLevel::Event, ConsoleOutputLevel::Info];
        yield 'plan prints as info' => [ChaosWatchLogLevel::Plan, ConsoleOutputLevel::Info];
        yield 'done prints as info' => [ChaosWatchLogLevel::Done, ConsoleOutputLevel::Info];
        yield 'progress prints as info' => [ChaosWatchLogLevel::Progress, ConsoleOutputLevel::Info];
        yield 'waiting prints as info' => [ChaosWatchLogLevel::Waiting, ConsoleOutputLevel::Info];
        yield 'info prints as info' => [ChaosWatchLogLevel::Info, ConsoleOutputLevel::Info];
    }

    #[DataProvider('consoleOutputLevelCases')]
    public function testConsoleOutputLevelsMapOntoWatchLevels(ConsoleOutputLevel $level, ChaosWatchLogLevel $expected): void
    {
        self::assertSame($expected, ChaosWatchLogLevel::fromConsoleOutputLevel($level));
    }

    /**
     * @return iterable<string, array{ConsoleOutputLevel, ChaosWatchLogLevel}>
     */
    public static function consoleOutputLevelCases(): iterable
    {
        yield 'step' => [ConsoleOutputLevel::Step, ChaosWatchLogLevel::Progress];
        yield 'progress' => [ConsoleOutputLevel::Progress, ChaosWatchLogLevel::Progress];
        yield 'info' => [ConsoleOutputLevel::Info, ChaosWatchLogLevel::Info];
        yield 'detail' => [ConsoleOutputLevel::Detail, ChaosWatchLogLevel::Info];
        yield 'success' => [ConsoleOutputLevel::Success, ChaosWatchLogLevel::Done];
        yield 'warning' => [ConsoleOutputLevel::Warning, ChaosWatchLogLevel::Warning];
        yield 'error' => [ConsoleOutputLevel::Error, ChaosWatchLogLevel::Failure];
    }
}
